<?php

declare(strict_types=1);

namespace Condoedge\Ai;

use Illuminate\Support\ServiceProvider;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\VectorStore\QdrantStore;
use Condoedge\Ai\GraphStore\Neo4jStore;
use Condoedge\Ai\EmbeddingProviders\OpenAiEmbeddingProvider;
use Condoedge\Ai\EmbeddingProviders\AnthropicEmbeddingProvider;
use Condoedge\Ai\LlmProviders\OpenAiLlmProvider;
use Condoedge\Ai\LlmProviders\AnthropicLlmProvider;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\FileChunkerInterface;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileExtractorInterface;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Services\SemanticChunker;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\Extractors\TextExtractor;
use Condoedge\Ai\Services\Extractors\MarkdownExtractor;
use Condoedge\Ai\Services\Extractors\PdfExtractor;
use Condoedge\Ai\Services\Extractors\DocxExtractor;
use Condoedge\Ai\Services\PatternLibrary;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Services\Discovery\SchemaInspector;
use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;
use Condoedge\Ai\Services\Discovery\CypherQueryBuilderSpy;
use Condoedge\Ai\Services\Discovery\CypherPatternGenerator;
use Condoedge\Ai\Services\Discovery\PropertyDiscoverer;
use Condoedge\Ai\Services\Discovery\RelationshipDiscoverer;
use Condoedge\Ai\Services\Discovery\AliasGenerator;
use Condoedge\Ai\Services\Discovery\EmbedFieldDetector;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

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
 *     Condoedge\Ai\AiServiceProvider::class,
 * ]
 * ```
 *
 * **Usage with Facade (Recommended):**
 * ```php
 * use Condoedge\Ai\Facades\AI;
 *
 * AI::ingest($customer);
 * $context = AI::retrieveContext("Show all teams");
 * $response = AI::chat("What is 2+2?");
 * ```
 *
 * **Usage with Dependency Injection:**
 * ```php
 * use Condoedge\Ai\Services\AiManager;
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
 * use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
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
 * @package Condoedge\Ai
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

        // Register Auto-Discovery Services
        $this->app->singleton(\Condoedge\Ai\Services\Discovery\SchemaInspector::class);
        $this->app->singleton(\Condoedge\Ai\Services\Discovery\CypherScopeAdapter::class);
        $this->app->singleton(\Condoedge\Ai\Services\Discovery\PropertyDiscoverer::class);
        $this->app->singleton(\Condoedge\Ai\Services\Discovery\RelationshipDiscoverer::class);
        $this->app->singleton(\Condoedge\Ai\Services\Discovery\AliasGenerator::class);

        $this->app->singleton(\Condoedge\Ai\Services\Discovery\EmbedFieldDetector::class, function ($app) {
            return new \Condoedge\Ai\Services\Discovery\EmbedFieldDetector(
                $app->make(\Condoedge\Ai\Services\Discovery\SchemaInspector::class)
            );
        });

        $this->app->singleton(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class, function ($app) {
            return new \Condoedge\Ai\Services\Discovery\EntityAutoDiscovery(
                propertyDiscoverer: $app->make(\Condoedge\Ai\Services\Discovery\PropertyDiscoverer::class),
                relationshipDiscoverer: $app->make(\Condoedge\Ai\Services\Discovery\RelationshipDiscoverer::class),
                aliasGenerator: $app->make(\Condoedge\Ai\Services\Discovery\AliasGenerator::class),
                embedFieldDetector: $app->make(\Condoedge\Ai\Services\Discovery\EmbedFieldDetector::class),
                scopeAdapter: $app->make(\Condoedge\Ai\Services\Discovery\CypherScopeAdapter::class)
            );
        });

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

        // Register Pattern Library
        $this->app->singleton(PatternLibrary::class, function ($app) {
            $patterns = config('ai.query_patterns', []);
            return new PatternLibrary($patterns);
        });

        // Register Semantic Prompt Builder
        $this->app->singleton(SemanticPromptBuilder::class, function ($app) {
            return new SemanticPromptBuilder(
                patternLibrary: $app->make(PatternLibrary::class)
            );
        });

        // Register Query Generator
        $this->app->singleton(QueryGeneratorInterface::class, function ($app) {
            return new QueryGenerator(
                llm: $app->make(LlmProviderInterface::class),
                graphStore: $app->make(GraphStoreInterface::class),
                config: config('ai.query_generation', []),
                promptBuilder: $app->make(SemanticPromptBuilder::class)
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

        // Register File Chunker
        $this->app->singleton(FileChunkerInterface::class, function ($app) {
            return new SemanticChunker();
        });

        // Register Chunk Store
        $this->app->singleton(ChunkStoreInterface::class, function ($app) {
            return new QdrantChunkStore(
                vectorStore: $app->make(VectorStoreInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class),
                collection: config('ai.file_processing.collection', 'file_chunks')
            );
        });

        // Register File Extractor Registry
        $this->app->singleton(FileExtractorRegistry::class, function ($app) {
            $registry = new FileExtractorRegistry();

            // Register default extractors
            $registry->registerMany([
                new TextExtractor(),
                new MarkdownExtractor(),
                new PdfExtractor(),
                new DocxExtractor(),
            ]);

            return $registry;
        });

        // Register File Processor
        $this->app->singleton(FileProcessorInterface::class, function ($app) {
            return new FileProcessor(
                extractorRegistry: $app->make(FileExtractorRegistry::class),
                chunker: $app->make(FileChunkerInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class),
                chunkStore: $app->make(ChunkStoreInterface::class)
            );
        });

        // Alias for easier access
        $this->app->alias(FileProcessorInterface::class, FileProcessor::class);

        // Register File Search Service
        $this->app->singleton('file-search', function ($app) {
            return new FileSearchService(
                chunkStore: $app->make(ChunkStoreInterface::class),
                graphStore: $app->make(GraphStoreInterface::class)
            );
        });

        // Alias for dependency injection
        $this->app->alias('file-search', FileSearchService::class);

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

        // Register Discovery Services
        $this->registerDiscoveryServices();
    }

    /**
     * Register auto-discovery services
     *
     * @return void
     */
    private function registerDiscoveryServices(): void
    {
        // Register SchemaInspector
        $this->app->singleton(SchemaInspector::class, function ($app) {
            return new SchemaInspector();
        });

        // Register CypherQueryBuilderSpy
        $this->app->bind(CypherQueryBuilderSpy::class, function ($app) {
            return new CypherQueryBuilderSpy();
        });

        // Register CypherPatternGenerator
        $this->app->singleton(CypherPatternGenerator::class, function ($app) {
            return new CypherPatternGenerator();
        });

        // Register CypherScopeAdapter
        $this->app->singleton(CypherScopeAdapter::class, function ($app) {
            return new CypherScopeAdapter(
                spy: $app->make(CypherQueryBuilderSpy::class),
                generator: $app->make(CypherPatternGenerator::class)
            );
        });

        // Register PropertyDiscoverer
        $this->app->singleton(PropertyDiscoverer::class, function ($app) {
            return new PropertyDiscoverer(
                schema: $app->make(SchemaInspector::class)
            );
        });

        // Register RelationshipDiscoverer
        $this->app->singleton(RelationshipDiscoverer::class, function ($app) {
            return new RelationshipDiscoverer(
                schema: $app->make(SchemaInspector::class)
            );
        });

        // Register AliasGenerator
        $this->app->singleton(AliasGenerator::class, function ($app) {
            return new AliasGenerator();
        });

        // Register EmbedFieldDetector
        $this->app->singleton(EmbedFieldDetector::class, function ($app) {
            return new EmbedFieldDetector(
                schema: $app->make(SchemaInspector::class)
            );
        });

        // Register EntityAutoDiscovery
        $this->app->singleton(EntityAutoDiscovery::class, function ($app) {
            return new EntityAutoDiscovery(
                schema: $app->make(SchemaInspector::class),
                scopeAdapter: $app->make(CypherScopeAdapter::class),
                relationships: $app->make(RelationshipDiscoverer::class),
                properties: $app->make(PropertyDiscoverer::class),
                aliases: $app->make(AliasGenerator::class),
                embedFields: $app->make(EmbedFieldDetector::class)
            );
        });
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
            PatternLibrary::class,
            SemanticPromptBuilder::class,
            QueryGeneratorInterface::class,
            QueryGenerator::class,
            QueryExecutorInterface::class,
            QueryExecutor::class,
            ResponseGeneratorInterface::class,
            ResponseGenerator::class,
            FileChunkerInterface::class,
            ChunkStoreInterface::class,
            FileExtractorRegistry::class,
            FileProcessorInterface::class,
            FileProcessor::class,
            'file-search',
            FileSearchService::class,
            'ai',
            AiManager::class,
            SchemaInspector::class,
            CypherScopeAdapter::class,
            CypherQueryBuilderSpy::class,
            CypherPatternGenerator::class,
            PropertyDiscoverer::class,
            RelationshipDiscoverer::class,
            AliasGenerator::class,
            EmbedFieldDetector::class,
            EntityAutoDiscovery::class,
        ];
    }
}
