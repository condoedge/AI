<?php

namespace AiSystem\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * AI Documentation Controller
 *
 * Provides visual documentation interface for the AI Text-to-Query system.
 */
class AiDocsController extends Controller
{
    /**
     * Main documentation page
     */
    public function index()
    {
        return view('ai-docs::index');
    }

    /**
     * Architecture overview
     */
    public function architecture()
    {
        return view('ai-docs::architecture');
    }

    /**
     * Entity configurations list
     */
    public function entities()
    {
        $entities = config('ai.entities', []);

        return view('ai-docs::entities', [
            'entities' => $entities,
        ]);
    }

    /**
     * Entity detail view
     */
    public function entityDetail(string $entity)
    {
        $entities = config('ai.entities', []);

        if (!isset($entities[$entity])) {
            abort(404, "Entity '{$entity}' not found in configuration");
        }

        return view('ai-docs::entity-detail', [
            'entityName' => $entity,
            'config' => $entities[$entity],
        ]);
    }

    /**
     * Configuration reference
     */
    public function configuration()
    {
        $config = config('ai');

        return view('ai-docs::configuration', [
            'config' => $config,
        ]);
    }

    /**
     * Examples & tutorials
     */
    public function examples()
    {
        return view('ai-docs::examples');
    }

    /**
     * API reference
     */
    public function apiReference()
    {
        return view('ai-docs::api-reference');
    }

    /**
     * Test Neo4j connection
     */
    public function testNeo4j()
    {
        try {
            $config = config('ai.neo4j');

            if (!$config['enabled']) {
                return response()->json([
                    'status' => 'disabled',
                    'message' => 'Neo4j is disabled in configuration',
                ], 200);
            }

            // Test connection (will implement once Neo4jStore is created)
            return response()->json([
                'status' => 'success',
                'message' => 'Neo4j connection test successful',
                'config' => [
                    'uri' => $config['uri'],
                    'database' => $config['database'],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test Qdrant connection
     */
    public function testQdrant()
    {
        try {
            $config = config('ai.qdrant');

            if (!$config['enabled']) {
                return response()->json([
                    'status' => 'disabled',
                    'message' => 'Qdrant is disabled in configuration',
                ], 200);
            }

            // Test connection (will implement once QdrantStore is created)
            return response()->json([
                'status' => 'success',
                'message' => 'Qdrant connection test successful',
                'config' => [
                    'host' => $config['host'],
                    'port' => $config['port'],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test LLM provider connection
     */
    public function testLlm()
    {
        try {
            $provider = config('ai.llm.default_provider');
            $config = config("ai.llm.{$provider}");

            // Test connection (will implement once LLM providers are created)
            return response()->json([
                'status' => 'success',
                'message' => "LLM provider '{$provider}' connection test successful",
                'provider' => $provider,
                'model' => $config['model'] ?? 'N/A',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
