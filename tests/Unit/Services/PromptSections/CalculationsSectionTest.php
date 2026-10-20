<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\CalculationsSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * CalculationsSection — host-defined canonical formulas reach the generation prompt.
 */
class CalculationsSectionTest extends TestCase
{
    /** @test */
    public function it_renders_configured_calculations_with_formula_and_cypher(): void
    {
        config()->set('ai.calculations', [
            'team_total_owed' => [
                'label' => 'Total owed to the team',
                'description' => 'Outstanding receivables.',
                'formula' => 'SUM of Invoice.calc_due_amount where calc_due_amount > 0',
                'cypher' => 'MATCH (i:Invoice) WHERE i.calc_due_amount > 0 RETURN sum(i.calc_due_amount) AS total',
                'notes' => 'Never sum calc_total_amount for "owed".',
            ],
        ]);

        $section = new CalculationsSection();
        $context = new PipelineContext();

        $this->assertTrue($section->shouldInclude('how much are we owed?', $context));

        $output = $section->format('how much are we owed?', $context);

        $this->assertStringContainsString('DOMAIN CALCULATIONS', $output);
        $this->assertStringContainsString('Total owed to the team — Outstanding receivables.', $output);
        $this->assertStringContainsString('Formula: SUM of Invoice.calc_due_amount', $output);
        $this->assertStringContainsString('Cypher: MATCH (i:Invoice)', $output);
        $this->assertStringContainsString('Notes: Never sum calc_total_amount', $output);
        $this->assertStringContainsString('use its canonical formula EXACTLY', $output);
    }

    /** @test */
    public function it_requires_verification_columns_alongside_the_exact_aggregate(): void
    {
        // Lever L2-B(iii): "use EXACTLY" alone made the generator drop identifying
        // columns (bare $0 aggregates are unverifiable). The rule must keep the
        // aggregation exact while REQUIRING identifying/verification columns.
        config()->set('ai.calculations', [
            'metric' => ['label' => 'A metric', 'formula' => 'SUM of x'],
        ]);

        $output = (new CalculationsSection())->format('q', new PipelineContext());

        $this->assertStringContainsString('NEVER return the bare aggregate alone', $output);
        $this->assertStringContainsString('identifying/verification columns', $output);
        $this->assertStringContainsString('count of rows aggregated', $output);
        $this->assertStringContainsString('ADD columns; never change the aggregation itself', $output);
    }

    /** @test */
    public function it_is_excluded_entirely_when_no_calculations_are_configured(): void
    {
        config()->set('ai.calculations', []);

        $section = new CalculationsSection();
        $context = new PipelineContext();

        $this->assertFalse($section->shouldInclude('anything', $context));
        $this->assertSame('', $section->format('anything', $context));
    }

    /** @test */
    public function malformed_entries_are_skipped_not_fatal(): void
    {
        config()->set('ai.calculations', [
            'bad' => 'just a string',
            'good' => ['label' => 'Good metric', 'formula' => 'SUM of x'],
        ]);

        $output = (new CalculationsSection())->format('q', new PipelineContext());

        $this->assertStringContainsString('Good metric', $output);
        $this->assertStringNotContainsString('just a string', $output);
    }

    private function configureFinance(): void
    {
        config()->set('ai.calculations', [
            'receivables' => [
                'label' => 'Receivables',
                'cypher' => 'MATCH (i:Invoice) WHERE i.due_amount >= 0.01 RETURN sum(i.due_amount) AS owed',
            ],
            'unit_balance' => [
                'label' => 'Unit balance',
                'cypher' => 'MATCH (u:Unit)-[:UNION]->(un:Union) OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) RETURN u.name',
            ],
            'payables' => [
                'label' => 'Payables',
                'cypher' => 'MATCH (b:Bill) OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) RETURN sum(b.due_amount)',
            ],
            'declared' => [
                'label' => 'Declared entities',
                'formula' => 'count of members',
                'entities' => ['TeamRole'],
            ],
            'generic' => [
                'label' => 'Generic metric',
                'formula' => 'a formula naming no graph label',
            ],
        ]);
    }

