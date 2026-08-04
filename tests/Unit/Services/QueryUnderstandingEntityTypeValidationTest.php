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
 * P0-2: QueryUnderstandingService must validate the LLM-returned entity_types
 * against the entity catalog. Downstream, QU entity_types are force-injected into
 * semantic selection at a high score (~0.8), so a hallucinated label would
 * pollute retrieval. Invalid labels are dropped (and logged at debug); valid
 * labels are kept.
 *
 * Drives the real analyze()/normalizeResponse path with a mocked LLM and a real
 * catalog; returns a real QueryUnderstandingResult.
 */
class QueryUnderstandingEntityTypeValidationTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param array $entityTypes entity_types the LLM "returned"
     */
    private function analyzeWithLlmEntityTypes(
        array $entityTypes,
        bool $isPossessive = false,
        string $intent = 'list',
        array $personNames = []
    ): QueryUnderstandingResult
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'entity_types' => $entityTypes,
            'person_names' => $personNames,
            'property_concepts' => [],
            'search_query' => 'rewritten',
            'is_possessive' => $isPossessive,
            'intent' => $intent,
            'question_type' => 'data_query',
            'clarified_question' => '',
            'search_tags' => [],
        ]);

        // Mock the logger so we don't depend on log-channel config, and so we can
        // tolerate (any) info/debug/timer calls the service makes.
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        $service = new QueryUnderstandingService($llm, $logger, []);

        // Catalog built from real entity configs: Contact and Unit are valid labels.
        $catalog = QueryUnderstandingService::buildEntityCatalog([
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => []],
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => []],
        ]);

        return $service->analyze('whatever question', $catalog);
    }

    /** @test */
    public function it_drops_an_entity_type_that_is_not_in_the_catalog(): void
    {
        $result = $this->analyzeWithLlmEntityTypes(['Dragon']);

        $this->assertNotContains('Dragon', $result->entityTypes);
        $this->assertSame([], $result->entityTypes);
    }

    /** @test */
    public function it_keeps_an_entity_type_that_is_in_the_catalog(): void
    {
        $result = $this->analyzeWithLlmEntityTypes(['Contact']);

        $this->assertContains('Contact', $result->entityTypes);
    }

    /** @test */
    public function it_keeps_valid_labels_and_drops_invalid_ones_together(): void
    {
        $result = $this->analyzeWithLlmEntityTypes(['Contact', 'Dragon', 'Unit']);

        $this->assertContains('Contact', $result->entityTypes);
        $this->assertContains('Unit', $result->entityTypes);
        $this->assertNotContains('Dragon', $result->entityTypes);
        $this->assertCount(2, $result->entityTypes);
    }

    /** @test */
    public function possessive_profile_lookup_does_not_force_ambiguous_contact_entity(): void
    {
        $result = $this->analyzeWithLlmEntityTypes(
            ['Contact', 'Unit'],
            isPossessive: true,
            intent: 'lookup'
        );

        $this->assertNotContains('Contact', $result->entityTypes);
        $this->assertContains('Unit', $result->entityTypes);
    }

    /** @test */
    public function possessive_contact_record_lists_keep_the_contact_entity(): void
    {
        $result = $this->analyzeWithLlmEntityTypes(
            ['Contact'],
            isPossessive: true,
            intent: 'list'
        );

        $this->assertContains('Contact', $result->entityTypes);
    }

    /** @test */
    public function it_preserves_the_file_listing_intent_so_enumeration_can_fire(): void
    {
        // The file-enumeration capability keys off qu->intent === 'file_listing'.
        // If normalizeIntent() dropped it to 'other', enumeration would be unreachable.
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'entity_types' => [],
            'person_names' => [],
            'property_concepts' => [],
            'search_query' => 'files I can access',
            'is_possessive' => true,
            'intent' => 'file_listing',
            'question_type' => 'data_query',
            'clarified_question' => '',
            'search_tags' => [],
        ]);
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        $service = new QueryUnderstandingService($llm, $logger, []);
        $result = $service->analyze('Which files do I have access to?', []);

        $this->assertSame('file_listing', $result->intent);
    }

    /** @test */
    public function search_tags_are_capped_at_ten_after_dedupe(): void
    {
        $tags = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n'];
        $result = $this->analyzeWithLlmSearchTags($tags);

        $this->assertLessThanOrEqual(10, count($result->searchTags));
    }

    /** @test */
    public function query_understanding_prompt_is_domain_and_language_agnostic(): void
    {
        // The package must not bake a tenant's domain/language into its own prompt.
        $service = new QueryUnderstandingService(Mockery::mock(LlmProviderInterface::class), Mockery::mock(AiLogger::class), []);
        $ref = new \ReflectionMethod($service, 'buildMessages');
        $ref->setAccessible(true);
        $messages = $ref->invoke($service, 'any question', []);
        $system = $messages[0]['content'] ?? '';

        $this->assertStringNotContainsStringIgnoringCase('copropriété', $system);
        $this->assertStringNotContainsStringIgnoringCase('co-ownership', $system);
        $this->assertStringNotContainsString('EXACTLY 10', $system);
    }

    private function analyzeWithLlmSearchTags(array $tags): QueryUnderstandingResult
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'entity_types' => [], 'person_names' => [], 'property_concepts' => [],
            'search_query' => 'x', 'is_possessive' => false, 'intent' => 'list',
            'question_type' => 'data_query', 'clarified_question' => '', 'search_tags' => $tags,
        ]);
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        return (new QueryUnderstandingService($llm, $logger, []))->analyze('q', []);
    }
}
