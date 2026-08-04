<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\DTOs\Eval;

use Condoedge\Ai\DTOs\Eval\EvalRow;
use PHPUnit\Framework\TestCase;

/**
 * The trace is the eval's whole value to an out-of-process grading agent: it must
 * carry everything needed to grade a turn WITHOUT re-running it (question, standard,
 * qu output, retrieved chunks, cypher, verbatim prompts, answer, timings, gates).
 */
class EvalRowTraceTest extends TestCase
{
    /** @test */
    public function to_array_carries_the_full_trace_for_out_of_process_grading(): void
    {
        $row = new EvalRow(question: 'Now give me that as an Excel.', tags: ['suite']);
        $row->conversationId = 'team-roster-export';
        $row->turnIndex = 1;
        $row->role = 'manager';
        $row->standard = 'Produces an Excel of the SAME members from the previous turn.';
        $row->expect = ['produces_file' => 'excel'];
        $row->understanding = ['question_type' => 'data_query', 'intent' => 'list'];
        $row->retrievedChunks = [['id' => 'f1', 'score' => 0.91, 'snippet' => 'Team roster...']];
        $row->cypher = 'MATCH (u:User) RETURN u';
        $row->prompts = [
            'query_generation' => ['system' => 'QG-SYS', 'user' => 'QG-USR'],
            'response_generation' => ['system' => 'RG-SYS', 'user' => 'RG-USR'],
        ];
        $row->answer = 'I prepared the Excel file.';
        $row->referencedFiles = [['id' => 3]];
        $row->responseMetadata = ['export_intent' => true, 'export_format' => 'excel'];
        $row->conversationContextMeta = ['last_result_sample_count' => 5];
        $row->timings = ['retrieval' => 10, 'prompt' => 20];
        $row->totalDurationMs = 42;
        $row->gates = [['gate' => 'produces_file', 'expected' => 'excel', 'actual' => 'excel', 'passed' => true, 'detail' => '']];

        $array = $row->toArray();

        // Gate results live top-level, next to the verdict.
        $this->assertTrue($array['gates'][0]['passed']);

        $trace = $array['trace'];
        $this->assertSame('team-roster-export', $trace['conversation_id']);
        $this->assertSame(1, $trace['turn_index']);
        $this->assertSame('manager', $trace['role']);
        $this->assertSame('Produces an Excel of the SAME members from the previous turn.', $trace['standard']);
        $this->assertSame(['produces_file' => 'excel'], $trace['expect']);
        $this->assertSame('data_query', $trace['understanding']['question_type']);
        $this->assertSame('f1', $trace['retrieved_chunks'][0]['id']);
        $this->assertSame('MATCH (u:User) RETURN u', $trace['cypher']);
        $this->assertSame('QG-SYS', $trace['prompts']['query_generation']['system']);
        $this->assertSame('RG-USR', $trace['prompts']['response_generation']['user']);
        $this->assertSame('I prepared the Excel file.', $trace['answer']);
        $this->assertSame([['id' => 3]], $trace['referenced_files']);
        $this->assertTrue($trace['response_metadata']['export_intent']);
        $this->assertSame(5, $trace['conversation_context_received']['last_result_sample_count']);
        $this->assertSame(['retrieval' => 10, 'prompt' => 20], $trace['timings_ms']);
        $this->assertSame(42, $trace['total_duration_ms']);
    }

    /** @test */
    public function prompts_default_empty_and_execution_data_is_never_serialized(): void
    {
        $row = new EvalRow(question: 'How many units?');
        $row->executionData = [['secret_bulk' => 'row'], ['secret_bulk' => 'row2']];

        $array = $row->toArray();

        // No capture flag → no prompts ballooning the report.
        $this->assertSame([], $array['trace']['prompts']);

        // executionData is a transient carrier for the production conversation
        // write-back (recordResponse) — the report only ever carries the sample.
        $json = json_encode($array);
        $this->assertStringNotContainsString('secret_bulk', $json);
    }
}
