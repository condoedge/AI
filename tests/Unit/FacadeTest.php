<?php

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Facades\FileSearch;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Tests\TestCase;

/**
 * Facade hygiene tests.
 *
 * Guards two things:
 *  - each facade resolves to the expected underlying service via its accessor;
 *  - every method advertised in a facade's `@method` docblock actually exists on
 *    the underlying class (so the docblock can never silently drift / lie again).
 */
class FacadeTest extends TestCase
{
    /**
     * Provide dummy provider keys so the `ai` binding (which eagerly constructs the
     * embedding/LLM providers) resolves without any network access — the providers
     * only validate that a key string is present at construction time.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-key');
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-key');
    }

    /** @test */
    public function ai_facade_accessor_resolves_to_ai_manager(): void
    {
        $this->assertSame('ai', $this->facadeAccessor(AI::class));
        $this->assertInstanceOf(AiManager::class, app('ai'));
    }

    /** @test */
    public function file_search_facade_accessor_resolves_to_file_search_service(): void
    {
        $this->assertSame('file-search', $this->facadeAccessor(FileSearch::class));
        $this->assertInstanceOf(FileSearchService::class, app('file-search'));
    }

    /** @test */
    public function ai_facade_advertises_real_methods(): void
    {
        // Spot-check a few core proxied methods explicitly...
        foreach (['ingest', 'retrieveContext', 'answerQuestion', 'generateQuery'] as $method) {
            $this->assertTrue(
                method_exists(AiManager::class, $method),
                "AiManager is missing advertised method [{$method}]."
            );
        }

        // ...then assert the WHOLE @method block matches the real surface.
        $this->assertDocblockMethodsExist(AI::class, AiManager::class);
    }

    /** @test */
    public function file_search_facade_advertises_real_methods(): void
    {
        foreach (['searchByContent', 'searchByMetadata', 'hybridSearch'] as $method) {
            $this->assertTrue(
                method_exists(FileSearchService::class, $method),
                "FileSearchService is missing advertised method [{$method}]."
            );
        }

        $this->assertDocblockMethodsExist(FileSearch::class, FileSearchService::class);
    }

    /**
     * Every `@method static <return> <name>(...)` in the facade's docblock must
     * map to a real public method on the underlying class.
     */
    private function assertDocblockMethodsExist(string $facade, string $target): void
    {
        $doc = (new \ReflectionClass($facade))->getDocComment() ?: '';

        preg_match_all('/@method\s+static\s+\S+\s+(\w+)\s*\(/', $doc, $matches);
        $advertised = $matches[1];

        $this->assertNotEmpty($advertised, "No @method entries found on {$facade}.");

        foreach ($advertised as $method) {
            $this->assertTrue(
                method_exists($target, $method),
                "{$facade} advertises [{$method}] but {$target} has no such method."
            );

            $reflected = new \ReflectionMethod($target, $method);
            $this->assertTrue(
                $reflected->isPublic(),
                "{$facade} advertises [{$method}] but {$target}::{$method} is not public."
            );
        }
    }

    private function facadeAccessor(string $facade): string
    {
        $method = new \ReflectionMethod($facade, 'getFacadeAccessor');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
