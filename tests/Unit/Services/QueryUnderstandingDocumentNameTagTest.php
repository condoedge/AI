<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Phase 7.1b — a corpus/document NAME as a search probe ("Quebec Civil Code")
 * matches the title chunk of that document (shared by every passage) instead of
 * the passage that answers; live, the CCQ title chunk won slots on follow-ups via
 * such a tag. The QU prompt carries a STRUCTURAL instruction (never a list of
 * names) for search_tags. (The same rule for the document-search probes lives
 * with their writer: DocumentProbeWriterTest.)
 */
class QueryUnderstandingDocumentNameTagTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function systemPrompt(array $config): string
    {
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer', 'stopTimer', 'info', 'debug', 'error')->andReturn(null, 0.0)->byDefault();

        $captured = null;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use (&$captured) {
            $captured = $messages;
            return ['search_query' => 'x'];
        });

        (new QueryUnderstandingService($llm, $logger, $config))->analyze('some question', []);

        return (string) ($captured[0]['content'] ?? '');
    }

    /** @test */
    public function search_tags_must_not_be_the_name_of_a_document_or_corpus(): void
    {
        $system = $this->systemPrompt([]);

        $this->assertStringContainsString('Never use the name or title of a document, code, statute or corpus', $system);
    }
}
