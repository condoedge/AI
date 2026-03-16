<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\LlmProviders\OllamaLlmProvider;
use Condoedge\Ai\EmbeddingProviders\OllamaEmbeddingProvider;
use Illuminate\Console\Command;

class OllamaSetupCommand extends Command
{
    protected $signature = 'ai:ollama-setup
        {--check : Only check connectivity and model availability, do not pull models}';

    protected $description = 'Setup and verify Ollama for local AI. Checks connectivity, pulls models, and tests LLM + embeddings.';

    public function handle(): int
    {
        $this->info('Ollama Setup');
        $this->line('');

        $baseUrl = config('ai.llm.ollama.base_url', 'http://localhost:11434');
        $llmModel = config('ai.llm.ollama.model', 'llama3.1:8b');
        $embeddingModel = config('ai.embedding.ollama.model', 'nomic-embed-text');

        // 1. Check connectivity
        $this->info('1. Checking Ollama connectivity...');

        if (!$this->checkConnectivity($baseUrl)) {
            $this->error("Cannot connect to Ollama at {$baseUrl}");
            $this->line('  Start Ollama with: ollama serve');
            return 1;
        }

        $this->info("  Connected to Ollama at {$baseUrl}");

        // 2. List available models
        $this->info('2. Checking available models...');
        $availableModels = $this->getAvailableModels($baseUrl);

        if (empty($availableModels)) {
            $this->warn('  No models found.');
        } else {
            foreach ($availableModels as $model) {
                $this->line("  - {$model}");
            }
        }

        // 3. Check/pull LLM model
        $this->info("3. LLM model: {$llmModel}");
        $llmAvailable = in_array($llmModel, $availableModels);

        if (!$llmAvailable) {
            if ($this->option('check')) {
                $this->warn("  Model not found. Pull it with: ollama pull {$llmModel}");
            } else {
                $this->line("  Pulling {$llmModel}... (this may take a while)");
                $result = $this->pullModel($baseUrl, $llmModel);
                if (!$result) {
                    $this->error("  Failed to pull {$llmModel}");
                    return 1;
                }
                $this->info("  Pulled {$llmModel}");
            }
        } else {
            $this->info("  Model available");
        }

        // 4. Check/pull embedding model
        $this->info("4. Embedding model: {$embeddingModel}");
        $embeddingAvailable = in_array($embeddingModel, $availableModels);

        if (!$embeddingAvailable) {
            if ($this->option('check')) {
                $this->warn("  Model not found. Pull it with: ollama pull {$embeddingModel}");
            } else {
                $this->line("  Pulling {$embeddingModel}...");
                $result = $this->pullModel($baseUrl, $embeddingModel);
                if (!$result) {
                    $this->error("  Failed to pull {$embeddingModel}");
                    return 1;
                }
                $this->info("  Pulled {$embeddingModel}");
            }
        } else {
            $this->info("  Model available");
        }

        // 5. Test LLM
        if (!$this->option('check') || ($llmAvailable && $embeddingAvailable)) {
            $this->info('5. Testing LLM...');
            try {
                $llm = new OllamaLlmProvider(config('ai.llm.ollama', []));
                $response = $llm->chat([
                    ['role' => 'user', 'content' => 'Say "hello" in one word.'],
                ], ['max_tokens' => 10]);

                $this->info("  LLM response: {$response}");
            } catch (\Exception $e) {
                $this->error("  LLM test failed: {$e->getMessage()}");
                if (!$this->option('check')) {
                    return 1;
                }
            }

            // 6. Test embeddings
            $this->info('6. Testing embeddings...');
            try {
                $embedder = new OllamaEmbeddingProvider(config('ai.embedding.ollama', []));
                $embedding = $embedder->embed('test');
                $dims = count($embedding);

                $this->info("  Embedding dimensions: {$dims}");

                // Check dimension compatibility
                $qdrantDims = config('ai.semantic_context.dimension', 1536);
                $configDims = config('ai.embedding.ollama.dimensions', 768);

                if ($dims !== $configDims) {
                    $this->warn("  Actual dimensions ({$dims}) differ from configured ({$configDims}). Update AI_OLLAMA_EMBEDDING_DIMENSIONS={$dims}");
                }

                if ($dims !== $qdrantDims) {
                    $this->warn("  Ollama embeddings ({$dims}d) differ from current Qdrant collection ({$qdrantDims}d).");
                    $this->warn('  You may need to recreate Qdrant collections with the new dimension size.');
                }
            } catch (\Exception $e) {
                $this->error("  Embedding test failed: {$e->getMessage()}");
                if (!$this->option('check')) {
                    return 1;
                }
            }
        }

        // 7. Configuration summary
        $this->line('');
        $this->info('Configuration for .env:');
        $this->line('  AI_LLM_PROVIDER=ollama');
        $this->line('  AI_EMBEDDING_PROVIDER=ollama');
        $this->line("  OLLAMA_BASE_URL={$baseUrl}");
        $this->line("  OLLAMA_MODEL={$llmModel}");
        $this->line("  OLLAMA_EMBEDDING_MODEL={$embeddingModel}");
        $this->line('  AI_TOOLS_ENABLED=true');
        $this->line('');
        $this->info('Setup complete.');

        return 0;
    }

    private function checkConnectivity(string $baseUrl): bool
    {
        $ch = curl_init($baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && $httpCode === 200;
    }

    private function getAvailableModels(string $baseUrl): array
    {
        $ch = curl_init($baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return [];
        }

        $decoded = json_decode($response, true);

        return array_column($decoded['models'] ?? [], 'name');
    }

    private function pullModel(string $baseUrl, string $model): bool
    {
        $ch = curl_init($baseUrl . '/api/pull');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['name' => $model]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 0, // No timeout for large model downloads
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && $httpCode === 200;
    }
}
