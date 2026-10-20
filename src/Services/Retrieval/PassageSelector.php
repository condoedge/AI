<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Contracts\ReportsTokenUsageInterface;
use Condoedge\Ai\Support\GuardedPipelineObserver;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\TokenUsage;
use Condoedge\Ai\Support\UntrustedLabel;
use Illuminate\Support\Facades\Log;

/**
 * PassageSelector
 *
 * ONE small, focused LLM call made after the document search has chosen its
 * passages: it reads the question, those passages and a short pool of candidates
 * the search ranked too low to select, and reports which candidates a correct
 * answer still needs. The caller (AiManager) decides what to do with the report.
 *
 * Why (eval p16–p21, "can I tear down this wall?"): the article stating the general
 * rule the question falls under never came within reach of the search — cosine 0.28
 * for the question itself, rank 7–75 for every rewrite and probe of it. That is an
 * inference gap, not a vocabulary one: re-embedding the corpus with generated
 * questions moved it from rank 26 to 16, and a probe stating the general rule got
 * it selected about one time in four. A model that READS the question against the
 * candidates named it in 12 of 14 stored turns.
 *
 * Why it only ADDS (measured before building): asked to choose the whole passage
 * set instead, the same model took passages of the wrong part of the document in
 * 4 of 8 runs on a broad question, never chose a passage the grading standard asks
 * for, and lost the needed article of another question in 1 run of 2. What the
 * search selects therefore stays; this call can only name what is missing.
 *
 * The prompt describes the task structurally. Its only domain inputs are the
 * host's own texts (white-label rule): the application description and the corpus
 * register. Candidates are shown under opaque ids — the model answered with the
 * numbers it read INSIDE the texts when the ids were numeric.
 *
 * Never throws: any failure (provider error, timeout, invalid JSON, a reply
 * without the list) returns null and the turn keeps the passages it had.
 */
final class PassageSelector
{
    /** Observer stage of the call's prompt, usage and recovered errors. */
    public const STAGE = 'passage_selection';

    /** Characters of a candidate's text the model reads (config excerpt_chars). */
    public const DEFAULT_EXCERPT_CHARS = 350;

    /** Characters of EACH chunk of an already selected passage the model reads (config selected_chars). */
    public const DEFAULT_SELECTED_CHARS = 110;

    /** Output budget: a JSON frame and a handful of two-letter ids. */
    private const MAX_OUTPUT_TOKENS = 60;

    /**
     * @param array{application?: mixed, corpus_register?: mixed, excerpt_chars?: mixed, selected_chars?: mixed} $config
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly array $config = []
    ) {
    }

    /**
     * Which candidates a correct answer still needs.
     *
     * @param string $question   The user's question, verbatim.
     * @param string $standalone Its conversation-resolved form ('' when none).
     * @param list<array{heading?: string, parts?: list<string>, text?: string}> $selected The passages the
     *        search selected: their heading and the chunks they are made of (`parts`; or one `text`).
     * @param list<array{source?: string, heading?: string, text?: string}> $candidates The pool, in the
     *        order it is shown; the reply names positions of this list.
     * @param int $limit At most this many candidates are returned.
     * @return array{picked: list<int>, unknown: int, usage: array<string, mixed>|null}|null
     *         picked: positions in $candidates, in the order reported, distinct, at most
     *         $limit ([] = the model reported that nothing is missing). unknown: reported
     *         ids that name no candidate. null: no usable report (nothing to ask, or the
     *         call failed).
     */
    public function select(
        string $question,
        string $standalone,
        array $selected,
        array $candidates,
        int $limit,
        ?PipelineObserverInterface $observer = null
    ): ?array {
        $question = trim($question);
        $candidates = array_values($candidates);
        if ($question === '' || $candidates === [] || $limit < 1) {
            return null;
        }

        $observer = $observer !== null ? GuardedPipelineObserver::wrap($observer) : PipelineObserverScope::current();

        try {
            $ids = $this->opaqueIds(count($candidates));
            $messages = $this->buildMessages($question, trim($standalone), $selected, $candidates, $ids, $limit);
            $observer->promptBuilt(self::STAGE, $messages);

            try {
                $response = $this->llm->chatJson($messages, [
                    'temperature' => 0.0,
                    'max_tokens' => self::MAX_OUTPUT_TOKENS,
                ]);
            } finally {
                // A failed or truncated reply still consumed tokens.
                TokenUsage::report($observer, self::STAGE, $this->llm);
            }

            $data = is_object($response) ? (array) $response : $response;
            $reported = $data['needed'] ?? null;
            if (!is_array($reported)) {
                return null; // no list: a failure, never "nothing is missing"
            }

            $position = array_flip($ids);
            $picked = [];
            $unknown = 0;
            foreach ($reported as $id) {
                if (!is_string($id) || !isset($position[$id])) {
                    $unknown++;
                    continue;
                }
                if (!in_array($position[$id], $picked, true) && count($picked) < $limit) {
                    $picked[] = $position[$id];
                }
            }

            return ['picked' => $picked, 'unknown' => $unknown, 'usage' => $this->lastUsage()];
        } catch (\Throwable $e) {
            try {
                Log::warning('Passage selection unavailable: ' . $e->getMessage());
            } catch (\Throwable) {
                // Logging is best effort.
            }
            $observer->stageError(self::STAGE, $e);

            return null;
        }
    }

