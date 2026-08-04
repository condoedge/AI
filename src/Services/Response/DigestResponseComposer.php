<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\Digest\DigestSourceResult;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Support\StatedNumber;

/**
 * Digest Response Composer
 *
 * Renders a completed digest run (list of DigestSourceResult) into the final
 * chat answer with exactly ONE LLM call — the digest turn's entire LLM budget
 * beyond QU (design §0.2). The prompt is assembled deterministically per
 * source; the honesty rubric is enforced structurally:
 *
 *  - honest-empty collapse: plain-empty sources become ONE combined line;
 *  - fallback rows render ONLY under their fallback_label, "none pending"
 *    stated first — never as the asked-for items;
 *  - failed sources reduce to one honest "could not be checked" line (raw
 *    error text never reaches the LLM or the user);
 *  - per-source truncation disclosure is BLOCKING: a missing true total is
 *    deterministically appended after the LLM returns (§4.2);
 *  - amendment B1: a combined digest+export turn acknowledges the export ask
 *    as the turn's SINGLE deepening offer and stamps
 *    metadata.response.digest.export_followup_offered;
 *  - amendment B2: metadata.response.digest.skipped_sources carries the keys
 *    capped out by max_sources.
 */
class DigestResponseComposer
{
    public function __construct(private readonly LlmProviderInterface $llm)
    {
    }

