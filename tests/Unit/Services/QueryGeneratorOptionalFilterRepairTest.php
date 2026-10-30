<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Tests\Fixtures\ScriptedJsonLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * A text condition on an OPTIONAL MATCH the total does not use filters nothing. It is fed
 * back like the other draft checks — same retry budget, the condition quoted — and a draft
 * still written that way when the budget is spent runs as written, the violation recorded.
 */
class QueryGeneratorOptionalFilterRepairTest extends TestCase
{
    private const CONDITION = "toLower(s.name) CONTAINS toLower('assurance')";

    private const OPTIONAL = "MATCH (b:Bill) OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE " . self::CONDITION
        . ' WITH b, s RETURN sum(b.total_amount) AS total LIMIT 100';

    private const REQUIRED = "MATCH (b:Bill) MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE " . self::CONDITION
        . ' WITH b, s RETURN sum(b.total_amount) AS total LIMIT 100';

    public function setUp(): void
    {
        parent::setUp();
        config()->set('ai.security.enabled', false);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function generate(ScriptedJsonLlm $llm, int $maxRetries = 3): \Condoedge\Ai\DTOs\QueryGenerationResult
    {
        $generator = new QueryGenerator($llm, Mockery::mock(GraphStoreInterface::class), ['temperature' => 0.1, 'max_retries' => $maxRetries]);

        return $generator->generate('How much was paid for insurance?', new PipelineContext(), ['explain' => false]);
    }

    /** @test */
    public function the_condition_is_fed_back_and_the_redraft_is_accepted(): void
    {
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::OPTIONAL), ScriptedJsonLlm::draft(self::REQUIRED)]);

        $result = $this->generate($llm);

        $this->assertSame(self::REQUIRED, $result->cypher);
        $this->assertSame([], $result->metadata['optional_filter_violations']);
        $this->assertSame([self::CONDITION], $result->metadata['optional_filter_repaired']);
        $this->assertSame(1, $result->metadata['repair_attempts']);
        $this->assertCount(2, $llm->calls);

        $feedback = $llm->userMessage(1);
        $this->assertStringContainsString(self::CONDITION, $feedback, 'the condition is quoted');
        $this->assertStringContainsString('use MATCH', $feedback);
        $this->assertStringContainsString(self::OPTIONAL, $feedback, 'the previous draft is shown back');
        $this->assertStringNotContainsString('live graph schema', $feedback);
        $this->assertStringNotContainsString('parentheses', $feedback);
    }

    /** @test */
    public function a_draft_still_written_that_way_when_the_budget_is_spent_runs_with_the_violation_recorded(): void
    {
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::OPTIONAL), ScriptedJsonLlm::draft(self::OPTIONAL)]);

        $result = $this->generate($llm, 2);

        $this->assertSame(self::OPTIONAL, $result->cypher, 'returned, not blocked');
        $this->assertCount(1, $result->metadata['optional_filter_violations']);
        $this->assertStringContainsString(self::CONDITION, $result->metadata['optional_filter_violations'][0]);
        $this->assertSame([self::CONDITION], $result->metadata['optional_filter_repaired']);
        $this->assertCount(2, $llm->calls, 'the SAME retry budget — no extra call');
    }

    /** @test */
    public function the_redraft_with_fewer_violations_is_the_one_kept(): void
    {
        $both = "MATCH (b:Bill) OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE s.name = 'a' MATCH (b)-[:U]->(u:U) WHERE u.a = 1 OR u.b = 2 AND u.c = 3 RETURN sum(b.total_amount) AS total LIMIT 100";
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft($both), ScriptedJsonLlm::draft(self::OPTIONAL), ScriptedJsonLlm::draft($both)]);

        $result = $this->generate($llm);

        $this->assertSame(self::OPTIONAL, $result->cypher);
        $this->assertSame([], $result->metadata['precedence_violations']);
        $this->assertCount(1, $result->metadata['optional_filter_violations']);
    }

    /** @test */
    public function an_ambiguous_predicate_and_an_ineffective_condition_are_fed_back_together(): void
    {
        $both = "MATCH (b:Bill) WHERE b.x = 1 OR b.y = 2 AND b.z = 3 OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) WHERE " . self::CONDITION . ' RETURN sum(b.total_amount) AS total LIMIT 100';
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft($both), ScriptedJsonLlm::draft(self::REQUIRED)]);

        $this->assertSame(self::REQUIRED, $this->generate($llm)->cypher);
        $feedback = $llm->userMessage(1);
        $this->assertStringContainsString('(A OR B) AND C AND D', $feedback);
        $this->assertStringContainsString(self::CONDITION, $feedback);
    }

    /** @test */
    public function a_clean_draft_is_accepted_first_try(): void
    {
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::REQUIRED)]);

        $result = $this->generate($llm);

        $this->assertSame(self::REQUIRED, $result->cypher);
        $this->assertSame([], $result->metadata['optional_filter_violations']);
        $this->assertSame([], $result->metadata['optional_filter_repaired']);
        $this->assertSame(0, $result->metadata['repair_attempts']);
        $this->assertCount(1, $llm->calls);
    }

    /** @test */
    public function the_check_can_be_turned_off(): void
    {
        config()->set('ai.query_generation.optional_filter_check', false);
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::OPTIONAL)]);

        $result = $this->generate($llm);

        $this->assertSame(self::OPTIONAL, $result->cypher);
        $this->assertSame([], $result->metadata['optional_filter_violations']);
        $this->assertCount(1, $llm->calls);
    }
}
