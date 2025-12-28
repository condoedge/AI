<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * DiagnoseCommand
 *
 * Diagnoses AI package configuration and connectivity.
 * Helps developers quickly identify and fix configuration issues.
 *
 * Usage:
 *   php artisan ai:diagnose
 *
 * @package Condoedge\Ai\Console\Commands
 */
class DiagnoseCommand extends Command
{
    protected $signature = 'ai:diagnose';
    protected $description = 'Diagnose AI package configuration and connectivity';

    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;

    public function handle(): int
    {
        $this->info('AI Package Diagnostic Report');
        $this->info(str_repeat('=', 50));
        $this->newLine();

        // 1. Check configuration files
        $this->checkConfiguration();

        // 2. Check Neo4j connectivity
        $this->checkNeo4j();

        // 3. Check Qdrant connectivity
        $this->checkQdrant();

        // 4. Check LLM API
        $this->checkLlmApi();

        // 5. Check embedding provider
        $this->checkEmbeddingProvider();

        // Summary
        $this->newLine();
        $this->info(str_repeat('=', 50));
        $this->info("Summary: {$this->passed} passed, {$this->failed} failed, {$this->warnings} warnings");

        if ($this->failed > 0) {
            $this->newLine();
            $this->error('Some checks failed. Fix the issues above before using AI features.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All checks passed! AI package is ready to use.');
        return self::SUCCESS;
    }

    private function checkConfiguration(): void
    {
        $this->info('Configuration:');

        // Check entities.php exists
        $entitiesPath = config_path('entities.php');
        if (File::exists($entitiesPath)) {
            $entities = include $entitiesPath;
            $count = is_array($entities) ? count($entities) : 0;
            $this->reportPass("entities.php exists ({$count} entities configured)");
        } else {
            $this->reportFail('entities.php not found - run: php artisan ai:discover');
        }

        // Check ai.php config
        if (config('ai.llm.default')) {
            $this->reportPass('ai.php configured (default LLM: ' . config('ai.llm.default') . ')');
        } else {
            $this->reportFail('ai.php not configured - publish config: php artisan vendor:publish --tag=ai-config');
        }

        $this->newLine();
    }

    private function checkNeo4j(): void
    {
        $this->info('Neo4j:');

        $uri = config('ai.graph.neo4j.uri', 'not set');

        if ($uri === 'not set') {
            $this->reportFail('Neo4j URI not configured');
            $this->newLine();
            return;
        }

        $this->reportPass("URI: {$uri}");

        try {
            $graphStore = app(GraphStoreInterface::class);
            $schema = $graphStore->getSchema();
            $labelCount = count($schema['labels'] ?? []);
            $this->reportPass("Connected ({$labelCount} labels found)");
        } catch (\Exception $e) {
            $this->reportFail('Connection failed: ' . $this->truncateMessage($e->getMessage()));
        }

        $this->newLine();
    }

    private function checkQdrant(): void
    {
        $this->info('Qdrant:');

        $host = config('ai.vector.qdrant.host', 'not set');
        $port = config('ai.vector.qdrant.port', 'not set');

        if ($host === 'not set') {
            $this->reportFail('Qdrant host not configured');
            $this->newLine();
            return;
        }

        $this->reportPass("Host: {$host}:{$port}");

        try {
            $vectorStore = app(VectorStoreInterface::class);
            $collections = $vectorStore->listCollections();
            $collectionCount = is_array($collections) ? count($collections) : 0;
            $this->reportPass("Connected ({$collectionCount} collections found)");
        } catch (\Exception $e) {
            $this->reportFail('Connection failed: ' . $this->truncateMessage($e->getMessage()));
        }

        $this->newLine();
    }

    private function checkLlmApi(): void
    {
        $this->info('LLM API:');

        $provider = config('ai.llm.default');
        $apiKey = config("ai.llm.{$provider}.api_key");

        if (!$provider) {
            $this->reportFail('No LLM provider configured');
            $this->newLine();
            return;
        }

        $this->reportPass("Provider: {$provider}");

        if (!$apiKey) {
            $this->reportFail('API key not set - add to .env: AI_' . strtoupper($provider) . '_API_KEY=xxx');
        } elseif (strlen($apiKey) < 20) {
            $this->reportWarn('API key looks invalid (too short)');
        } else {
            $maskedKey = substr($apiKey, 0, 8) . '...' . substr($apiKey, -4);
            $this->reportPass("API key configured ({$maskedKey})");
        }

        $this->newLine();
    }

    private function checkEmbeddingProvider(): void
    {
        $this->info('Embedding Provider:');

        $provider = config('ai.embedding.default', 'openai');
        $model = config("ai.embedding.{$provider}.model", 'text-embedding-3-small');
        $dimensions = config("ai.embedding.{$provider}.dimensions", 1536);
        $apiKey = config("ai.embedding.{$provider}.api_key");

        $this->reportPass("Provider: {$provider}, Model: {$model}, Dimensions: {$dimensions}");

        if (!$apiKey) {
            $this->reportFail('API key not set - add to .env: AI_' . strtoupper($provider) . '_EMBEDDING_API_KEY=xxx');
        } elseif (strlen($apiKey) < 20) {
            $this->reportWarn('API key looks invalid (too short)');
        } else {
            $maskedKey = substr($apiKey, 0, 8) . '...' . substr($apiKey, -4);
            $this->reportPass("API key configured ({$maskedKey})");
        }

        $this->newLine();
    }

    private function reportPass(string $message): void
    {
        $this->line("  <fg=green>+</> {$message}");
        $this->passed++;
    }

    private function reportFail(string $message): void
    {
        $this->line("  <fg=red>x</> {$message}");
        $this->failed++;
    }

    private function reportWarn(string $message): void
    {
        $this->line("  <fg=yellow>!</> {$message}");
        $this->warnings++;
    }

    /**
     * Truncate error messages to avoid overly long output
     */
    private function truncateMessage(string $message, int $maxLength = 100): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));

        if (strlen($message) > $maxLength) {
            return substr($message, 0, $maxLength) . '...';
        }

        return $message;
    }
}
