<?php

declare(strict_types=1);

namespace AiSystem;

use Illuminate\Support\ServiceProvider;
use AiSystem\Services\AiManager;
use AiSystem\Services\DataIngestionService;
use AiSystem\Services\ContextRetriever;
use AiSystem\Services\QueryGenerator;
use AiSystem\Services\QueryExecutor;
use AiSystem\Services\ResponseGenerator;
use AiSystem\VectorStore\QdrantStore;
use AiSystem\GraphStore\Neo4jStore;
use AiSystem\EmbeddingProviders\OpenAiEmbeddingProvider;
use AiSystem\EmbeddingProviders\AnthropicEmbeddingProvider;
use AiSystem\LlmProviders\OpenAiLlmProvider;
use AiSystem\LlmProviders\AnthropicLlmProvider;
use AiSystem\Contracts\VectorStoreInterface;
use AiSystem\Contracts\GraphStoreInterface;
use AiSystem\Contracts\EmbeddingProviderInterface;
use AiSystem\Contracts\LlmProviderInterface;
use AiSystem\Contracts\DataIngestionServiceInterface;
use AiSystem\Contracts\ContextRetrieverInterface;
use AiSystem\Contracts\QueryGeneratorInterface;
use AiSystem\Contracts\QueryExecutorInterface;
use AiSystem\Contracts\ResponseGeneratorInterface;

/**
 * AI System Service Provider
 *
 * Registers AI system services in the Laravel container for automatic
 * dependency injection throughout your application.
 *
 * **Installation:**
 * Add to `config/app.php` providers array:
 * ```php
 * 'providers' => [
 *     // ...
 *     AiSystem\AiServiceProvider::class,
 * ]
 * ```
 *
 * **Usage with Facade (Recommended):**
 * ```php
 * use AiSystem\Facades\AI;
 *
 * AI::ingest($customer);
 * $context = AI::retrieveContext("Show all teams");
 * $response = AI::chat("What is 2+2?");
 * ```
 *
 * **Usage with Dependency Injection:**
 * ```php
 * use AiSystem\Services\AiManager;
 *
 * class CustomerController extends Controller
 * {
 *     public function __construct(private AiManager $ai) {}
 *
 *     public function store(Request $request)
 *     {
 *         $customer = Customer::create($request->all());
 *         $this->ai->ingest($customer);
 *     }
 * }
 * ```
 *
 * **Usage with Direct Services:**
 * ```php
 * use AiSystem\Contracts\DataIngestionServiceInterface;
 *
 * class CustomerController extends Controller
 * {
 *     public function __construct(
 *         private DataIngestionServiceInterface $ingestion
 *     ) {}
 *
 *     public function store(Request $request)
 *     {
 *         $customer = Customer::create($request->all());
 *         $this->ingestion->ingest($customer);
 *     }
 * }
 * ```
 *
 * @package AiSystem
 */
class AiServiceProvider extends ServiceProvider
{
    /**
     * Register services in the container
     *
     * @return void
     */
    public function register(): void
    {
        // Register configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ai.php',
            'ai'
        );

        // Register Vector Store
        $this->app->singleton(VectorStoreInterface::class, function ($app) {
            $defaultStore = config('ai.vector.default', 'qdrant');

            return match ($defaultStore) {
                'qdrant' => new QdrantStore(config('ai.vector.qdrant')),
                default => throw new \RuntimeException("Unsupported vector store: {$defaultStore}")
            };
        });

        // Register Graph Store
        $this->app->singleton(GraphStoreInterface::class, function ($app) {
            $defaultStore = config('ai.graph.default', 'neo4j');

            return match ($defaultStore) {
                'neo4j' => new Neo4jStore(config('ai.graph.neo4j')),
                default => throw new \RuntimeException("Unsupported graph store: {$defaultStore}")
            };
        });

        // Register Embedding Provider
        $this->app->singleton(EmbeddingProviderInterface::class, function ($app) {
            $defaultProvider = config('ai.embedding.default', 'openai');

            return match ($defaultProvider) {
                'openai' => new OpenAiEmbeddingProvider(config('ai.embedding.openai')),
                'anthropic' => new AnthropicEmbeddingProvider(config('ai.embedding.anthropic')),
                default => throw new \RuntimeException("Unsupported embedding provider: {$defaultProvider}")
            };
        });

