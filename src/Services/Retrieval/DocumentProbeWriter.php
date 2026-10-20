<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Support\GuardedPipelineObserver;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\TokenUsage;
use Illuminate\Support\Facades\Log;

/**
 * DocumentProbeWriter
 *
 * ONE small, focused LLM call that rewrites the turn's question into document
 * search probes: for each corpus language, up to MAX_PROBES_PER_LANGUAGE short
 * phrasings in the wording the source documents themselves use. The file-search
 * fan-out adds each probe as a search angle, so a question asked in another
 * language, or in everyday words, still lands on the passage that answers it.
 *
 * The probes ADD breadth to the search; they do not outvote it (eval p16). The
 * n-th probe of every language is one SLOT — the same line of attack, once per
 * language — and AiManager counts a slot as one fusion vote, does not search a
 * slot that nearly duplicates an earlier one or a rendering that nearly
 * duplicates the question (similarity of the angle embeddings), and makes no
 * probe call at all for an ask the application itself answers (QU's
 * navigation_target). This class only removes exact copies (parse()).
 *
 * Why its own call (eval p15, 2026-10-06): the same job used to ride the
 * Query-Understanding call as two extra output keys. Inside that ~20-key prompt
 * the small model wrote them badly (same-language rendering copied or missing,
 * everyday words, wrong language) and their instructions cost ~530 prompt tokens
 * on EVERY turn, data turns included. Here the model has one job, and the call
 * is made only when document search actually runs (AiManager decides).
 *
 * Inputs are host configuration only (white-label rule): the corpus languages
 * (ai.file_context.corpus_languages) and the corpus register text
 * (ai.file_context.corpus_register). The prompt below describes the task
 * structurally and carries no domain vocabulary of its own.
 *
 * Never throws: any failure (provider error, timeout, invalid JSON, unusable
 * shape) yields no probes — the turn continues on its other search angles.
 */
final class DocumentProbeWriter
{
    /** Observer stage of the call's prompt, usage and recovered errors. */
    public const STAGE = 'search_probes';

    /** Each probe is one more search angle: two distinct lines of attack per language. */
    public const MAX_PROBES_PER_LANGUAGE = 2;

    /** Output budget: a fixed part (JSON frame + the "terms" scratch field) plus a share per requested probe. */
    private const BASE_OUTPUT_TOKENS = 120;
    private const OUTPUT_TOKENS_PER_PROBE = 60;

    /**
     * @param array{corpus_languages?: mixed, corpus_register?: mixed} $config
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly array $config = []
    ) {
    }

    /**
     * Whether the host described its corpus at all (languages and/or register).
     * When it did not, there is nothing to write probes with: no call is made.
     */
    public function isConfigured(): bool
    {
        return $this->corpusLanguages() !== [] || $this->corpusRegister() !== '';
    }

    /**
     * Write the probes for one question.
     *
     * @param string $question           The user's question, verbatim.
     * @param string $standaloneQuestion The context-resolved form of the question (QU's
     *        rewrite), so a follow-up that only makes sense inside its conversation is
     *        still probed for what it is about. '' when there is none.
     * @param string $questionLanguage   ISO 639-1 code of the question's language ('' unknown).
     * @return array<string, list<string>> language code => probes (at most
     *         MAX_PROBES_PER_LANGUAGE each). Empty when unconfigured or on any failure.
     */
    public function write(
        string $question,
        string $standaloneQuestion = '',
        string $questionLanguage = '',
        ?PipelineObserverInterface $observer = null
    ): array {
        $question = trim($question);
        $standaloneQuestion = trim($standaloneQuestion);
        $questionLanguage = strtolower(trim($questionLanguage));

        if ($question === '' || !$this->isConfigured()) {
            return [];
        }

        $languages = $this->targetLanguages($questionLanguage);
        $observer = $observer !== null ? GuardedPipelineObserver::wrap($observer) : PipelineObserverScope::current();

        try {
            $messages = $this->buildMessages($question, $standaloneQuestion, $questionLanguage, $languages);
            $observer->promptBuilt(self::STAGE, $messages);

            try {
                $response = $this->llm->chatJson($messages, [
                    'temperature' => 0.0,
                    'max_tokens' => self::BASE_OUTPUT_TOKENS
                        + self::OUTPUT_TOKENS_PER_PROBE * self::MAX_PROBES_PER_LANGUAGE * count($languages),
                ]);
            } finally {
                // A failed or truncated reply still consumed tokens.
                TokenUsage::report($observer, self::STAGE, $this->llm);
            }

            return $this->parse($response, $languages, [$question, $standaloneQuestion]);
        } catch (\Throwable $e) {
            // Probes are an enhancement: without them the search runs on its other angles.
            try {
                Log::warning('Document search probes unavailable: ' . $e->getMessage());
            } catch (\Throwable) {
                // Logging is best effort.
            }
            $observer->stageError(self::STAGE, $e);

            return [];
        }
    }

