<?php
// tests/Unit/Services/Cache/QueryResultCacheTest.php

namespace Condoedge\Ai\Tests\Unit\Services\Cache;

use Condoedge\Ai\Services\Cache\QueryResultCache;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class QueryResultCacheTest extends TestCase
{
    /** @test */
    public function it_caches_context_results(): void
    {
        $cache = new QueryResultCache();

        $context = ['similar_queries' => [['question' => 'test']]];
        $cache->cacheContext('How many customers?', $context);

        $cached = $cache->getContext('How many customers?');

        $this->assertNotNull($cached);
        $this->assertEquals($context, $cached);
    }

    /** @test */
    public function it_returns_null_for_uncached_questions(): void
    {
        $cache = new QueryResultCache();

        $cached = $cache->getContext('Never asked before');

        $this->assertNull($cached);
    }

    /** @test */
    public function it_normalizes_questions_for_cache_key(): void
    {
        $cache = new QueryResultCache();

        $context = ['test' => true];
        $cache->cacheContext('How many customers?', $context);

        // Same question with different casing/spacing should hit cache
        $cached = $cache->getContext('how many customers?');

        $this->assertNotNull($cached);
    }
}
