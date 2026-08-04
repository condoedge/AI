<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Export-intent detection lives in the QU LLM call (the SAME single call, like
 * register_expansions) — NOT in a hardcoded verb/phrase regex. The live failure
 * this pins: "Can i get an excel with all the members from my current team?"
 * was missed by the deterministic phrase list ("get" absent from the verb list),
 * so the semantic judgment must come from the model:
 *   - the prompt asks for "export_format": "pdf" | "excel" | null, described as
 *     a CONCEPT (receiving the information as a downloadable file) with no
 *     example phrase lists;
 *   - the value is parsed onto QueryUnderstandingResult::exportFormat;
 *   - malformed/absent values degrade to null (fallback regex still applies
 *     downstream);
 *   - the question "language" is parsed alongside for the language policy.
 */
class QueryUnderstandingExportFormatTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function logger(): AiLogger
    {
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        return $logger;
    }

    private function serviceReturning(array $response, ?array &$captured = null): QueryUnderstandingService
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use (&$captured, $response) {
            $captured = $messages;
            return $response;
        });

        return new QueryUnderstandingService($llm, $this->logger(), []);
    }

    /** @test */
    public function the_prompt_asks_for_export_format_and_language_without_phrase_lists(): void
    {
        $captured = null;
        $service = $this->serviceReturning(['search_query' => 'x'], $captured);
        $service->analyze('some question', []);

        $system = $captured[0]['content'] ?? '';
        $this->assertStringContainsString('"export_format"', $system);
        $this->assertStringContainsString('"language"', $system);
        // White-label invariant: the instruction describes the CONCEPT — it must
        // not teach the model a phrase list of export verbs.
        $this->assertStringNotContainsString('user may say', $system);
        $this->assertStringNotContainsString('download excel,', strtolower($system));
    }

    /** @test */
    public function it_parses_a_valid_export_format_and_language(): void
    {
        $service = $this->serviceReturning([
            'search_query' => 'team members contact info',
            'export_format' => 'excel',
            'language' => 'en',
        ]);

        $result = $service->analyze('Can i get an excel with all the members from my current team? With contact info', []);

        $this->assertSame('excel', $result->exportFormat);
        $this->assertSame('en', $result->language);
    }

    /** @test */
    public function export_format_is_null_when_absent_or_malformed(): void
    {
        foreach ([null, '', 'csv', 42, ['excel'], 'EXCEL '] as $i => $malformed) {
            $service = $this->serviceReturning([
                'search_query' => 'x',
                'export_format' => $malformed,
            ]);

            $result = $service->analyze('How many residents are there?', []);

            if ($malformed === 'EXCEL ') {
                // Case/whitespace variance is normalized, not rejected.
                $this->assertSame('excel', $result->exportFormat);
            } else {
                $this->assertNull($result->exportFormat, 'value #' . $i . ' must degrade to null');
            }
        }
    }

    /** @test */
    public function language_degrades_to_empty_and_locale_values_reduce_to_the_primary_subtag(): void
    {
        foreach ([
            ['fr', 'fr'],
            ['FR', 'fr'],
            ['en-US', 'en'],
            ['fr_CA', 'fr'],
            ['gibberish-long-value', ''],
            [null, ''],
            [42, ''],
        ] as [$raw, $expected]) {
            $service = $this->serviceReturning([
                'search_query' => 'x',
                'language' => $raw,
            ]);

            $result = $service->analyze('some question', []);

            $this->assertSame($expected, $result->language);
        }
    }

    /** @test */
    public function consumers_tolerate_results_built_without_the_new_fields(): void
    {
        // Back-compatible ctor: every existing construction site (and ::empty())
        // still works, defaulting to "no export ask" and "unknown language".
        $bare = new QueryUnderstandingResult(entityTypes: ['Unit'], isPossessive: true);

        $this->assertNull($bare->exportFormat);
        $this->assertSame('', $bare->language);

        $empty = QueryUnderstandingResult::empty('my units');
        $this->assertNull($empty->exportFormat);
        $this->assertSame('', $empty->language);
    }

    /** @test */
    public function a_failed_qu_call_yields_a_result_with_no_export_format(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andThrow(new \RuntimeException('LLM down'));

        $service = new QueryUnderstandingService($llm, $this->logger(), []);
        $result = $service->analyze('Can I get an excel of the roster?', []);

        // Downstream, AiManager falls back to the deterministic regex.
        $this->assertNull($result->exportFormat);
    }
}
