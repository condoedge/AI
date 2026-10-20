<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Response\DataAnswerPlan;
use Condoedge\Ai\Services\Response\PiiMasker;
use Condoedge\Ai\Services\Response\TableRenderer;

/**
 * ResultsDataSection
 *
 * Shows the query results data — since Phase 8 batch A as the DataAnswerPlan's
 * COMPACT, PII-masked pipe-table summary (first ≤ maxItems rows) with facts,
 * query/system-computed totals, homonym notes and, on the deterministic-table
 * path, the {{table}} placeholder instruction (the table itself is rendered by
 * code after generation). Never the former pretty-printed JSON sample.
 * Priority: 50
 */
class ResultsDataSection extends BaseResponseSection
{
    protected string $name = 'data';
    protected int $priority = 50;

    private int $maxItems = 10;

    /**
     * Set maximum items to show
     */
    public function setMaxItems(int $max): self
    {
        $this->maxItems = $max;
        return $this;
    }

    /**
     * How many rows this section renders at most — read by the ResponseGenerator's
     * truncation-honesty guard so trigger and prompt can never disagree.
     */
    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $data = $context->executionResult->data;

        if (empty($data)) {
            // The data part could not be retrieved (execution error or a scoping
            // safe-stop): that is NOT an empty result. Rendering "No data returned"
            // here would invite a false "you have no ..." claim.
            $unavailable = !empty($context->executionResult->errors)
                || ($context->executionResult->metadata['safe_stop'] ?? false) === true;
            if ($unavailable) {
                return "Results: NOT AVAILABLE — the data part of this question could not be retrieved. "
                    . "Do not say anything about the data; do not claim there is no data. "
                    . "Answer only what the other context supports.\n\n";
            }

            return "Results: No data returned\n\n";
        }

        // Phase 8 batch A: the model gets a COMPACT, PII-masked pipe-table summary
        // plus facts / system-computed totals (DataAnswerPlan) — never the former
        // pretty-printed JSON sample, and never a real identifying-field value.
        $plan = DataAnswerPlan::for($context);
        $tableMode = DataAnswerPlan::tableModeFor($options) && $plan->hasTable();
        $displayCount = min(count($data), $this->maxItems);

        if ($plan->mode() === DataAnswerPlan::MODE_NONE) {
            // Nothing displayable survived redaction (e.g. id-only rows): masked JSON.
            $masked = PiiMasker::for($context)->maskRows(array_slice($data, 0, $this->maxItems));
            $block = (string) json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            // B5: rows numbered for {{record:N}} only when code attached their records.
            $block = $plan->promptBlock($tableMode, $this->maxItems, $context->attachedActions?->hasRecordRefs() ?? false);
        }

        $output = "Results:\n"
            . $this->spotlight($block, 'retrieved_document', ['id' => 'results'])
            . "\n\n"
            . $this->dateTenseBlock($context, array_slice($data, 0, $this->maxItems), count($data) === 1);

        // The disclosed total prefers the companion count's TRUE total (stamped by
        // AiManager when execution hit the row cap) over the retrieved row count,
        // so a cap-hit turn shows "first 10 of 250", never a false "of 100".
        $trueTotal = $context->executionResult->metadata['true_total'] ?? null;
        $total = is_numeric($trueTotal) && (int) $trueTotal >= count($data) ? (int) $trueTotal : count($data);

        if ($tableMode && !$plan->isRecord()) {
            // DATA STATS for the table path: the user sees the system-rendered
            // table (and its "Showing X of Y" line), not the 10-row summary.
            // (ONE record has no table to count: it falls to "Rows returned: 1".)
            $output .= "(DATA STATS: {$plan->totalPhrase()} rows in total; the table the system shows the user holds "
                . "{$plan->shown()} of {$plan->totalPhrase()}.)\n\n";
        } elseif ($total > $displayCount && $plan->mode() !== DataAnswerPlan::MODE_SINGLE) {
            $remaining = $total - $displayCount;
            $output .= "(Showing first {$displayCount} of {$total} results, {$remaining} more not shown)\n\n";
        } else {
            // Folded from the former StatisticsSection (Task 5.8): the row count of a
            // complete result, so "how many" answers need not count the rows.
            $output .= "(Rows returned: {$total})\n\n";
        }

        // Execution time (also from StatisticsSection) is a debugging metric: only
        // shown when execution stats are explicitly NOT hidden (technical style).
        $hideStats = $options['hide_stats'] ?? config('ai.response_generation.hide_execution_stats', true);
        $executionMs = $context->executionResult->stats['execution_time_ms'] ?? null;
        if (!$hideStats && is_numeric($executionMs)) {
            $output .= "(Query execution time: {$executionMs}ms)\n\n";
        }

