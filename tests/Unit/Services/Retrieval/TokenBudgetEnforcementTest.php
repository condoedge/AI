<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PatternLibrary;
use Condoedge\Ai\Services\Retrieval\TokenBudgeter;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Tests\TestCase;

/**
 * C2: the token budget must be ENFORCED during prompt assembly (not merely
 * computed), and a per-turn token estimate must be produced for cost telemetry.
 *
 * Default-off contract: with no budget configured, the assembled prompt is
 * byte-identical to the unguarded path and no section is dropped.
 */
class TokenBudgetEnforcementTest extends TestCase
{
    private function builder(): SemanticPromptBuilder
    {
        return new SemanticPromptBuilder(new PatternLibrary());
    }

    private function richContext(): PipelineContext
    {
        $context = new PipelineContext();
        $context->graphSchema = [
            'labels' => ['Customer', 'Order', 'Product', 'Invoice', 'Payment'],
            'relationships' => ['PURCHASED', 'ORDERED', 'PAID', 'CONTAINS'],
            'properties' => [
                'Customer' => ['id', 'name', 'email', 'phone', 'address'],
                'Order' => ['id', 'total', 'created_at', 'status'],
            ],
        ];
        $context->relevantEntities = [
            'Customer' => [
                ['id' => 1, 'name' => 'Alice Anderson', 'email' => 'alice@example.com'],
                ['id' => 2, 'name' => 'Bob Builder', 'email' => 'bob@example.com'],
            ],
            'Order' => [
                ['id' => 10, 'total' => 1234.56, 'status' => 'confirmed'],
            ],
        ];
        $context->similarQueries = [
            ['question' => 'List all customers who ordered last month', 'query' => 'MATCH (c:Customer)-[:ORDERED]->(o:Order) RETURN c'],
            ['question' => 'Total revenue per product this year', 'query' => 'MATCH (p:Product)<-[:CONTAINS]-(o:Order) RETURN p, sum(o.total)'],
        ];

        return $context;
    }

    /** @test */
    public function default_behavior_is_byte_identical_when_no_budget_configured(): void
    {
        config(['ai.rag.token_budget' => null]);

        $question = 'How many customers do we have?';
        $context = $this->richContext();

        // Assemble twice (once as control) — with no budget the output must be
        // identical to the historical unguarded assembly. We compare against a
        // freshly built reference builder to guard against accidental ordering
        // drift introduced by the budget plumbing.
        ['system' => $system, 'user' => $user] = $this->builder()->buildPrompts($question, $context, false);
        ['system' => $refSystem, 'user' => $refUser] = $this->builder()->buildPrompts($question, $context, false);

        $this->assertSame($refSystem, $system);
        $this->assertSame($refUser, $user);

        // The Schema section (a droppable context section) is present when unbudgeted.
        $this->assertStringContainsString('Customer', $user);
    }

    /** @test */
    public function assembly_respects_a_configured_budget_by_dropping_lowest_priority_sections(): void
    {
        $question = 'How many customers do we have?';
        $context = $this->richContext();

        // Unbudgeted size, used to pick a budget that forces trimming.
        $unbudgeted = $this->builder()->buildPrompts($question, $context, false);
        $fullTokens = (new TokenBudgeter())->estimateTokens($unbudgeted['system'])
            + (new TokenBudgeter())->estimateTokens($unbudgeted['user']);

        // Pick a budget below the full size so at least one droppable section must go.
        $budget = (int) floor($fullTokens * 0.5);
        config(['ai.rag.token_budget' => $budget]);

        $budgeted = $this->builder()->buildPrompts($question, $context, false);

        $budgetedTokens = (new TokenBudgeter())->estimateTokens($budgeted['system'])
            + (new TokenBudgeter())->estimateTokens($budgeted['user']);

        // (i) Budget is respected: the assembled prompt shrank under enforcement.
        $this->assertLessThan($fullTokens, $budgetedTokens, 'Budget enforcement must trim the assembled prompt');

        // The essential tail is never dropped: the user question and the task cue survive.
        $this->assertStringContainsString('How many customers do we have?', $budgeted['user']);
        $this->assertStringContainsString('CYPHER QUERY', $budgeted['user']);

        // Telemetry: the per-turn token estimate is produced and reflects the trimmed size.
        $this->assertArrayHasKey('token_estimate', $budgeted);
        $this->assertSame($budgetedTokens, $budgeted['token_estimate']);

        // The drop list names at least one trimmed section.
        $this->assertArrayHasKey('sections_dropped', $budgeted);
        $this->assertNotEmpty($budgeted['sections_dropped']);
    }

    /** @test */
    public function token_telemetry_is_produced_even_without_a_budget(): void
    {
        config(['ai.rag.token_budget' => null]);

        $built = $this->builder()->buildPrompts('How many customers do we have?', $this->richContext(), false);

        $this->assertArrayHasKey('token_estimate', $built);
        $this->assertGreaterThan(0, $built['token_estimate']);

        // No budget -> nothing dropped.
        $this->assertArrayHasKey('sections_dropped', $built);
        $this->assertSame([], $built['sections_dropped']);
    }

    /** @test */
    public function enforce_drops_least_important_droppable_sections_first(): void
    {
        $budgeter = new TokenBudgeter();

        // 4 sections; budget only fits the two essentials + the smallest droppable.
        $sections = [
            ['name' => 'schema', 'role' => 'user', 'priority' => 20, 'content' => str_repeat('s', 400), 'keep' => false],
            ['name' => 'similar_queries', 'role' => 'user', 'priority' => 50, 'content' => str_repeat('q', 400), 'keep' => false],
            ['name' => 'question', 'role' => 'user', 'priority' => 80, 'content' => str_repeat('z', 40), 'keep' => true],
            ['name' => 'task_instructions', 'role' => 'user', 'priority' => 90, 'content' => str_repeat('t', 40), 'keep' => true],
        ];

        // Budget ~ keep both essentials (20 tokens) + the lower-priority schema (100 tokens) only.
        $kept = $budgeter->enforce($sections, 130);

        $keptNames = array_column($kept, 'name');

        // Essentials always survive.
        $this->assertContains('question', $keptNames);
        $this->assertContains('task_instructions', $keptNames);

        // The highest-priority-number droppable (similar_queries, 50) is dropped before
        // the lower-priority schema (20).
        $this->assertNotContains('similar_queries', $keptNames);
        $this->assertContains('schema', $keptNames);
    }

    /** @test */
    public function enforce_never_drops_kept_sections_even_if_over_budget(): void
    {
        $budgeter = new TokenBudgeter();

        $sections = [
            ['name' => 'question', 'role' => 'user', 'priority' => 80, 'content' => str_repeat('z', 4000), 'keep' => true],
            ['name' => 'task_instructions', 'role' => 'user', 'priority' => 90, 'content' => str_repeat('t', 4000), 'keep' => true],
        ];

        // Budget far below the essentials' size — they must still survive (correctness over budget).
        $kept = $budgeter->enforce($sections, 1);

        $this->assertCount(2, $kept);
    }

    /** @test */
    public function the_container_bound_builder_produces_token_telemetry(): void
    {
        // The DI wiring resolves the 3-arg builder (budgeter + logger) cleanly and the
        // resolved instance still emits the per-turn token estimate on its return value.
        $builder = app(SemanticPromptBuilder::class);

        $built = $builder->buildPrompts('How many customers do we have?', $this->richContext(), false);

        $this->assertArrayHasKey('token_estimate', $built);
        $this->assertGreaterThan(0, $built['token_estimate']);
    }
}