    /**
     * The languages probes are written in: the configured corpus languages, else
     * (a register without languages) the question's own language.
     *
     * @return list<string>
     */
    private function targetLanguages(string $questionLanguage): array
    {
        $languages = $this->corpusLanguages();
        if ($languages !== []) {
            return $languages;
        }

        // ISO 639 "undetermined": the model writes in the question's language.
        return [$questionLanguage !== '' ? $questionLanguage : 'und'];
    }

    /**
     * The reply keys of one language's probes: "<code>_1", "<code>_2".
     *
     * One flat key per probe, each spelled out in the reply form AND in the
     * request (measured live 2026-10-06 on the small model): asked for "a list per
     * listed language" it answered in the question's language only (5 of 10
     * replies), and a nested {"probes": {...}} form came back mis-nested.
     *
     * @return list<string>
     */
    private function probeKeys(string $language): array
    {
        $keys = [];
        for ($n = 1; $n <= self::MAX_PROBES_PER_LANGUAGE; $n++) {
            $keys[] = "{$language}_{$n}";
        }

        return $keys;
    }

    /**
     * @param list<string> $languages
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $question, string $standalone, string $questionLanguage, array $languages): array
    {
        $codes = json_encode($languages, JSON_UNESCAPED_UNICODE);
        $register = $this->corpusRegister();

        $languageLine = $this->corpusLanguages() !== []
            ? "The source documents are written in these languages: {$codes}."
            : "The source documents are written in the language of the question (code {$codes}).";

        // The register is the ONLY domain input, and it is the host's text. It tells
        // the model how the documents name things — it is not a list of subjects to
        // search for (live 2026-10-06: probes drifted to whichever term the register
        // listed, whatever the question asked).
        $registerBlock = $register !== ''
            ? "\n\nTHE SOURCE DOCUMENTS' REGISTER (how they are worded, with a sample of their own terms):\n{$register}\n"
                . 'Use everything you know about how documents of this register are drafted; the sample is not '
                . 'exhaustive. It is a guide to WORDING, not a list of subjects: most of its terms have nothing to do '
                . 'with any one question. Use a term only where it is that register\'s name for something the question '
                . 'itself mentions.'
            : '';

        $keys = [];
        $form = ['terms' => ['…' => '…']];
        foreach ($languages as $language) {
            foreach ($this->probeKeys($language) as $key) {
                $keys[] = $key;
                $form[$key] = '…';
            }
        }
        $form = json_encode($form, JSON_UNESCAPED_UNICODE);

        // Tuned live against real retrieval (2026-10-06, the host's own corpus and
        // register). What the structure is for:
        //  - "terms" first: a scratch step. Without it the probes kept the user's
        //    everyday words; with it they use the documents' terms.
        //  - probe 1 states the rule (the passage that answers reads like that);
        //    probe 2 is about the thing itself — a second, distinct line of attack.
        $system = 'You write search probes for a document search engine. A probe is matched by meaning against the '
            . 'passages of the source documents. The best probe is a sentence that could have been copied from the '
            . 'passage that answers the question: the same formal wording, the same drafting style. It is never a '
            . "question, a heading or a description of a rule.\n\n"
            . $languageLine
            . $registerBlock
            . "\n\nFill the reply in this order:\n"
            . '- "terms": for each everyday expression of the question that such documents word differently, the '
            . "documents' own term for it (2 to 5 short pairs: everyday expression => documents' term).\n"
            . '- then, for EACH listed language, ' . self::MAX_PROBES_PER_LANGUAGE . ' probes written entirely in that '
            . "language, using those terms the way documents written in that language word them:\n"
            . '  "<code>_1": the rule itself, as the documents state it — who it applies to, what they may do, must '
            . "do or are owed, and the thing concerned;\n"
            . '  "<code>_2": a different line of attack on the SAME question — what the documents say of the specific '
            . "thing it is about: what that thing is, whose it is, who answers for it.\n\n"
            . "Rules:\n"
            . '- Both probes answer THIS question. Keep the specific thing it is about: do not widen it to its general '
            . "topic and do not drift to neighbouring subjects.\n"
            . '- A probe states what the rule SAYS. It never says where the rule comes from or who sets it: no '
            . '"according to…", "subject to the rules of…", "as provided by…", "established by…", and never name a '
            . "law, code, regulation, document or title.\n"
            . '- In the question\'s own language the probe is still a rewrite into the documents\' wording, never a '
            . "copy or a light paraphrase of the question.\n"
            . "- One language per probe, under 30 words, a statement (no question mark).\n\n"
            . "Return ONLY JSON of this exact form, every key filled: {$form}";

        $user = 'Question' . ($questionLanguage !== '' ? " (written in \"{$questionLanguage}\")" : '') . ": {$question}";
        if ($standalone !== '' && $this->comparable($standalone) !== $this->comparable($question)) {
            $user .= "\nThe same question, resolved against its conversation: {$standalone}";
        }
        $user .= "\nWrite: terms, " . implode(', ', $keys) . '.';

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Keep, per requested language, the distinct non-blank probes of its reply
     * keys that are not a copy of the question (at most MAX_PROBES_PER_LANGUAGE).
     * A reply that groups a language's probes under its bare code ("fr": [..]) is
     * understood too. Everything else the reply holds — the "terms" scratch field,
     * other languages, other shapes — is ignored.
     *
     * @param list<string> $languages
     * @param list<string> $askedForms The question and its standalone form.
     * @return array<string, list<string>>
     */
    private function parse(object|array $response, array $languages, array $askedForms): array
    {
        $data = is_object($response) ? (array) $response : $response;

        $copies = [];
        foreach ($askedForms as $form) {
            $key = $this->comparable($form);
            if ($key !== '') {
                $copies[$key] = true;
            }
        }

        $probes = [];
        foreach ($languages as $language) {
            $values = [];
            foreach ($this->probeKeys($language) as $key) {
                $values[] = $data[$key] ?? null;
            }
            $grouped = $data[$language] ?? null;
            if (is_string($grouped)) {
                $values[] = $grouped;
            } elseif (is_array($grouped)) {
                array_push($values, ...array_values($grouped));
            }

            $kept = [];
            foreach ($values as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $probe = trim((string) preg_replace('/\s+/u', ' ', $value));
                $key = $this->comparable($probe);
                if ($key === '' || isset($copies[$key]) || isset($kept[$key])) {
                    continue;
                }
                $kept[$key] = $probe;
                if (count($kept) >= self::MAX_PROBES_PER_LANGUAGE) {
                    break;
                }
            }

            if ($kept !== []) {
                $probes[$language] = array_values($kept);
            }
        }

        return $probes;
    }

    /**
     * A text reduced to its letters and digits (case-folded, single-spaced), so a
     * probe that only re-punctuates or re-cases the question compares equal to it.
     */
    private function comparable(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim($text);
    }

    /**
     * @return list<string> Normalized, de-duplicated, non-empty language codes.
     */
    private function corpusLanguages(): array
    {
        $languages = $this->config['corpus_languages'] ?? [];
        if (!is_array($languages)) {
            return [];
        }

        $normalized = [];
        foreach ($languages as $code) {
            if (is_string($code) && trim($code) !== '') {
                $normalized[] = trim($code);
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * The host's plain-text description of the wording its documents use ('' = none).
     */
    private function corpusRegister(): string
    {
        $register = $this->config['corpus_register'] ?? '';

        return is_string($register) ? trim($register) : '';
    }
}