        // Fan-out disclosure: the rows are a join, so the ROW total above is not
        // the number of entities. The companion DISTINCT count of the anchor
        // (stamped by AiManager) is the figure for "how many <label>" wording.
        // Emitted ONLY on a real fan-out (anchor < rows): when they are equal the
        // line adds nothing and the model echoes it (live roster: "523 distinct
        // roles"). The noun is the entity's configured display name, else the raw
        // label, phrased neutrally as "<noun> records" (no pluralization rules).
        $anchorTotal = $context->executionResult->metadata['anchor_total'] ?? null;
        if (is_numeric($anchorTotal) && (int) $anchorTotal < $total) {
            $anchorTotal = (int) $anchorTotal;
            // Never the cypher variable name (anchor_variable) as a noun: an
            // unlabeled anchor reads "entity records" (Task 1.28 M3).
            $label = $context->executionResult->metadata['anchor_label'] ?? null;
            $noun = $this->displayName(is_string($label) ? $label : null);
            $output .= "(these {$total} rows cover {$anchorTotal} distinct {$noun} records — "
                . "when stating how many {$noun} records there are, use {$anchorTotal})\n\n";
        }

        return $output;
    }

    /**
     * Matches a date-shaped value: an ISO-8601 date with an optional time part
     * ('2023-10-24', '2023-10-24 16:00:00', '2021-08-01T04:00:00.000000Z').
     * Structural (the value's shape), never a column-name list.
     */
    private const DATE_VALUE_PATTERN = '/^(\d{4}-\d{2}-\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

    /**
     * TENSE IS COMPUTED, NOT GUESSED (eval p9-B digest t1, 2026-10-01): the
     * response prompt carried no notion of today, so "When was our last
     * meeting?" over FACTS "Date: 2023-10-24 16:00" was answered "is scheduled
     * for" — future tense on a past date. Code compares each date value's DAY
     * with today's (the same day boundary the query rules use:
     * toString(date())) and states the outcome beside the data:
     *  - single-row result (the FACTS case): one line per date-valued column,
     *    tagged PAST / TODAY / FUTURE;
     *  - multi-row result: today's date, so row dates can be told apart.
     * Values are read from the PII-MASKED rows (the same masker the summary
     * uses), so a sensitive date (a masked placeholder) is never restated.
     * Returns '' when no displayed value is date-shaped (zero tokens).
     *
     * @param array<int, mixed> $rows the displayed rows (real values)
     */
    private function dateTenseBlock(PipelineContext $context, array $rows, bool $singleRow): string
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        if ($rows === []) {
            return '';
        }

        $masked = PiiMasker::for($context)->maskRows($rows);
        $today = $this->today();
        $renderer = new TableRenderer();
        $plan = DataAnswerPlan::for($context);

        $tagged = [];
        $anyDate = false;
        foreach ($masked as $row) {
            foreach ($row as $column => $value) {
                $day = $this->dayOf($value);
                if ($day === null) {
                    continue;
                }
                $anyDate = true;
                if ($singleRow) {
                    $tense = match (true) {
                        $day < $today => 'PAST (before today) — describe it in the past tense, never as scheduled or upcoming',
                        $day > $today => 'FUTURE (after today) — describe it as upcoming',
                        default => 'TODAY',
                    };
                    // The label the FACTS / record block gives the column (DataAnswerPlan).
                    $tagged[] = '- ' . $plan->columnLabel((string) $column) . ': '
                        . $renderer->cell($value, true, 'en') . ' — ' . $tense;
                }
            }
        }

        if (!$anyDate) {
            return '';
        }

        $output = "DATES RELATIVE TO TODAY (computed by the system — use them for tense): Today is {$today}.";
        $output .= $tagged !== []
            ? "\n" . implode("\n", $tagged)
            : ' A date before today is in the past; a date after today is upcoming.';

        return $output . "\n\n";
    }

    /** The YYYY-MM-DD day of a date-shaped value, else null. */
    private function dayOf(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (!is_string($value) || preg_match(self::DATE_VALUE_PATTERN, trim($value), $m) !== 1) {
            return null;
        }

        // The day the user reads (app timezone) — the same day TableRenderer shows (E2).
        return TableRenderer::localDay(trim($value)) ?? $m[1];
    }

    /** Today's date (app timezone; Carbon test-now aware). */
    private function today(): string
    {
        try {
            if (function_exists('now')) {
                return now()->toDateString();
            }
        } catch (\Throwable $e) {
            // Fall through to native date.
        }

        return date('Y-m-d');
    }

    /**
     * The entity's human display name from the entity config (`metadata.display_name`
     * or top-level `display_name`, via the shared EntityLabelIndex), else the raw label.
     */
    private function displayName(?string $label): string
    {
        if ($label === null || trim($label) === '') {
            return 'entity';
        }

        $config = EntityLabelIndex::shared()->configForLabel($label) ?? [];
        $display = $config['metadata']['display_name'] ?? $config['display_name'] ?? null;

        return is_string($display) && trim($display) !== '' ? trim($display) : $label;
    }
}