    /**
     * Two capital letters per candidate: AA, AB, … (676 of them — far above any pool).
     *
     * @return list<string>
     */
    private function opaqueIds(int $count): array
    {
        $ids = [];
        for ($n = 0; $n < $count; $n++) {
            $ids[] = chr(65 + intdiv($n, 26) % 26) . chr(65 + $n % 26);
        }

        return $ids;
    }

    /**
     * @param list<array{heading?: string, text?: string}> $selected
     * @param list<array{source?: string, heading?: string, text?: string}> $candidates
     * @param list<string> $ids
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $question, string $standalone, array $selected, array $candidates, array $ids, int $limit): array
    {
        $application = $this->text('application');
        $register = $this->text('corpus_register');

        // Tuned live against real retrieval (2026-10-07, the host's own corpus):
        //  - the model judges what is MISSING from a given selection. Asked to pick
        //    the whole set it padded the list and took passages of the wrong part of
        //    the document;
        //  - "often nothing is missing" keeps it from adding on turns the search
        //    already covered (none added on 5 of 8 such stored turns);
        //  - the ids rule: without it the reply carried numbers read inside the texts.
        $system = 'A search engine has chosen passages of the source documents for answering a user\'s question; they are '
            . 'listed under "Selected". You check whether a correct and complete answer needs anything MORE from the '
            . 'candidates listed after them, which the search ranked too low to select.'
            . ($application !== '' ? "\n\nAbout the application the user is asking in: {$application}" : '')
            . ($register !== '' ? ($application !== '' ? "\n" : "\n\n") . "About the source documents: {$register}" : '')
            . "\n\nPick a candidate only if the answer would be wrong or incomplete without it and the selected passages do "
            . 'not already say it: typically the general rule or right the question falls under (what the person concerned '
            . 'may do or must do by default), a definition the selected passages rely on, or an approval, a condition or a '
            . 'consequence they leave out. Most candidates are near misses that only share words with the question, and '
            . "often nothing is missing.\n\n"
            . 'Every candidate starts with its id: two capital letters in square brackets. Answer with those ids only — '
            . "never a number or a title found inside a text.\n"
            . "Return at most {$limit} ids, the most necessary first; an empty list when nothing is missing.\n"
            . 'Return ONLY JSON: {"needed": ["<id>", …]}';

        $user = "Question: {$question}";
        if ($standalone !== '' && $standalone !== $question) {
            $user .= "\nThe same question, resolved against its conversation: {$standalone}";
        }

        $selectedChars = $this->length('selected_chars', self::DEFAULT_SELECTED_CHARS);
        $user .= "\n\nSelected:\n";
        if ($selected === []) {
            $user .= "(none)\n";
        }
        foreach ($selected as $passage) {
            // Headings and file names are document content printed as labels: through
            // the shared sanitiser (they cannot forge a candidate id or a new line).
            $heading = UntrustedLabel::heading((string) ($passage['heading'] ?? ''));
            // The start of EVERY chunk of the passage: shown only its first lines, the
            // model took the rest of a selected passage for missing and always added.
            $parts = is_array($passage['parts'] ?? null) ? $passage['parts'] : [(string) ($passage['text'] ?? '')];
            $shown = [];
            foreach ($parts as $part) {
                $part = $this->oneLine((string) $part);
                if ($part !== '') {
                    $shown[] = $this->excerpt($part, $selectedChars);
                }
            }
            $user .= '- ' . ($heading !== '' ? $heading . ': ' : '') . implode(' ', $shown) . "\n";
        }

        $excerptChars = $this->length('excerpt_chars', self::DEFAULT_EXCERPT_CHARS);
        $user .= "\nCandidates:\n";
        foreach ($candidates as $n => $candidate) {
            $label = implode(' — ', array_filter([
                UntrustedLabel::fileName((string) ($candidate['source'] ?? '')),
                UntrustedLabel::heading((string) ($candidate['heading'] ?? '')),
            ], static fn (string $part): bool => $part !== ''));
            $user .= "[{$ids[$n]}]" . ($label !== '' ? ' ' . $label : '') . "\n"
                . $this->excerpt(trim((string) ($candidate['text'] ?? '')), $excerptChars) . "\n\n";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => rtrim($user)],
        ];
    }

    private function excerpt(string $text, int $chars): string
    {
        return mb_strlen($text, 'UTF-8') > $chars ? rtrim(mb_substr($text, 0, $chars, 'UTF-8')) . ' …' : $text;
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function text(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function length(string $key, int $default): int
    {
        $value = $this->config[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /** @return array<string, mixed>|null */
    private function lastUsage(): ?array
    {
        try {
            return $this->llm instanceof ReportsTokenUsageInterface ? $this->llm->lastUsage() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
