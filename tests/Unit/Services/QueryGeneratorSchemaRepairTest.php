<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Exceptions\LlmInvalidJsonException;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Tests\Fixtures\ScriptedJsonLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Tasks 5.4 + 5.5 + 5.6 at the generator:
 *  - 5.4: a draft that passes validation but uses a relationship / property the
 *    graph does not have is regenerated with the violations as feedback, within
 *    the SAME retry budget; a final draft still in violation is returned with
 *    schema_violations recorded (never blocked — the security layer still guards).
 *  - 5.5: an execution-repair request carries the Neo4j error + the generator's
 *    OWN previous draft as feedback.
 *  - 5.6: structured output {"needs_query", "cypher", "reason"} via chatJson;
 *    needs_query=false means no query; an unparseable reply falls back to the
 *    free-text extraction once.
 */
class QueryGeneratorSchemaRepairTest extends TestCase
{
    private const INVENTED = 'MATCH (un:Union) MATCH (i:Invoice)-[:BELONGS_TO_UNION]->(un) MATCH (c:Contact)<-[:CUSTOMER]-(i) RETURN c.first_name LIMIT 100';
    private const CORRECTED = 'MATCH (un:Union) MATCH (i:Invoice)-[:BELONGS_TO_UNION]->(un) MATCH (i)-[:CUSTOMER]->(u:Unit) RETURN u.id LIMIT 100';

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

    private function context(): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->graphSchema = [
            'labels' => ['Invoice', 'Contact', 'Union', 'Unit'],
            'all_labels' => ['Invoice', 'Contact', 'Union', 'Unit'],
            'all_relationship_types' => ['CUSTOMER', 'BELONGS_TO_UNION'],
            'all_relationship_patterns' => [
                ['from_label' => 'Invoice', 'type' => 'CUSTOMER', 'to_label' => 'Unit'],
                ['from_label' => 'Invoice', 'type' => 'BELONGS_TO_UNION', 'to_label' => 'Union'],
            ],
            'properties' => ['Contact' => ['id', 'first_name'], 'Unit' => ['id', 'name']],
            'all_property_keys' => ['id', 'first_name', 'name'],
        ];

