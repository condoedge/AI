<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Chat\Support;

use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Services\Chat\Support\MessageTraceObserver;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.2 — the production turn trace persisted on the assistant message.
 */
class MessageTraceObserverTest extends TestCase
{
    private function prompt(int $chars): array
    {
        return [
            ['role' => 'system', 'content' => str_repeat('s', $chars)],
            ['role' => 'user', 'content' => str_repeat('u', $chars)],
        ];
    }

    /** @test */
    public function it_records_stage_timings_data_errors_and_usage_totals(): void
    {
        $trace = new MessageTraceObserver(capturePrompts: false);
        $this->assertInstanceOf(PipelineObserverInterface::class, $trace);

        $trace->stageStarted('understanding');
        $trace->promptBuilt('understanding', $this->prompt(10));
        $trace->usage('understanding', ['input' => 100, 'output' => 20, 'total' => 120, 'model' => 'mini']);
        $trace->stageFinished('understanding', ['qu' => ['question_type' => 'data_query']], 812.4);
        $trace->stageStarted('execution');
        $trace->stageError('execution', new \RuntimeException('Neo4j SyntaxError'));
        $trace->stageFinished('execution', ['row_count' => 0, 'success' => false], 5.0);
        $trace->usage('composition', ['input' => 300, 'output' => 50, 'total' => 350, 'model' => 'big']);
        $trace->usage('composition', ['input' => 10, 'output' => 5, 'total' => 15, 'model' => 'big']);

        $out = $trace->toArray();

        $this->assertSame(1, $out['version']);
        $this->assertSame(['understanding', 'execution'], array_column($out['stages'], 'stage'));
        $this->assertSame(812.4, $out['stages'][0]['ms']);
        $this->assertSame('data_query', $out['stages'][0]['data']['qu']['question_type']);
        $this->assertSame(
            [['stage' => 'execution', 'class' => \RuntimeException::class, 'message' => 'Neo4j SyntaxError']],
            $out['errors']
        );
        $this->assertSame(['input' => 100, 'output' => 20, 'total' => 120, 'calls' => 1], $out['usage']['by_stage']['understanding']);
        $this->assertSame(['input' => 310, 'output' => 55, 'total' => 365, 'calls' => 2], $out['usage']['by_stage']['composition']);
        $this->assertSame(['input' => 410, 'output' => 75, 'total' => 485, 'calls' => 3], $out['usage']['total']);
        $this->assertSame(['mini', 'big'], $out['usage']['models']);
        $this->assertArrayHasKey('total_ms', $out);
        $this->assertArrayNotHasKey('prompts', $out, 'prompts are never persisted unless capture_prompts');
    }

    /** @test */
    public function prompts_are_kept_only_when_capture_is_enabled(): void
    {
        $trace = new MessageTraceObserver(capturePrompts: true);
        $trace->promptBuilt('composition', $this->prompt(5));

        $this->assertSame([$this->prompt(5)], $trace->toArray()['prompts']['composition']);
    }

    /** @test */
    public function an_oversized_trace_is_shrunk_prompts_first(): void
    {
        $trace = new MessageTraceObserver(capturePrompts: true, maxBytes: 20000);
        $trace->stageFinished('understanding', ['qu' => ['search_query' => 'units']], 3.0);
        $trace->promptBuilt('understanding', $this->prompt(30000));
        $trace->promptBuilt('composition', $this->prompt(30000));

        $out = $trace->toArray();
        $bytes = strlen(json_encode($out));

        $this->assertLessThanOrEqual(20000, $bytes);
        $this->assertContains('prompts', $out['truncated']);
        // Stage data survives when dropping/cutting prompts was enough.
        $this->assertSame('units', $out['stages'][0]['data']['qu']['search_query']);
    }

