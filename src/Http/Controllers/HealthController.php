<?php

declare(strict_types=1);

namespace Condoedge\Ai\Http\Controllers;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $services = [];
        $healthy = true;

        try {
            $graphStore = app(GraphStoreInterface::class);
            $graphStore->getSchema();
            $services['neo4j'] = ['status' => 'healthy'];
        } catch (\Throwable $e) {
            // Never echo exception text (DSNs, hosts, credentials) — log it for operators.
            $this->logFailure('neo4j', $e);
            $services['neo4j'] = ['status' => 'unhealthy'];
            $healthy = false;
        }

        try {
            $vectorStore = app(VectorStoreInterface::class);
            $vectorStore->listCollections();
            $services['qdrant'] = ['status' => 'healthy'];
        } catch (\Throwable $e) {
            $this->logFailure('qdrant', $e);
            $services['qdrant'] = ['status' => 'unhealthy'];
            $healthy = false;
        }

        // Check LLM API key configured
        $llmConfigured = config('ai.llm.default') && config('ai.llm.' . config('ai.llm.default') . '.api_key');
        $services['llm'] = ['status' => $llmConfigured ? 'configured' : 'not_configured'];
        if (!$llmConfigured) {
            $healthy = false;
        }

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'services' => $services,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function logFailure(string $service, \Throwable $e): void
    {
        Log::warning("[AI health] {$service} check failed", [
            'service' => $service,
            'exception' => get_class($e),
            'error' => $e->getMessage(),
        ]);
    }
}
