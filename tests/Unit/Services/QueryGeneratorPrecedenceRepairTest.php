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
 * Eval p22-1001-B, rights t2: a draft whose WHERE mixes OR and AND at one level
 * (`(unit) OR (contact) AND status … AND year …`) is valid Cypher that filters the
 * last alternative only. It is fed back like a schema violation — same retry
 * budget, the offending predicate quoted — and a draft still ambiguous when the
 * budget is spent is returned with the violation recorded (never blocked).
 */
class QueryGeneratorPrecedenceRepairTest extends TestCase
{
    private const AMBIGUOUS = "MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL "
        . "MATCH (i:Invoice) WHERE (i.customer_type = 'unit' AND i.customer_id = u.id) OR (i.customer_type = 'contact' AND i.customer_id = c.id) "
        . "AND i.status IN ['2', '3', '4'] AND substring(i.invoiced_at, 0, 4) = '2026' RETURN SUM(i.total_amount) AS annual_contribution LIMIT 100";

    private const PREDICATE = "(i.customer_type = 'unit' AND i.customer_id = u.id) OR (i.customer_type = 'contact' AND i.customer_id = c.id) "
        . "AND i.status IN ['2', '3', '4'] AND substring(i.invoiced_at, 0, 4) = '2026'";

    private const PARENTHESIZED = "MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL "
        . "MATCH (i:Invoice) WHERE ((i.customer_type = 'unit' AND i.customer_id = u.id) OR (i.customer_type = 'contact' AND i.customer_id = c.id)) "
        . "AND i.status IN ['2', '3', '4'] AND substring(i.invoiced_at, 0, 4) = '2026' RETURN SUM(i.total_amount) AS annual_contribution LIMIT 100";

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

    private function generator(ScriptedJsonLlm $llm, int $maxRetries = 3): QueryGenerator
    {
        return new QueryGenerator($llm, Mockery::mock(GraphStoreInterface::class), [
            'temperature' => 0.1,
            'max_retries' => $maxRetries,
        ]);
    }

    /** @test */
    public function an_ambiguous_predicate_is_fed_back_and_the_parenthesized_draft_is_accepted(): void
    {
        $llm = new ScriptedJsonLlm([
            ScriptedJsonLlm::draft(self::AMBIGUOUS),
            ScriptedJsonLlm::draft(self::PARENTHESIZED),
        ]);

        $result = $this->generator($llm)->generate('How much is my contribution this year?', new PipelineContext(), ['explain' => false]);

        $this->assertSame(self::PARENTHESIZED, $result->cypher);
        $this->assertSame([], $result->metadata['precedence_violations']);
        $this->assertSame([self::PREDICATE], $result->metadata['precedence_repaired']);
        $this->assertSame([], $result->metadata['schema_violations']);
        $this->assertSame(1, $result->metadata['repair_attempts']);
        $this->assertSame(1, $result->metadata['retry_count']);
        $this->assertCount(2, $llm->calls);

        $feedback = $llm->userMessage(1);
        $this->assertStringContainsString(self::PREDICATE, $feedback, 'the offending predicate is quoted');
        $this->assertStringContainsString('(A OR B) AND C AND D', $feedback);
        $this->assertStringContainsString(self::AMBIGUOUS, $feedback, 'the previous draft is shown back');
        $this->assertStringContainsString('corrected query', $feedback);
        $this->assertStringNotContainsString('live graph schema', $feedback, 'not worded as a schema violation');
    }

    /** @test */
    public function a_draft_still_ambiguous_when_the_budget_is_spent_is_returned_with_the_violation_recorded(): void
    {
        $llm = new ScriptedJsonLlm([
            ScriptedJsonLlm::draft(self::AMBIGUOUS),
            ScriptedJsonLlm::draft(self::AMBIGUOUS),
        ]);

        $result = $this->generator($llm, 2)->generate('How much is my contribution this year?', new PipelineContext(), ['explain' => false]);

        $this->assertSame(self::AMBIGUOUS, $result->cypher, 'same as an unrepaired schema violation: returned, not blocked');
        $this->assertCount(1, $result->metadata['precedence_violations']);
        $this->assertStringContainsString(self::PREDICATE, $result->metadata['precedence_violations'][0]);
        $this->assertSame([self::PREDICATE], $result->metadata['precedence_repaired']);
        $this->assertSame(1, $result->metadata['repair_attempts']);
        $this->assertCount(2, $llm->calls, 'the SAME retry budget — no extra call');
    }

    /** @test */
    public function an_unambiguous_draft_is_accepted_first_try(): void
    {
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::PARENTHESIZED)]);

        $result = $this->generator($llm)->generate('How much is my contribution this year?', new PipelineContext(), ['explain' => false]);

        $this->assertSame(self::PARENTHESIZED, $result->cypher);
        $this->assertSame([], $result->metadata['precedence_violations']);
        $this->assertSame([], $result->metadata['precedence_repaired']);
        $this->assertSame(0, $result->metadata['repair_attempts']);
        $this->assertCount(1, $llm->calls);
    }

    /** @test */
    public function a_schema_violation_and_an_ambiguous_predicate_are_fed_back_together(): void
    {
        $context = new PipelineContext();
        $context->graphSchema = [
            'labels' => ['Invoice', 'Unit'],
            'all_labels' => ['Invoice', 'Unit'],
            'all_relationship_types' => ['CUSTOMER'],
            'all_relationship_patterns' => [['from_label' => 'Invoice', 'type' => 'CUSTOMER', 'to_label' => 'Unit']],
            'properties' => ['Invoice' => ['id', 'status', 'total'], 'Unit' => ['id', 'name']],
            'all_property_keys' => ['id', 'status', 'total', 'name'],
        ];
        $both = 'MATCH (u:Unit)-[:CUSTOMER]->(i:Invoice) WHERE i.status = 1 OR i.status = 2 AND i.total > 0 RETURN i.id LIMIT 100';
        $fixed = 'MATCH (i:Invoice)-[:CUSTOMER]->(u:Unit) WHERE (i.status = 1 OR i.status = 2) AND i.total > 0 RETURN i.id LIMIT 100';
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft($both), ScriptedJsonLlm::draft($fixed)]);

        $result = $this->generator($llm)->generate('Open invoices?', $context, ['explain' => false]);

        $this->assertSame($fixed, $result->cypher);
        $feedback = $llm->userMessage(1);
        $this->assertStringContainsString('live graph schema', $feedback);
        $this->assertStringContainsString('[:CUSTOMER]', $feedback);
        $this->assertStringContainsString('i.status = 1 OR i.status = 2 AND i.total > 0', $feedback);
    }

    /** @test */
    public function the_check_can_be_turned_off(): void
    {
        config()->set('ai.query_generation.precedence_check', false);
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::AMBIGUOUS)]);

        $result = $this->generator($llm)->generate('How much is my contribution this year?', new PipelineContext(), ['explain' => false]);

        $this->assertSame(self::AMBIGUOUS, $result->cypher);
        $this->assertSame([], $result->metadata['precedence_violations']);
        $this->assertCount(1, $llm->calls);
    }
}