    /** @test */
    public function only_the_calculations_over_selected_labels_are_rendered(): void
    {
        // Task 5.11: every formula shipped on every turn (~5K chars) — a team-roster
        // question carried the receivables and payables formulas.
        $this->configureFinance();
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Unit', 'Invoice', 'Union']];

        $output = (new CalculationsSection())->format('what does unit 310 owe?', $context);

        $this->assertStringContainsString('Receivables', $output);
        $this->assertStringContainsString('Unit balance', $output);
        $this->assertStringNotContainsString('Payables', $output);
        $this->assertStringNotContainsString('Declared entities', $output);
        // A calculation that names no label cannot be judged irrelevant: kept.
        $this->assertStringContainsString('Generic metric', $output);
    }

    /** @test */
    public function an_explicit_entities_list_overrides_the_cypher_labels(): void
    {
        $this->configureFinance();
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Team', 'TeamRole', 'User']];

        $output = (new CalculationsSection())->format('team roles', $context);

        $this->assertStringContainsString('Declared entities', $output);
        $this->assertStringNotContainsString('Receivables', $output);
    }

    /** @test */
    public function the_section_is_skipped_when_every_calculation_is_off_topic(): void
    {
        config()->set('ai.calculations', [
            'payables' => ['label' => 'Payables', 'cypher' => 'MATCH (b:Bill) RETURN sum(b.due_amount)'],
        ]);
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Team', 'User']];

        $section = new CalculationsSection();

        $this->assertFalse($section->shouldInclude('members of my team', $context));
        $this->assertSame('', $section->format('members of my team', $context));
    }

    /** @test */
    public function every_calculation_renders_when_the_context_has_no_projected_schema(): void
    {
        $this->configureFinance();

        $output = (new CalculationsSection())->format('anything', new PipelineContext());

        foreach (['Receivables', 'Unit balance', 'Payables', 'Declared entities', 'Generic metric'] as $label) {
            $this->assertStringContainsString($label, $output);
        }
    }

    /** @test */
    public function the_calculations_the_question_asks_for_are_the_only_ones_rendered(): void
    {
        // The retriever's CalculationMatcher (embedding similarity) decided which
        // metric the question asks for — label overlap would render all three
        // Invoice formulas for any Invoice question.
        $this->configureFinance();
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Unit', 'Invoice', 'Union']];
        $context->selectionInfo = ['calculations' => ['matched' => ['unit_balance' => 0.64]]];

        $output = (new CalculationsSection())->format('what does unit 310 owe?', $context);

        $this->assertStringContainsString('Unit balance', $output);
        $this->assertStringNotContainsString('Receivables', $output);
        $this->assertStringNotContainsString('Generic metric', $output);
    }

    /** @test */
    public function labels_kept_only_to_join_or_scope_do_not_pull_calculations_in(): void
    {
        $this->configureFinance();
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Account', 'Union']];
        // No matcher verdict (disabled / embedding failed): label-overlap fallback.
        $context->selectionInfo = [
            'selection_tiers' => ['Account' => 'seed', 'Union' => 'tenant'],
        ];

        $output = (new CalculationsSection())->format('receivable accounts', $context);

        // Union is only the tenant hop of Account: the unit-balance formula stays out.
        $this->assertStringNotContainsString('Unit balance', $output);
        $this->assertStringNotContainsString('Receivables', $output);
        $this->assertStringContainsString('Generic metric', $output);
    }

    /** @test */
    public function no_calculation_renders_when_the_matcher_judged_none_asked_for(): void
    {
        // Live q3 (board question): the seed Union appears in two money formulas'
        // cypher, so label overlap shipped them; the matcher judged no metric asked.
        $this->configureFinance();
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['BoardMember', 'Union']];
        $context->selectionInfo = ['calculations' => ['matched' => []]];

        $section = new CalculationsSection();

        $this->assertFalse($section->shouldInclude('who is on our board?', $context));
        $this->assertSame('', $section->format('who is on our board?', $context));
    }
}