    /** @test */
    public function after_prompts_long_data_samples_are_cut_then_stage_data_dropped(): void
    {
        $trace = new MessageTraceObserver(capturePrompts: false, maxBytes: 4000);
        $trace->stageFinished('file_context', [
            'angles' => array_fill(0, 200, str_repeat('a', 100)),
        ], 1.0);
        $trace->stageFinished('composition', ['branch' => 'data', 'answer_length' => 10], 2.0);

        $out = $trace->toArray();

        $this->assertLessThanOrEqual(4000, strlen(json_encode($out)));
        $this->assertNotContains('prompts', $out['truncated']);
        $this->assertContains('data_samples', $out['truncated']);
        $this->assertSame(['file_context', 'composition'], array_column($out['stages'], 'stage'), 'stage timeline always survives');
        $this->assertSame(2.0, $out['stages'][1]['ms']);
    }

    /** @test */
    public function the_persisted_cypher_has_its_literals_masked(): void
    {
        $trace = new MessageTraceObserver();
        $trace->stageFinished('query_generation', [
            'cypher' => "MATCH (u:User {email: 'ann@example.com'})-[:IN]->(t:`Team`) WHERE u.age > 42 AND t.id IN \$ai_team_ids RETURN u.name, \"x\" AS k LIMIT 10",
            'attempts' => 1,
        ], 3.0);

        $cypher = $trace->toArray()['stages'][0]['data']['cypher'];

        $this->assertSame(
            'MATCH (u:User {email: ?})-[:IN]->(t:`Team`) WHERE u.age > ? AND t.id IN $ai_team_ids RETURN u.name, ? AS k LIMIT ?',
            $cypher
        );
    }

    /** @test */
    public function literal_redaction_can_be_turned_off(): void
    {
        $cypher = "MATCH (u:User {email: 'ann@example.com'}) RETURN u LIMIT 10";
        $trace = new MessageTraceObserver(redactLiterals: false);
        $trace->stageFinished('query_generation', ['cypher' => $cypher], 3.0);

        $this->assertSame($cypher, $trace->toArray()['stages'][0]['data']['cypher']);
    }

    /** @test */
    public function an_unlexable_cypher_is_still_masked(): void
    {
        $trace = new MessageTraceObserver();
        $trace->stageFinished('query_generation', ['cypher' => "MATCH (u {name: 'Ann}) RETURN u LIMIT 5"], 1.0);

        $cypher = $trace->toArray()['stages'][0]['data']['cypher'];
        $this->assertStringNotContainsString('Ann', $cypher);
        $this->assertStringNotContainsString('5', $cypher);
    }

    /** @test */
    public function the_persisted_file_context_keeps_references_but_never_snippet_text(): void
    {
        $trace = new MessageTraceObserver();
        $trace->stageFinished('file_context', ['file_count' => 1, 'files' => [
            ['id' => 9, 'chunk_index' => 2, 'relevance' => 0.7, 'source' => 'database', 'snippet' => 'Confidential clause 12'],
        ]], 10.0);
        $trace->stageFinished('export', ['export_source' => 'current_result', 'rows' => 1, 'sample' => [['email' => 'secret@x.com']]], 1.0);
        $trace->stageFinished('understanding', ['fallbacks' => [
            ['service' => 'semantic_selector', 'from' => 'vector_search', 'to' => 'keyword_matching', 'reason' => str_repeat('x', 400)],
        ]], 1.0);

        $out = $trace->toArray();

        $file = $out['stages'][0]['data']['files'][0];
        $this->assertSame(['id' => 9, 'chunk_index' => 2, 'relevance' => 0.7, 'source' => 'database'], $file);
        $this->assertStringNotContainsString('Confidential', json_encode($out));
        $this->assertSame(300, mb_strlen($out['stages'][2]['data']['fallbacks'][0]['reason']), 'fallback reasons are capped like errors');
        $this->assertSame(['export_source' => 'current_result', 'rows' => 1], $out['stages'][1]['data'], 'exported rows are never persisted');
        $this->assertStringNotContainsString('secret@x.com', json_encode($out));
    }

