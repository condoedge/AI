<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Eval p15 decision: the document-search probes ("corpus_translations",
 * "register_expansions") are NO LONGER part of the Query-Understanding call.
 * Their two key docs cost ~530 prompt tokens on every turn (data turns never use
 * them) and the overloaded prompt wrote them badly. They are written by the
 * focused DocumentProbeWriter call instead, only when document search runs.
 *
 * So, whatever the host's corpus configuration: the QU prompt asks for neither
 * key, and a model that volunteers them is ignored (the probe slots of the QU
 * result stay empty until the probe writer fills them).
 */
class QueryUnderstandingNoProbeKeysTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function logger(): AiLogger
    {
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldIgnoreMissing();
        $logger->shouldReceive('stopTimer')->andReturn(0.0);

        return $logger;
    }

    /**
     * Host corpus configuration as the service provider used to surface it into
     * the QU config — it must have no effect on the QU prompt any more.
     */
    private const CORPUS_CONFIG = [
        'corpus_languages' => ['fr', 'en'],
        'corpus_register' => 'aviation maintenance regulation terminology (airworthiness directive)',
    ];

    /** @test */
    public function the_qu_prompt_asks_for_neither_probe_key(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use (&$captured) {
            $captured = $messages;

            return ['search_query' => 'x'];
        });

        (new QueryUnderstandingService($llm, $this->logger(), self::CORPUS_CONFIG))->analyze('some question', []);

        $system = (string) ($captured[0]['content'] ?? '');
        $this->assertStringNotContainsString('corpus_translations', $system);
        $this->assertStringNotContainsString('register_expansions', $system);
        $this->assertStringNotContainsString('airworthiness directive', $system, 'the corpus register is not QU input');
        // The neighbouring keys are untouched and still adjacent.
        $this->assertMatchesRegularExpression('/- "search_tags":[^\n]*\n- "export_format":/', $system);
    }

    /** @test */
    public function probe_keys_a_model_volunteers_are_ignored(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'search_query' => 'x',
            'question_type' => 'knowledge_query',
            'corpus_translations' => ['fr' => 'une traduction', 'en' => 'a translation'],
            'register_expansions' => ['a volunteered reformulation'],
        ]);

        $result = (new QueryUnderstandingService($llm, $this->logger(), self::CORPUS_CONFIG))->analyze('some question', []);

        $this->assertSame([], $result->corpusTranslations);
        $this->assertSame([], $result->registerExpansions);
        $this->assertSame('knowledge_query', $result->questionType, 'the rest of the reply is parsed as before');
    }
}
