<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Retrieval\CalculationMatcher;
use Condoedge\Ai\Services\Retrieval\EntitySelectionPruner;

/**
 * CalculationsSection
 *
 * Teaches the query generator the host's CANONICAL domain calculations — named metrics
 * with their exact aggregation formula (e.g. "total owed to a team" = SUM of
 * Invoice.calc_due_amount over invoices with a positive balance) — so money/metric
 * questions reuse the business-approved formula instead of inventing an aggregation.
 *
 * Config (host-defined, per software):
 *
 *   'calculations' => [
 *       'team_total_owed' => [
 *           'label'       => 'Total owed to the team (receivables outstanding)',
 *           'description' => 'What customers still owe.',
 *           'formula'     => 'SUM of Invoice.calc_due_amount where calc_due_amount > 0',
 *           'cypher'      => 'MATCH (i:Invoice) WHERE i.calc_due_amount > 0 RETURN sum(i.calc_due_amount) AS total_owed',
 *           'notes'       => 'Split overdue vs not-yet-due on i.due_at < date().',
 *       ],
 *   ],
 *
 * Every key is optional except one of formula/cypher. Priority 74: with the query rules
 * (QueryRules 75 / TenantScopingRules 76), just before the question.
 *
 * Relevance (Task 5.11): when the retriever's {@see CalculationMatcher} judged which
 * metric(s) the question asks for (embedding similarity), exactly those render — none
 * when it asks for none. Without a verdict (matching off / embedding failed) only the
 * calculations over the question's PRIMARY labels (the projected
 * schema minus the pruner's join/scope-only tiers) render. A calculation's labels are
 * its explicit 'entities' list, else the node labels its cypher MATCHes
 * ((i:Invoice) → Invoice). In that fallback a calculation naming no label is kept (it
 * cannot be judged off-topic); without a projected schema every calculation renders.
 */
class CalculationsSection extends BasePromptSection
{
    protected string $name = 'calculations';
    protected int $priority = 74;

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $calculations = $this->relevant($context);

        if (empty($calculations)) {
            return '';
        }

        $output = $this->header('DOMAIN CALCULATIONS (canonical formulas)');
        $output .= "When the question asks for one of these metrics, use its canonical formula EXACTLY — do not invent a different aggregation.\n";
        $output .= "NEVER return the bare aggregate alone: keep the aggregation itself unchanged, and ALSO RETURN identifying/verification columns alongside it — the count of rows aggregated and the ids/names of the entities involved (e.g. count(x) AS items_counted, plus the id/name of the entity the metric is about) — so the result is verifiable, especially when the aggregate is 0 or empty.\n";
        $output .= "ADD columns; never change the aggregation itself.\n\n";

        foreach ($calculations as $key => $calc) {
            if (!is_array($calc)) {
                continue;
            }

            $label = (string) ($calc['label'] ?? (is_string($key) ? $key : 'calculation'));
            $output .= "• {$label}";
            if (!empty($calc['description'])) {
                $output .= " — {$calc['description']}";
            }
            $output .= "\n";

            if (!empty($calc['formula'])) {
                $output .= "  Formula: {$calc['formula']}\n";
            }
            if (!empty($calc['cypher'])) {
                $output .= "  Cypher: {$calc['cypher']}\n";
            }
            if (!empty($calc['notes'])) {
                $output .= "  Notes: {$calc['notes']}\n";
            }
            $output .= "\n";
        }

        return $output;
    }

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        return !empty($this->relevant($context));
    }

    /**
     * The configured calculations whose labels intersect the projected schema.
     *
     * @return array<int|string, mixed>
     */
    private function relevant(PipelineContext $context): array
    {
        $calculations = $this->configured();
        if ($calculations === []) {
            return [];
        }

        // 1. The retriever's CalculationMatcher verdict (embedding similarity): exactly
        //    the calculations the question asks for — none when it asks for none.
        $matched = $context->selectionInfo['calculations']['matched'] ?? null;
        if (is_array($matched)) {
            $chosen = [];
            foreach (array_keys($matched) as $key) {
                if (isset($calculations[$key]) && is_array($calculations[$key])) {
                    $chosen[$key] = $calculations[$key];
                }
            }

            return $chosen;
        }

        // 2. No verdict (matching off / embedding failed): the calculations over the
        //    question's PRIMARY labels.
        $labels = $this->primaryLabels($context);
        if ($labels === []) {
            return $calculations;
        }

        $selected = array_fill_keys(array_map('strtolower', $labels), true);

        return array_filter($calculations, function ($calc) use ($selected): bool {
            if (!is_array($calc)) {
                return true; // malformed entries are skipped by format()
            }
            $own = CalculationMatcher::labelsOf($calc);
            if ($own === []) {
                return true;
            }
            foreach ($own as $label) {
                if (isset($selected[strtolower($label)])) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * The labels the question is about: the projected schema minus the labels the
     * entity-selection pruner kept only to join or scope them (identity / tenant /
     * closure / ownership) — a tenant hop through Project must not pull the Project
     * formulas in.
     *
     * @return string[]
     */
    private function primaryLabels(PipelineContext $context): array
    {
        $labels = array_values(array_filter((array) ($context->graphSchema['labels'] ?? []), fn ($l) => is_string($l) && $l !== ''));
        $tiers = (array) ($context->selectionInfo['selection_tiers'] ?? []);
        if ($tiers === []) {
            return $labels;
        }

        $support = [
            EntitySelectionPruner::TIER_IDENTITY,
            EntitySelectionPruner::TIER_TENANT,
            EntitySelectionPruner::TIER_CLOSURE,
            EntitySelectionPruner::TIER_OWNERSHIP,
        ];

        return array_values(array_filter($labels, fn ($l) => !in_array($tiers[$l] ?? null, $support, true)));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function configured(): array
    {
        $calculations = config('ai.calculations', []);

        return is_array($calculations) ? $calculations : [];
    }
}