        // Register LLM Provider
        $this->app->singleton(LlmProviderInterface::class, function ($app) {
            $defaultProvider = config('ai.llm.default', 'openai');

            return match ($defaultProvider) {
                'openai' => new OpenAiLlmProvider(config('ai.llm.openai')),
                'anthropic' => new AnthropicLlmProvider(config('ai.llm.anthropic')),
                default => throw new \RuntimeException("Unsupported LLM provider: {$defaultProvider}")
            };
        });

        // Register Data Ingestion Service
        $this->app->singleton(DataIngestionServiceInterface::class, function ($app) {
            return new DataIngestionService(
                vectorStore: $app->make(VectorStoreInterface::class),
                graphStore: $app->make(GraphStoreInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class)
            );
        });

        // Alias for easier access
        $this->app->alias(DataIngestionServiceInterface::class, DataIngestionService::class);

        // Register Context Retriever
        $this->app->singleton(ContextRetrieverInterface::class, function ($app) {
            return new ContextRetriever(
                vectorStore: $app->make(VectorStoreInterface::class),
                graphStore: $app->make(GraphStoreInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class)
            );
        });

        // Alias for easier access
        $this->app->alias(ContextRetrieverInterface::class, ContextRetriever::class);

        // Register Query Generator
        $this->app->singleton(QueryGeneratorInterface::class, function ($app) {
            return new QueryGenerator(
                llm: $app->make(LlmProviderInterface::class),
                graphStore: $app->make(GraphStoreInterface::class),
                config: config('ai.query_generation', [])
            );
        });

        // Alias for easier access
        $this->app->alias(QueryGeneratorInterface::class, QueryGenerator::class);

        // Register Query Executor
        $this->app->singleton(QueryExecutorInterface::class, function ($app) {
            return new QueryExecutor(
                graphStore: $app->make(GraphStoreInterface::class),
                config: config('ai.query_execution', [])
            );
        });

        // Alias for easier access
        $this->app->alias(QueryExecutorInterface::class, QueryExecutor::class);

        // Register Response Generator
        $this->app->singleton(ResponseGeneratorInterface::class, function ($app) {
            return new ResponseGenerator(
                llm: $app->make(LlmProviderInterface::class),
                config: config('ai.response_generation', [])
            );
        });

        // Alias for easier access
        $this->app->alias(ResponseGeneratorInterface::class, ResponseGenerator::class);

        // Register AI Manager as singleton
        $this->app->singleton('ai', function ($app) {
            return new AiManager(
                ingestion: $app->make(DataIngestionServiceInterface::class),
                context: $app->make(ContextRetrieverInterface::class),
                embedding: $app->make(EmbeddingProviderInterface::class),
                llm: $app->make(LlmProviderInterface::class),
                queryGenerator: $app->make(QueryGeneratorInterface::class),
                queryExecutor: $app->make(QueryExecutorInterface::class),
                responseGenerator: $app->make(ResponseGeneratorInterface::class)
            );
        });

        // Alias for dependency injection
        $this->app->alias('ai', AiManager::class);
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/ai.php' => config_path('ai.php'),
        ], 'ai-config');

        // Publish entity configuration
        $this->publishes([
            __DIR__ . '/../config/entities.php' => config_path('entities.php'),
        ], 'ai-entities');

        // Load routes if documentation is enabled
        if (config('ai.docs.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }
    }

    /**
     * Get the services provided by the provider
     *
     * @return array
     */
    public function provides(): array
    {
        return [
            VectorStoreInterface::class,
            GraphStoreInterface::class,
            EmbeddingProviderInterface::class,
            LlmProviderInterface::class,
            DataIngestionServiceInterface::class,
            DataIngestionService::class,
            ContextRetrieverInterface::class,
            ContextRetriever::class,
            QueryGeneratorInterface::class,
            QueryGenerator::class,
            QueryExecutorInterface::class,
            QueryExecutor::class,
            ResponseGeneratorInterface::class,
            ResponseGenerator::class,
            'ai',
            AiManager::class,
        ];
    }
}
