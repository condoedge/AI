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
 * D2a — cross-lingual retrieval (Query-Understanding side). When corpus_languages
 * are configured, the QU layer (which already calls the LLM once) must:
 *   - instruct the model to translate the question into each corpus language, and
 *   - parse the returned per-language translations onto
 *     QueryUnderstandingResult::corpusTranslations.
 * With no configured corpus languages the prompt and result are unchanged (zero
 * added cost). A missing/malformed translation field degrades silently to none.
 */
class QueryUnderstandingCorpusTranslationTest extends TestCase
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

    /** @test */
    public function it_parses_corpus_translations_when_languages_configured(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'entity_types' => [],
            'person_names' => [],
            'property_concepts' => [],
            'search_query' => 'tear down wall Quebec rules',
            'is_possessive' => false,
            'intent' => 'lookup',
            'question_type' => 'knowledge_query',
            'clarified_question' => '',
            'search_tags' => [],
            'corpus_translations' => [
                'fr' => 'Puis-je démolir un mur en tenant compte des règles du Québec?',
                'en' => 'Can I tear down a wall keeping in mind the rules of Quebec?',
            ],
        ]);

        $service = new QueryUnderstandingService($llm, $this->logger(), [
            'corpus_languages' => ['fr', 'en'],
        ]);

        $result = $service->analyze('Can i tear down a wall keeping in mind rules of quebec?', []);

        $this->assertSame(
            'Puis-je démolir un mur en tenant compte des règles du Québec?',
            $result->corpusTranslations['fr'] ?? null
        );
        $this->assertSame(
            'Can I tear down a wall keeping in mind the rules of Quebec?',
            $result->corpusTranslations['en'] ?? null
        );
    }

    /** @test */
    public function it_instructs_the_model_to_translate_into_each_configured_language(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages, $options) use (&$captured) {
            $captured = $messages;
            return ['search_query' => 'x'];
        });

        $service = new QueryUnderstandingService($llm, $this->logger(), [
            'corpus_languages' => ['fr', 'en'],
        ]);

        $service->analyze('some question', []);

        $system = $captured[0]['content'] ?? '';
        // The configured language codes must appear in the prompt and a
        // corpus_translations key must be requested.
        $this->assertStringContainsString('corpus_translations', $system);
        $this->assertStringContainsString('fr', $system);
        $this->assertStringContainsString('en', $system);
    }

    /** @test */
    public function it_emits_no_translations_and_no_instruction_when_no_languages_configured(): void
    {
        $captured = null;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages, $options) use (&$captured) {
            $captured = $messages;
            return [
                'search_query' => 'x',
                // Even if the model volunteered translations, none were requested,
                // so the configured (empty) language set yields no angles.
                'corpus_translations' => ['de' => 'irrelevant'],
            ];
        });

        $service = new QueryUnderstandingService($llm, $this->logger(), [
            'corpus_languages' => [],
        ]);

        $result = $service->analyze('some question', []);

        $system = $captured[0]['content'] ?? '';
        $this->assertStringNotContainsString('corpus_translations', $system);
        $this->assertSame([], $result->corpusTranslations);
    }

    /** @test */
    public function it_parses_corpus_translations_returned_as_a_json_object(): void
    {
        // chatJson commonly decodes nested JSON objects to stdClass, not arrays.
        // The parser must coerce an object map the same as an associative array.
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn((object) [
            'search_query' => 'x',
            'corpus_translations' => (object) [
                'fr' => 'Puis-je abattre un mur?',
                'en' => 'Can I tear down a wall?',
            ],
        ]);

        $service = new QueryUnderstandingService($llm, $this->logger(), [
            'corpus_languages' => ['fr', 'en'],
        ]);

        $result = $service->analyze('some question', []);

        $this->assertSame('Puis-je abattre un mur?', $result->corpusTranslations['fr'] ?? null);
        $this->assertSame('Can I tear down a wall?', $result->corpusTranslations['en'] ?? null);
    }

    /** @test */
    public function it_degrades_silently_when_translation_field_is_missing_or_malformed(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'search_query' => 'x',
            // Malformed: not a string->string map.
            'corpus_translations' => 'not-a-map',
        ]);

        $service = new QueryUnderstandingService($llm, $this->logger(), [
            'corpus_languages' => ['fr', 'en'],
        ]);

        $result = $service->analyze('some question', []);

        $this->assertSame([], $result->corpusTranslations);
    }

    /** @test */
    public function it_keeps_only_configured_languages_and_drops_empty_values(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'search_query' => 'x',
            'corpus_translations' => [
                'fr' => 'Question en français',
                'en' => '   ', // blank -> dropped
                'de' => 'Nicht konfiguriert', // not configured -> dropped
            ],
        ]);

        $service = new QueryUnderstandingService($llm, $this->logger(), [
            'corpus_languages' => ['fr', 'en'],
        ]);

        $result = $service->analyze('some question', []);

        $this->assertSame(['fr' => 'Question en français'], $result->corpusTranslations);
    }
}
