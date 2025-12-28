<?php
// src/Http/Controllers/HealthController.php

declare(strict_types=1);

namespace Condoedge\Ai\Http\Controllers;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $services = [];
        $healthy = true;

        // Check Neo4j
        try {
            $graphStore = app(GraphStoreInterface::class);
            $graphStore->getSchema();
            $services['neo4j'] = ['status' => 'healthy'];
        } catch (\Exception $e) {
            $services['neo4j'] = ['status' => 'unhealthy', 'error' => $e->getMessage()];
            $healthy = false;
        }

        // Check Qdrant
        try {
            $vectorStore = app(VectorStoreInterface::class);
            $vectorStore->listCollections();
            $services['qdrant'] = ['status' => 'healthy'];
        } catch (\Exception $e) {
            $services['qdrant'] = ['status' => 'unhealthy', 'error' => $e->getMessage()];
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
}