        return $ctx;
    }

    // -------------------------------------------------------------- 5.4 repair

    /** @test */
    public function a_schema_violation_is_fed_back_and_the_corrected_draft_is_accepted(): void
    {
        $llm = new ScriptedJsonLlm([
            ScriptedJsonLlm::draft(self::INVENTED),
            ScriptedJsonLlm::draft(self::CORRECTED),
        ]);

        $result = $this->generator($llm)->generate('Who owes money?', $this->context(), ['explain' => false]);

        $this->assertSame(self::CORRECTED, $result->cypher);
        $this->assertSame([], $result->metadata['schema_violations']);
        $this->assertSame(1, $result->metadata['repair_attempts']);
        $this->assertSame(1, $result->metadata['retry_count']);

        $feedback = $llm->userMessage(1);
        $this->assertStringContainsString('(Contact)<-[:CUSTOMER]-(Invoice)', $feedback);
        $this->assertStringContainsString('Existing relationships from Invoice:', $feedback);
        $this->assertStringContainsString(self::INVENTED, $feedback, 'the previous draft is shown back');
        $this->assertStringContainsString('corrected query', $feedback);
    }

    /** @test */
    public function a_final_draft_still_in_violation_is_returned_with_the_violations_recorded(): void
    {
        $llm = new ScriptedJsonLlm([
            ScriptedJsonLlm::draft(self::INVENTED),
            ScriptedJsonLlm::draft(self::INVENTED),
        ]);

        $result = $this->generator($llm, 2)->generate('Who owes money?', $this->context(), ['explain' => false]);

        $this->assertSame(self::INVENTED, $result->cypher);
        $this->assertCount(1, $result->metadata['schema_violations']);
        $this->assertStringContainsString('[:CUSTOMER]', $result->metadata['schema_violations'][0]);
        $this->assertSame(1, $result->metadata['repair_attempts']);
        $this->assertCount(2, $llm->calls, 'the SAME retry budget — no extra call');
    }

    /** @test */
    public function a_valid_draft_is_accepted_first_try_with_no_violations(): void
    {
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::CORRECTED)]);

        $result = $this->generator($llm)->generate('Units?', $this->context(), ['explain' => false]);

        $this->assertSame(self::CORRECTED, $result->cypher);
        $this->assertSame([], $result->metadata['schema_violations']);
        $this->assertSame(0, $result->metadata['repair_attempts']);
    }

    /** @test */
    public function schema_validation_can_be_turned_off(): void
    {
        config()->set('ai.query_generation.schema_validation', false);
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::INVENTED)]);

        $result = $this->generator($llm)->generate('Who owes money?', $this->context(), ['explain' => false]);

        $this->assertSame(self::INVENTED, $result->cypher);
        $this->assertSame([], $result->metadata['schema_violations']);
    }

    // ------------------------------------------------------ 5.5 repair request

    /** @test */
    public function an_execution_repair_request_feeds_the_neo4j_error_and_the_own_draft(): void
    {
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::CORRECTED)]);
        $draft = 'MATCH (i:Invoice) RETURN sum(ab.total) LIMIT 100';

        $result = $this->generator($llm)->generate('Totals?', $this->context(), [
            'explain' => false,
            'max_retries' => 1,
            'repair' => ['cypher' => $draft, 'error' => 'Neo.ClientError.Statement.SyntaxError: Variable `ab` not defined'],
        ]);

        $this->assertSame(self::CORRECTED, $result->cypher);
        $this->assertTrue($result->metadata['execution_repair']);
        $prompt = $llm->userMessage(0);
        $this->assertStringContainsString('Variable `ab` not defined', $prompt);
        $this->assertStringContainsString($draft, $prompt);
    }

    /** @test */
    public function a_variable_not_defined_repair_carries_the_structural_scope_hint(): void
    {
        // Eval p12-1001-A: the raw error alone let the model keep `t.contact_phone`
        // after a WITH that dropped `t` — the same error came back.
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::CORRECTED)]);
        $draft = 'MATCH (t:Team) OPTIONAL MATCH (u:User) WHERE u.current_team_id = t.id '
            . 'WITH u, count(*) AS n RETURN u.name AS member_name, n, t.contact_phone AS phone LIMIT 100';

        $this->generator($llm)->generate('Members?', $this->context(), [
            'explain' => false,
            'max_retries' => 1,
            'repair' => ['cypher' => $draft, 'error' => 'Neo.ClientError.Statement.SyntaxError: Variable `t` not defined (line 1, column 120 (offset: 119))'],
        ]);

        $prompt = $llm->userMessage(0);
        $this->assertStringContainsString('Variable `t` not defined', $prompt);
        $this->assertStringContainsString('`WITH u, count(*) AS n` does not carry it', $prompt);
    }

    // --------------------------------------------------- 5.6 structured output

    /** @test */
    public function needs_query_false_means_no_query(): void
    {
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(null, false, 'The IDs are already in context.')]);

        $result = $this->generator($llm)->generate('Open the profile of John', $this->context(), ['explain' => false]);

        $this->assertSame('', $result->cypher);
        $this->assertFalse($result->hasCypher());
        $this->assertSame('The IDs are already in context.', $result->metadata['no_query_reason']);
    }

    /** @test */
    public function the_generation_call_goes_through_chat_json_with_system_and_user_messages(): void
    {
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft(self::CORRECTED)]);

        $this->generator($llm)->generate('Units?', $this->context(), ['explain' => false, 'temperature' => 0.5]);

        $this->assertSame('chatJson', $llm->calls[0]['method']);
        $this->assertSame(['system', 'user'], array_column($llm->calls[0]['messages'], 'role'));
        $this->assertSame(0.5, $llm->calls[0]['options']['temperature']);
        $this->assertStringContainsString('"needs_query"', $llm->calls[0]['messages'][0]['content'] . $llm->calls[0]['messages'][1]['content']);
    }

    /** @test */
    public function an_unparseable_reply_falls_back_to_free_text_extraction_once(): void
    {
        $llm = new ScriptedJsonLlm([
            new LlmInvalidJsonException('not JSON', "```cypher\n" . self::CORRECTED . "\n```"),
        ]);

        $result = $this->generator($llm)->generate('Units?', $this->context(), ['explain' => false]);

        $this->assertSame(self::CORRECTED, $result->cypher);
        $this->assertSame('text', $result->metadata['output_format']);
        $this->assertCount(1, $llm->calls, 'no re-request: the same reply is read as free text');
    }

    /** @test */
    public function a_reply_cut_at_the_output_budget_is_retried_with_a_teachable_error_not_read_as_free_text(): void
    {
        $cut = '{"needs_query": true, "cypher": "MATCH (un:Union) MATCH (i:Invoice)-[:BELONGS_TO_UNION]->(un) RETURN i.id, i.due';
        $llm = new ScriptedJsonLlm([
            new LlmInvalidJsonException('not JSON', $cut, true),
            ScriptedJsonLlm::draft(self::CORRECTED),
        ]);

        $result = $this->generator($llm)->generate('Units?', $this->context(), ['explain' => false]);

        $this->assertSame(self::CORRECTED, $result->cypher);
        $this->assertSame(1, $result->metadata['retry_count']);
        $this->assertStringContainsString('cut at the output budget', $llm->userMessage(1));
        $this->assertStringContainsString('shorter query', $llm->userMessage(1));
    }

    /** @test */
    public function a_reply_cut_on_every_attempt_fails_generation_instead_of_running_a_fragment(): void
    {
        $cut = new LlmInvalidJsonException('not JSON', '{"needs_query": true, "cypher": "MATCH (i:Invoice) RETURN', true);
        $llm = new ScriptedJsonLlm([$cut, $cut]);

        $this->expectException(\Condoedge\Ai\Exceptions\QueryGenerationException::class);
        $this->expectExceptionMessage('cut at the output budget');

        $this->generator($llm, 2)->generate('Units?', $this->context(), ['explain' => false]);
    }

    /** @test */
    public function an_empty_free_text_reply_means_no_query(): void
    {
        $llm = new ScriptedJsonLlm([new LlmInvalidJsonException('not JSON', '   ')]);

        $logger = Mockery::spy(\Condoedge\Ai\Support\AiLogger::class);
        $generator = new QueryGenerator($llm, Mockery::mock(GraphStoreInterface::class), [
            'max_retries' => 3,
        ], null, $logger);

        $result = $generator->generate('Hello', $this->context(), ['explain' => false]);

        $this->assertSame('', $result->cypher);
        $logger->shouldHaveReceived('info')
            ->with('query_generator', Mockery::pattern('/Empty free-text generation reply read as needs_query=false/'))
            ->once();
    }

    /** @test */
    public function the_legacy_sentinel_is_no_longer_an_exact_match_escape_hatch(): void
    {
        // The old path matched the reply 'NO QUERY REQUIRED' by exact string. Now a
        // JSON reply decides; the sentinel string as a cypher is just invalid Cypher
        // and is retried like any invalid draft.
        $llm = new ScriptedJsonLlm([
            ScriptedJsonLlm::draft('NO QUERY REQUIRED'),
            ScriptedJsonLlm::draft(self::CORRECTED),
        ]);

        $result = $this->generator($llm)->generate('Units?', $this->context(), ['explain' => false]);

        $this->assertSame(self::CORRECTED, $result->cypher);
    }

    /** @test */
    public function a_reply_that_is_not_the_contract_object_is_retried(): void
    {
        $llm = new ScriptedJsonLlm([
            ['just', 'a', 'list'],
            (object) ['needs_query' => true, 'cypher' => ''],
            ScriptedJsonLlm::draft(self::CORRECTED),
        ]);

        $result = $this->generator($llm)->generate('Units?', $this->context(), ['explain' => false]);

        $this->assertSame(self::CORRECTED, $result->cypher);
        $this->assertSame(2, $result->metadata['retry_count']);
    }

    /** @test */
    public function fenced_cypher_inside_the_json_string_is_cleaned(): void
    {
        $llm = new ScriptedJsonLlm([ScriptedJsonLlm::draft("```cypher\n" . self::CORRECTED . "\n```")]);

        $result = $this->generator($llm)->generate('Units?', $this->context(), ['explain' => false]);

        $this->assertSame(self::CORRECTED, $result->cypher);
        $this->assertSame('json', $result->metadata['output_format']);
    }
}