    /**
     * @param DigestSourceResult[] $sources        Registry-ordered run results.
     * @param string[]             $skippedSources Enabled keys dropped by max_sources (B2).
     * @param string|null          $exportFormat   The turn's QU export ask, when combined (B1).
     * @param array<string, mixed> $options
     * @return array<string, mixed> The standard response array.
     */
    public function compose(
        string $question,
        PipelineContext $ctx,
        array $sources,
        array $skippedSources = [],
        ?string $exportFormat = null,
        array $options = []
    ): array {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($ctx, $exportFormat)],
            ['role' => 'user', 'content' => $this->userPrompt($question, $sources, $exportFormat)],
        ];

        // The ONE composition call (LLM budget: QU + this = 2 calls per digest turn).
        $answer = trim($this->llm->chat($messages, ['temperature' => 0.4]));

        // §4.2 — deterministic truncation guard: disclosure is never left to
        // LLM goodwill. STANDALONE-number presence per capped source (via
        // StatedNumber — a digit run buried in a date/id/amount never counts,
        // and the tight "shown/total" fraction form does); any miss gets the
        // one-line footer appended, localized to the QUESTION language (the
        // same policy as every other canned line — the 2026-07-23 live-failure
        // class), never the app locale.
        $truncationAppended = [];
        $locale = $ctx->qu->language !== '' ? $ctx->qu->language : null;
        foreach ($sources as $source) {
            if ($source->error !== null || $source->trueTotal === null || $source->trueTotal <= $source->rowCount) {
                continue;
            }
            $stated = StatedNumber::appears($answer, $source->trueTotal)
                || StatedNumber::disclosesTruncation($answer, $source->trueTotal, $source->rowCount);
            if (!$stated) {
                $answer .= "\n\n" . trans('ai.digest.truncation_footer', [
                    'label' => $source->label,
                    'shown' => $source->rowCount,
                    'total' => $source->trueTotal,
                ], $locale);
                $truncationAppended[] = $source->key;
            }
        }

        $responseMetadata = ['digest' => $this->digestMetadata($sources, $skippedSources, $exportFormat)];
        if ($truncationAppended !== []) {
            $responseMetadata['digest_truncation_appended'] = $truncationAppended;
        }

        $data = $this->flattenRows($sources);

        return [
            'question' => $question,
            'answer' => $answer,
            'insights' => [],
            'visualizations' => [],
            'cypher' => null,
            'data' => $data,
            'stats' => [
                'result_count' => count($data),
                'digest_sources_run' => count($sources),
            ],
            'referenced_files' => [],
            'metadata' => [
                'query' => ['source' => 'attention_digest', 'intent' => 'digest_request'],
                'execution' => ['digest_sources_run' => count($sources)],
                'response' => $responseMetadata,
                'scope_clarified' => false,
                'security_filter_applied' => false,
                // Same write-back contract as buildFinalResponse: the distilled
                // subject titles a follow-up export of these rows.
                'subject' => $ctx->qu->searchQuery !== '' ? $ctx->qu->searchQuery : null,
            ],
        ];
    }

    private function systemPrompt(PipelineContext $ctx, ?string $exportFormat): string
    {
        $language = $ctx->qu->language !== ''
            ? "Answer ENTIRELY in the question's language: '{$ctx->qu->language}'."
            : 'Answer ENTIRELY in the language of the question.';

        $closing = $exportFormat === null
            ? '- End with exactly ONE deepening offer chosen from the rendered sections '
                . '(e.g. the full list behind the most pressing section) — one offer, never a menu.'
            : '- EXPORT FOLLOW-UP (required): the user ALSO asked to receive this as a '
                . strtoupper($exportFormat) . ' file. Acknowledge that ask explicitly: state that the file '
                . 'with everything shown here is available as an immediate follow-up — they can simply '
                . "reply \"export that\" to receive it. Make this acknowledgment the answer's single "
                . 'closing offer; do NOT add any other deepening offer.';

        return <<<PROMPT
You render a workspace ATTENTION DIGEST from pre-executed data blocks. You never run queries and never add information of your own.

Rules:
- {$language}
- Render the sections IN THE ORDER GIVEN, using each block's heading as the section heading.
- NEVER invent rows, totals, dates, names or urgency not present in the blocks. Do not editorialize beyond what a block states.
- Sources marked EMPTY (and without their own empty note) must be COLLAPSED into one single combined line near the end, e.g. "Nothing pending in: X, Y." — never one section each.
- Sources with their own empty note render that note as their section body.
- FALLBACK blocks: these rows are NOT the asked-for items. State the honest "none pending/scheduled" fact FIRST, then present the rows as recent history under the block's own fallback heading.
- ERROR blocks: render exactly one line saying that source could not be checked right now. Nothing else.
- When a block says "showing X of Y", the answer MUST state that source's true total Y as a literal number. When a block says the total is unknown ("more may exist"), say so.
- Use the provided markdown links exactly as given (section links and per-row entity actions when present). Do not fabricate links.
- Direct and concise: real rows first, no padding.
{$closing}
PROMPT;
    }

    /**
     * @param DigestSourceResult[] $sources
     */
    private function userPrompt(string $question, array $sources, ?string $exportFormat): string
    {
        $blocks = ["QUESTION: {$question}"];

        foreach ($sources as $source) {
            $blocks[] = $this->sourceBlock($source);
        }

        if ($exportFormat !== null) {
            $blocks[] = 'NOTE: the user also asked for a ' . strtoupper($exportFormat)
                . ' file of this. Close with the follow-up availability ("export that") as the single offer.';
        }

        return implode("\n\n", $blocks);
    }

    private function sourceBlock(DigestSourceResult $source): string
    {
        $heading = $source->usedFallback && $source->fallbackLabel !== null
            ? $source->fallbackLabel
            : $source->label;

        $lines = ["=== SECTION: {$heading} ==="];

        if ($source->description !== '') {
            $lines[] = "Meaning: {$source->description}";
        }
        if ($source->actionKey !== null) {
            $lines[] = "Section link: [{$source->label}](action://{$source->actionKey})";
        }

        // PRIMARY failure (§3 step 4): one honest line; raw error text stays in
        // the logs/trace, never in the prompt.
        if ($source->error !== null) {
            $lines[] = 'STATUS: ERROR — this source could not be checked right now. '
                . 'Render exactly one honest line saying so.';

            return implode("\n", $lines);
        }

        if ($source->usedFallback) {
            $lines[] = 'STATUS: PRIMARY EMPTY — nothing pending/scheduled. The rows below are FALLBACK '
                . 'context: recent history, NOT the asked-for items. State the honest "none pending" '
                . 'fact first, then present them under this section\'s fallback heading.';
            $lines[] = 'Rows (JSON): ' . $this->rowsJson($source->rows);

            return implode("\n", $lines);
        }

        if ($source->rowCount === 0) {
            if ($source->emptyText !== null) {
                $lines[] = 'STATUS: EMPTY — render this configured note as the section body: '
                    . '"' . $source->emptyText . '"';
            } else {
                $lines[] = 'STATUS: EMPTY — nothing pending. Collapse this source into the combined '
                    . '"nothing pending in: ..." line.';
            }
            if ($source->fallbackError !== null) {
                // FALLBACK failure (§3 step 4): honest-empty + a small note.
                $lines[] = 'NOTE: recent-history context could not be loaded for this source — '
                    . 'mention that briefly alongside the honest empty.';
            }

            return implode("\n", $lines);
        }

        if ($source->trueTotal !== null && $source->trueTotal > $source->rowCount) {
            $lines[] = "Showing {$source->rowCount} of {$source->trueTotal} (state the true total {$source->trueTotal}).";
        } elseif ($source->capHit) {
            $lines[] = "Showing the first {$source->rowCount} — the total is unknown; more may exist (say so).";
        } else {
            $lines[] = "Showing all {$source->rowCount}.";
        }

        $lines[] = 'Rows (JSON): ' . $this->rowsJson($source->rows);

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function rowsJson(array $rows): string
    {
        return (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param DigestSourceResult[] $sources
     * @param string[]             $skippedSources
     * @return array<string, mixed>
     */
    private function digestMetadata(array $sources, array $skippedSources, ?string $exportFormat): array
    {
        $perSource = [];
        $empty = [];
        $failed = [];

        foreach ($sources as $source) {
            $entry = [
                'key' => $source->key,
                'row_count' => $source->rowCount,
                'true_total' => $source->trueTotal,
                'used_fallback' => $source->usedFallback,
                'error' => $source->error,
            ];
            if ($source->fallbackError !== null) {
                $entry['fallback_error'] = $source->fallbackError;
            }
            $perSource[] = $entry;

            if ($source->error !== null) {
                $failed[] = $source->key;
            } elseif ($source->countsAsEmpty()) {
                $empty[] = $source->key;
            }
        }

        $digest = [
            'sources' => $perSource,
            'empty_sources' => $empty,
            'failed_sources' => $failed,
            'skipped_sources' => array_values($skippedSources),
        ];

        if ($exportFormat !== null) {
            $digest['export_followup_offered'] = $exportFormat;
        }

        return $digest;
    }

    /**
     * Flat rows for the conversation write-back. recordResponse persists a
     * digest turn's rows IN FULL (metadata.response.digest marks them as an
     * already display-capped set) — that is what makes the B1 follow-up
     * "export that" able to carry everything shown. Each row is stamped with
     * its source key so the export keeps the attribution.
     *
     * @param DigestSourceResult[] $sources
     * @return array<int, array<string, mixed>>
     */
    private function flattenRows(array $sources): array
    {
        $data = [];
        foreach ($sources as $source) {
            foreach ($source->rows as $row) {
                if (is_array($row)) {
                    $data[] = $row + ['attention_source' => $source->key];
                }
            }
        }

        return $data;
    }
}