    /** @test */
    public function the_understanding_trace_keeps_only_whitelisted_qu_fields(): void
    {
        $trace = new MessageTraceObserver();
        $trace->stageFinished('understanding', [
            'qu' => [
                'question_type' => 'data_query', 'intent' => 'lookup', 'entity_types' => ['Person'],
                'language' => 'fr', 'search_query' => 'solde', 'follow_up_reference' => false,
                'digest_request' => false, 'export_format' => null, 'needs_clarification' => 1,
                'is_possessive' => true,
                'person_names' => ['Ann Smith'], 'clarified_question' => 'What is Ann Smith balance?',
                'clarifying_question' => 'Which Ann?', 'candidate_readings' => ['Ann A', 'Ann B'],
                'corpus_translations' => ['balance'], 'register_expansions' => ['x'], 'search_tags' => ['y'],
                'property_concepts' => ['z'],
            ],
            'selected_entities' => ['Person'],
        ], 10.0);

        $qu = $trace->toArray()['stages'][0]['data']['qu'];

        $this->assertSame([
            'question_type', 'intent', 'entity_types', 'language', 'search_query', 'follow_up_reference',
            'digest_request', 'export_format', 'needs_clarification', 'is_possessive',
        ], array_keys($qu));
        $this->assertTrue($qu['needs_clarification']);
        $this->assertStringNotContainsString('Ann', json_encode($trace->toArray()));
    }

    /** @test */
    public function error_messages_are_capped_at_300_chars_and_literal_masked(): void
    {
        $trace = new MessageTraceObserver();
        $trace->stageError('execution', new \RuntimeException("Invalid input 'ann@example.com' at 1234: " . str_repeat('x', 1000)));
        $trace->stageFinished('execution', ['errors' => ["Unknown value 'secret-token' in query"]], 1.0);

        $out = $trace->toArray();
        $message = $out['errors'][0]['message'];

        $this->assertLessThanOrEqual(300, mb_strlen($message));
        $this->assertStringNotContainsString('ann@example.com', $message);
        $this->assertStringNotContainsString('1234', $message);
        $this->assertStringNotContainsString('secret-token', json_encode($out));
    }

    /** @test */
    public function french_text_and_many_stages_always_fit_the_cap_as_eloquent_encodes_it(): void
    {
        $max = 4096;
        $trace = new MessageTraceObserver(capturePrompts: true, maxBytes: $max);
        for ($i = 0; $i < 400; $i++) {
            $trace->stageFinished("étape_{$i}", ['note' => str_repeat('é', 40), 'réponse' => 'Voilà les unités — détaillées'], 1.5);
            $trace->promptBuilt('composition', [['role' => 'user', 'content' => str_repeat('à', 300)]]);
        }
        for ($i = 0; $i < 12; $i++) {
            $trace->stageError("étape_{$i}", new \RuntimeException("échec numéro {$i}"));
        }
        $trace->usage('composition', ['input' => 5, 'output' => 5, 'total' => 10, 'model' => 'm']);

        $out = $trace->toArray();

        // The Eloquent `array` cast uses plain json_encode (unicode ESCAPED).
        $this->assertLessThanOrEqual($max, strlen(json_encode($out)));
        $this->assertSame(1, $out['version']);
        $this->assertContains('skeleton', $out['truncated']);
        $this->assertLessThanOrEqual(5, count($out['errors']));
        $this->assertSame(10, $out['usage']['total']['total']);
    }

    /** @test */
    public function the_skeleton_keeps_stage_names_timings_usage_and_the_first_errors(): void
    {
        $max = 4096;
        $trace = new MessageTraceObserver(maxBytes: $max);
        foreach (['understanding', 'file_context', 'composition'] as $stage) {
            $trace->stageFinished($stage, ['blob' => array_fill(0, 30, str_repeat('ü', 100))], 2.5);
        }
        for ($i = 0; $i < 8; $i++) {
            $trace->stageError('composition', new \RuntimeException(str_repeat('ö', 250)));
        }

        $out = $trace->toArray();

        $this->assertLessThanOrEqual($max, strlen(json_encode($out)));
        $this->assertSame(['understanding', 'file_context', 'composition'], array_column($out['stages'], 'stage'));
        $this->assertSame(2.5, (float) $out['stages'][2]['ms']);
    }

    /** @test */
    public function the_trace_is_always_json_encodable(): void
    {
        $trace = new MessageTraceObserver();
        $trace->stageFinished('execution', ['errors' => ["bad \xB1 utf8"]], 1.0);
        $trace->stageError('execution', new \RuntimeException("driver \xB1 message"));

        $this->assertNotFalse(json_encode($trace->toArray()));
    }
}
