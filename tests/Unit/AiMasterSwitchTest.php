<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\AiServiceProvider;
use Condoedge\Ai\Support\AiFeature;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

/**
 * The master switch `ai.enabled` (env AI_ENABLED, default true), 2026-10-28: a host ships
 * the package to production with the assistant OFF — no chat UI, no routes, no automatic
 * graph sync or file processing — and turns it back on by removing one .env line.
 */
class AiMasterSwitchTest extends AiMasterSwitchTestCase
{
    /** @test */
    public function enabled_is_the_default_and_changes_nothing(): void
    {
        $this->assertTrue(AiFeature::enabled());
        $this->assertSame(array_fill_keys(array_keys($this->routes()), true), $this->routes());
        $this->assertTrue((bool) config('ai.auto_sync.enabled'));
        $this->assertTrue((bool) config('ai.file_processing.enabled'));
        $this->assertTrue((bool) config('ai.admin.enabled', true));
    }

    /** @test */
    public function enabled_the_container_hands_out_the_real_clients(): void
    {
        $this->assertInstanceOf(\Condoedge\Ai\VectorStore\QdrantStore::class, app(\Condoedge\Ai\Contracts\VectorStoreInterface::class));
        $this->assertNotInstanceOf(\Condoedge\Ai\GraphStore\DisabledGraphStore::class, app(\Condoedge\Ai\Contracts\GraphStoreInterface::class));
        $this->assertInstanceOf(\Condoedge\Ai\EmbeddingProviders\MemoizedEmbeddingProvider::class, app(\Condoedge\Ai\Contracts\EmbeddingProviderInterface::class));
        $this->assertInstanceOf(\Condoedge\Ai\LlmProviders\OpenAiLlmProvider::class, app(\Condoedge\Ai\Contracts\LlmProviderInterface::class));
    }
}
