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
abstract class AiMasterSwitchTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \Kompo\KompoServiceProvider::class,
            AiServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
    }

    /** @return array<string, bool> */
    protected function routes(): array
    {
        Route::getRoutes()->refreshNameLookups();

        return [
            'ai.chat.screen' => Route::has('ai.chat.screen'),
            'ai.export-chat' => Route::has('ai.export-chat'),
            'ai.admin.conversations' => Route::has('ai.admin.conversations'),
            'api/ai/health' => collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => $r->uri() === 'api/ai/health'),
        ];
    }
}
