<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

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
 *           'description' => 'What residents/customers still owe.',
 *           'formula'     => 'SUM of Invoice.calc_due_amount where calc_due_amount > 0',
 *           'cypher'      => 'MATCH (i:Invoice) WHERE i.calc_due_amount > 0 RETURN sum(i.calc_due_amount) AS total_owed',
 *           'notes'       => 'Split overdue vs not-yet-due on i.due_at < date().',
 *       ],
 *   ],
 *
 * Every key is optional except one of formula/cypher. Priority 74: with the query rules
 * (QueryRules 75 / TenantScopingRules 76), just before the question.
 */
class CalculationsSection extends BasePromptSection
{
    protected string $name = 'calculations';
    protected int $priority = 74;

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $calculations = $this->configured();

        if (empty($calculations)) {
            return '';
        }

        $output = $this->header('DOMAIN CALCULATIONS (canonical formulas)');
        $output .= "When the question asks for one of these metrics, use its canonical formula EXACTLY — do not invent a different aggregation.\n\n";

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
        return !empty($this->configured());
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
