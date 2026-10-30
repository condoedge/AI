<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Exceptions\QueryValidationException;

class QueryGeneratorTest extends TestCase
{
    private QueryGenerator $generator;
    private LlmProviderInterface $mockLlm;
    private GraphStoreInterface $mockGraph;
    private array $config;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockGraph = Mockery::mock(GraphStoreInterface::class);
        $this->config = [
            'temperature' => 0.1,
            'max_retries' => 3,
            'allow_write_operations' => false,
            'default_limit' => 100,
            'max_complexity' => 100,
        ];

        $this->generator = new QueryGenerator(
            $this->mockLlm,
            $this->mockGraph,
            $this->config
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build a PipelineContext DTO from the legacy array-shaped context fields.
     *
     * QueryGenerator::generate() takes the canonical PipelineContext DTO, so the
     * tests map the schema/entity/query fields onto the corresponding properties.
     */
    /** A structured generation reply (Task 5.6 contract). */
    private function draft(?string $cypher, bool $needsQuery = true): object
    {
        return (object) ['needs_query' => $needsQuery, 'cypher' => $cypher, 'reason' => ''];
    }

    /** chatJson receives the system (identity + rules) and user messages. */
    private function systemAndUserMessages(): \Mockery\Matcher\Closure
    {
        return Mockery::on(fn ($messages) => is_array($messages)
            && ($messages[0]['role'] ?? null) === 'system'
            && ($messages[1]['role'] ?? null) === 'user');
    }

    private function makeContext(array $fields = []): PipelineContext
    {
        $context = new PipelineContext();
        $context->graphSchema = $fields['graph_schema'] ?? [];
        $context->similarQueries = $fields['similar_queries'] ?? [];
        $context->entityMetadata = $fields['entity_metadata'] ?? [];
        $context->conversationContext = $fields['conversation_context'] ?? [];

        return $context;
    }

    // =========================================================================
    // Query Generation Tests
    // =========================================================================

    public function test_generate_simple_query(): void
    {
        $question = "What are the customers?";
        $context = $this->makeContext([
            'graph_schema' => [
                'labels' => ['Customer', 'Order'],
                'relationships' => ['PURCHASED'],
            ],
            'similar_queries' => [],
            'relevant_entities' => [],
        ]);

        $this->mockLlm->shouldReceive('chatJson')
            ->once()
            ->with($this->systemAndUserMessages(), Mockery::any())
            ->andReturn($this->draft('MATCH (n:Customer) RETURN n LIMIT 100'));

        // Disable explanation to avoid second complete() call
        $result = $this->generator->generate($question, $context, ['explain' => false]);

        $this->assertInstanceOf(QueryGenerationResult::class, $result);
        $this->assertStringContainsString('MATCH', $result->cypher);
    }

    public function test_how_many_residents_goes_through_the_llm_and_never_hard_fails(): void
    {
        // Phase 2.B (plan issue #11): the English-only TemplateMatcher matched
        // "How many residents?", singularized it to "Resident" and threw
        // InvalidArgumentException("Unknown label") OUTSIDE the retry loop when
        // the graph named that concept differently. There is one generation path
        // now: the LLM, which maps the concept onto the real schema.
        $context = $this->makeContext([
            'graph_schema' => ['labels' => ['Contact', 'Unit'], 'relationships' => []],
        ]);

        $this->mockLlm->shouldReceive('chatJson')
            ->once()
            ->with($this->systemAndUserMessages(), Mockery::any())
            ->andReturn($this->draft('MATCH (c:Contact) RETURN count(c) AS residents'));

        $result = $this->generator->generate('How many residents?', $context, ['explain' => false]);

        $this->assertSame('MATCH (c:Contact) RETURN count(c) AS residents', $result->cypher);
        $this->assertNull($result->metadata['template_used'] ?? null);
    }

    public function test_formerly_template_shaped_questions_use_the_llm_path(): void
    {
        $context = $this->makeContext([
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ]);

        $this->mockLlm->shouldReceive('chatJson')
            ->twice()
            ->with($this->systemAndUserMessages(), Mockery::any())
            ->andReturn($this->draft('MATCH (n:Customer) RETURN n LIMIT 100'));

        foreach (['Show all customers', 'How many orders'] as $question) {
            $result = $this->generator->generate($question, $context, ['explain' => false]);
            $this->assertStringContainsString('MATCH', $result->cypher);
        }
    }

    public function test_generate_retries_on_validation_failure(): void
    {
        $question = "What are the customers?";
        $context = $this->makeContext([
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ]);

        // First attempt returns invalid query
        $this->mockLlm->shouldReceive('chatJson')
            ->once()
            ->with($this->systemAndUserMessages(), Mockery::any())
            ->andReturn($this->draft('INVALID QUERY'));

        // Second attempt returns valid query
        $this->mockLlm->shouldReceive('chatJson')
            ->once()
            ->with($this->systemAndUserMessages(), Mockery::any())
            ->andReturn($this->draft('MATCH (n:Customer) RETURN n LIMIT 100'));

        $result = $this->generator->generate($question, $context, ['explain' => false]);

        $this->assertGreaterThan(0, $result->metadata['retry_count']);
        $this->assertStringContainsString('MATCH', $result->cypher);
    }

    public function test_generate_throws_exception_after_max_retries(): void
    {
        $this->expectException(QueryGenerationException::class);

        $question = "What are the customers?";
        $context = $this->makeContext([
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ]);

        // All attempts return invalid queries
        $this->mockLlm->shouldReceive('chatJson')
            ->times(3)
            ->with($this->systemAndUserMessages(), Mockery::any())
            ->andReturn($this->draft('INVALID QUERY'));

        $this->generator->generate($question, $context, ['explain' => false]);
    }

    public function test_generate_respects_temperature_option(): void
    {
        $question = "What are the customers?";
        $context = $this->makeContext([
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ]);

        $this->mockLlm->shouldReceive('chatJson')
            ->once()
            ->with($this->systemAndUserMessages(), Mockery::on(function ($options) {
                return isset($options['temperature']) && $options['temperature'] === 0.5;
            }))
            ->andReturn($this->draft('MATCH (n:Customer) RETURN n LIMIT 100'));

        $result = $this->generator->generate($question, $context, ['temperature' => 0.5, 'explain' => false]);

        $this->assertInstanceOf(QueryGenerationResult::class, $result);
        $this->assertNotNull($result->cypher);
    }

    public function test_generate_includes_explanation_when_requested(): void
    {
        $question = "What are the customers?";
        $context = $this->makeContext([
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ]);

        // First call generates the query (structured JSON reply)
        $this->mockLlm->shouldReceive('chatJson')
            ->once()
            ->with($this->systemAndUserMessages(), Mockery::on(function ($options) {
                return isset($options['max_tokens']) && $options['max_tokens'] === 800;
            }))
            ->andReturn($this->draft('MATCH (n:Customer) RETURN n LIMIT 100'));

        // Second call generates the explanation
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->with(Mockery::any(), null, Mockery::on(function ($options) {
                return isset($options['max_tokens']) && $options['max_tokens'] === 150;
            }))
            ->andReturn('This query finds all customer nodes.');

        $result = $this->generator->generate($question, $context, ['explain' => true]);

        $this->assertNotEmpty($result->explanation);
    }

    // =========================================================================
    // Validation Tests
    // =========================================================================

    public function test_validate_accepts_read_only_query(): void
    {
        $query = 'MATCH (n:Customer) RETURN n LIMIT 100';

        $result = $this->generator->validate($query);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertTrue($result['is_read_only']);
    }

    public function test_validate_rejects_delete_without_permission(): void
    {
        $query = 'MATCH (n:Customer) DELETE n';

        $result = $this->generator->validate($query, ['allow_write' => false]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('DELETE', $result['errors'][0]);
    }

    public function test_validate_allows_delete_with_permission(): void
    {
        $query = 'MATCH (n:Customer) DELETE n';

        $result = $this->generator->validate($query, ['allow_write' => true]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertFalse($result['is_read_only']);
    }

    public function test_validate_warns_about_missing_limit(): void
    {
        $query = 'MATCH (n:Customer) RETURN n';

        $result = $this->generator->validate($query);

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('LIMIT', $result['warnings'][0]);
    }

    public function test_validate_calculates_complexity(): void
    {
        $query = 'MATCH (n:Customer) RETURN n LIMIT 100';

        $result = $this->generator->validate($query);

        $this->assertArrayHasKey('complexity', $result);
        $this->assertGreaterThan(0, $result['complexity']);
    }

    public function test_validate_throws_exception_for_empty_query(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->validate('');
    }

    public function test_validate_rejects_query_without_match_or_return(): void
    {
        $query = 'SHOW DATABASES';

        $result = $this->generator->validate($query);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_validate_warns_about_high_complexity(): void
    {
        $query = 'MATCH (a)-[r1]->(b)-[r2]->(c)-[r3]->(d) WHERE a.prop = 1 AND b.prop = 2 RETURN a, b, c, d LIMIT 10';

        $result = $this->generator->validate($query, ['max_complexity' => 20]);

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertGreaterThan(20, $result['complexity']);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function test_generate_with_empty_context(): void
    {
        $question = "What are the customers?";
        $context = $this->makeContext();

        $this->mockLlm->shouldReceive('chatJson')
            ->once()
            ->with($this->systemAndUserMessages(), Mockery::any())
            ->andReturn($this->draft('MATCH (n:Customer) RETURN n LIMIT 100'));

        $result = $this->generator->generate($question, $context, ['explain' => false]);

        $this->assertInstanceOf(QueryGenerationResult::class, $result);
        $this->assertNotNull($result->cypher);
    }

    public function test_generate_extracts_cypher_from_markdown(): void
    {
        $question = "What are the customers?";
        $context = $this->makeContext([
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ]);

        $this->mockLlm->shouldReceive('chatJson')
            ->once()
            ->with($this->systemAndUserMessages(), Mockery::any())
            ->andReturn($this->draft("```cypher\nMATCH (n:Customer) RETURN n LIMIT 100\n```"));

        $result = $this->generator->generate($question, $context, ['explain' => false]);

        $this->assertStringNotContainsString('```', $result->cypher);
        $this->assertStringContainsString('MATCH', $result->cypher);
    }

    public function test_validate_with_cypher_comments(): void
    {
        $query = '// Find customers
MATCH (n:Customer)
// Return all
RETURN n LIMIT 100';

        $result = $this->generator->validate($query);

        $this->assertTrue($result['valid']);
    }

    private function formulaTurn(bool $matched): PipelineContext
    {
        config(['ai.calculations' => ['platform_fees' => ['label' => 'Platform fees paid by a campaign', 'formula' => 'Campaign.platform_fees',
            'cypher' => 'MATCH (c:Campaign) RETURN c.platform_fees AS platform_fees']]]);
        $context = $this->makeContext(['graph_schema' => ['labels' => ['Campaign']]]);
        $context->qu = new QueryUnderstandingResult(questionType: 'data_query');
        $context->selectionInfo['calculations'] = ['matched' => $matched ? ['platform_fees' => 0.8] : []];

        return $context;
    }

    /** @test Live 8-data "Combien on a payé en frais Stripe?": a decline on a question a domain formula matches gets ONE retry naming it. */
    public function a_data_question_declined_although_a_formula_matches_is_redrafted_once_with_that_formula(): void
    {
        $prompts = [];
        $this->mockLlm->shouldReceive('chatJson')->twice()
            ->andReturnUsing(function (array $messages) use (&$prompts) {
                $prompts[] = $messages[1]['content'];

                return count($prompts) === 1
                    ? (object) ['needs_query' => false, 'cypher' => null, 'reason' => 'Stripe fees are not recorded.']
                    : $this->draft('MATCH (c:Campaign) RETURN c.platform_fees AS platform_fees LIMIT 100');
            });

        $result = $this->generator->generate('Combien on a payé en frais Stripe?', $this->formulaTurn(true), ['explain' => false]);

        $this->assertStringContainsString('c.platform_fees', $result->cypher);
        $this->assertStringContainsString('Platform fees paid by a campaign', $prompts[1]);
        $this->assertStringContainsString('Stripe fees are not recorded.', $prompts[1]);
    }

    /** @test */
    public function a_draft_ordering_an_aggregate_by_an_unreturned_property_is_projected_before_it_runs(): void
    {
        $this->mockLlm->shouldReceive('chatJson')->once()->andReturn($this->draft(
            'MATCH (c:Campaign) RETURN c.id AS id, count(*) AS n ORDER BY c.start_date DESC LIMIT 100'
        ));

        $this->assertSame(
            'MATCH (c:Campaign) RETURN c.id AS id, count(*) AS n, c.start_date AS c_start_date ORDER BY c_start_date DESC LIMIT 100',
            $this->generator->generate('Ventes par campagne', $this->formulaTurn(false), ['explain' => false])->cypher
        );
    }

    /** @test The generator says the recorded query already answers: no redraft, the manager runs that query. */
    public function a_reply_that_reuses_the_previous_query_is_passed_on_without_a_redraft(): void
    {
        $this->mockLlm->shouldReceive('chatJson')->once()
            ->andReturn((object) ['needs_query' => false, 'cypher' => null, 'reuse_previous' => true, 'reason' => 'Same question.']);

        $result = $this->generator->generate('Les campagnes?', $this->formulaTurn(true), ['explain' => false]);

        $this->assertFalse($result->hasCypher());
        $this->assertTrue($result->metadata['reuse_previous']);
    }

    /** @test */
    public function a_decline_with_no_formula_matched_stays_a_decline_without_another_call(): void
    {
        $this->mockLlm->shouldReceive('chatJson')->once()->andReturn((object) ['needs_query' => false, 'cypher' => null, 'reason' => 'Nothing to look up.']);

        $this->assertSame('', $this->generator->generate('Merci!', $this->formulaTurn(false), ['explain' => false])->cypher);
    }
}
