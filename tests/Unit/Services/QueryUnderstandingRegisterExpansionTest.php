<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Lay->domain-register query expansion (QU side). When a corpus register is
 * configured (ai.file_context.corpus_register, surfaced into the QU config),
 * the SAME single QU LLM call must:
 *   - instruct the model to emit "register_expansions": 1-3 short reformulations
 *     of the question in the corpus register's terminology, and
 *   - parse them onto QueryUnderstandingResult::registerExpansions.
 * With no configured register the prompt and result are unchanged (zero added
 * cost). Malformed/volunteered values degrade silently to none. The register
 * TEXT lives in host config — the package never hardcodes domain vocabulary.
 */
class QueryUnderstandingRegisterExpansionTest extends TestCase
{
    /**
     * A deliberately NON-host register (aviation, not the CondoEdge legal
     * corpus): the package must prove the feature is white-label — any host
     * register flows through, none is baked in.
     */
    private const REGISTER = 'aviation maintenance regulation terminology '
        . '(airworthiness directive, minimum equipment list, line-replaceable '
        . 'unit, scheduled maintenance check)';

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

    /** @test */
    public function it_parses_register_expansions_when_a_corpus_register_is_configured(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'search_query' => 'replace cockpit part rules',
            'question_type' => 'knowledge_query',
            'register_expansions' => [
                'airworthiness directive compliance for replacement of a line-replaceable unit',
                'minimum equipment list dispatch conditions for an inoperative component',
            ],
        ]);

        $service = new QueryUnderstandingService($llm, $this->logger(), [
            'corpus_register' => self::REGISTER,
        ]);

        $result = $service->analyze('Can I just swap out this broken part myself?', []);

        $this->assertSame(
            [
                'airworthiness directive compliance for replacement of a line-replaceable unit',
                'minimum equipment list dispatch conditions for an inoperative component',
            ],
            $result->registerExpansions
        );
    }

    /** @test */
    public function it_instructs_the_model_with_the_configured_register_text(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages, $options) use (&$captured) {
            $captured = $messages;
            return ['search_query' => 'x'];
        });

        $service = new QueryUnderstandingService($llm, $this->logger(), [
            'corpus_register' => self::REGISTER,
        ]);

        $service->analyze('some question', []);

        $system = $captured[0]['content'] ?? '';
        $this->assertStringContainsString('register_expansions', $system);
        // The register description is the HOST's text, passed through verbatim.
        $this->assertStringContainsString('airworthiness directive', $system);
    }

    /** @test */
    public function it_emits_no_expansions_and_no_instruction_when_no_register_configured(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages, $options) use (&$captured) {
            $captured = $messages;
            return [
                'search_query' => 'x',
                // Even if the model volunteers expansions, none were requested.
                'register_expansions' => ['volunteered domain phrasing'],
            ];
        });

        $service = new QueryUnderstandingService($llm, $this->logger(), []);

        $result = $service->analyze('some question', []);

        $system = $captured[0]['content'] ?? '';
        $this->assertStringNotContainsString('register_expansions', $system);
        $this->assertSame([], $result->registerExpansions);
    }

    /** @test */
    public function it_degrades_silently_when_the_field_is_missing_or_malformed(): void
    {
        foreach (['not-an-array', null, 42, ['ok' => ['nested']]] as $malformed) {
            $llm = Mockery::mock(LlmProviderInterface::class);
            $llm->shouldReceive('chatJson')->once()->andReturn([
                'search_query' => 'x',
                'register_expansions' => $malformed,
            ]);

            $service = new QueryUnderstandingService($llm, $this->logger(), [
                'corpus_register' => self::REGISTER,
            ]);

            $result = $service->analyze('some question', []);

            $this->assertSame([], $result->registerExpansions, 'malformed value must degrade to []');
        }
    }

    /** @test */
    public function it_caps_expansions_at_three_and_drops_blanks_and_duplicates(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'search_query' => 'x',
            'register_expansions' => [
                'first probe',
                '   ',
                'second probe',
                'first probe',
                'third probe',
                'fourth probe',
            ],
        ]);

        $service = new QueryUnderstandingService($llm, $this->logger(), [
            'corpus_register' => self::REGISTER,
        ]);

        $result = $service->analyze('some question', []);

        $this->assertSame(['first probe', 'second probe', 'third probe'], $result->registerExpansions);
    }

    /** @test */
    public function a_blank_configured_register_behaves_as_unconfigured(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages, $options) use (&$captured) {
            $captured = $messages;
            return ['search_query' => 'x', 'register_expansions' => ['x']];
        });

        $service = new QueryUnderstandingService($llm, $this->logger(), [
            'corpus_register' => '   ',
        ]);

        $result = $service->analyze('some question', []);

        $this->assertStringNotContainsString('register_expansions', $captured[0]['content'] ?? '');
        $this->assertSame([], $result->registerExpansions);
    }
}
