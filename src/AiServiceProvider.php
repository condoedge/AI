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
use Condoedge\Ai\Services\SemanticMatcher;
use Condoedge\Ai\Services\SemanticIndexer;
use Condoedge\Ai\Services\ScopeSemanticMatcher;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Services\Discovery\SchemaInspector;
use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;
use Condoedge\Ai\Services\Discovery\CypherQueryBuilderSpy;
use Condoedge\Ai\Services\Discovery\CypherPatternGenerator;
use Condoedge\Ai\Services\Discovery\PropertyDiscoverer;
use Condoedge\Ai\Services\Discovery\RelationshipDiscoverer;
use Condoedge\Ai\Services\Discovery\AliasGenerator;
use Condoedge\Ai\Services\Discovery\EmbedFieldDetector;
use Condoedge\Ai\Services\Discovery\TraversalScopeGenerator;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Discovery\InheritanceResolver;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Services\Context\FileAccessResolver;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Files\PhysicalFileIndexer;
use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Condoedge\Utils\Models\Files\File;
use Condoedge\Ai\Models\Plugins\FileProcessingPlugin;
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Services\UI\ConfigChatThemeFactory;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\Settings\UserChatSettings;

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
                embeddingProvider: $app->make(EmbeddingProviderInterface::class),
                entityConfigs: null, // Load from config
                scopeMatcher: $app->make(ScopeSemanticMatcher::class),
                contextSelector: $app->make(SemanticContextSelector::class)
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

        // The way to add a new section to the Semantic Prompt Builders
        // $this->app->make(SemanticPromptBuilder::class)->addSection($section);

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

        // Register Access Level Resolver
        $this->app->singleton(AccessLevelResolver::class);

        // Register AI Manager as singleton
        $this->app->singleton('ai', function ($app) {
            return new AiManager(
                ingestion: $app->make(DataIngestionServiceInterface::class),
                context: $app->make(ContextRetrieverInterface::class),
                embedding: $app->make(EmbeddingProviderInterface::class),
                llm: $app->make(LlmProviderInterface::class),
                queryGenerator: $app->make(QueryGeneratorInterface::class),
                queryExecutor: $app->make(QueryExecutorInterface::class),
                responseGenerator: $app->make(ResponseGeneratorInterface::class),
                vectorStore: $app->make(VectorStoreInterface::class)
            );
        });

        // Alias for dependency injection
        $this->app->alias('ai', AiManager::class);

        // Register Semantic Matching Services
        $this->registerSemanticServices();

        // Register Discovery Services
        $this->registerDiscoveryServices();

        // Register Chat Service
        $this->registerChatServices();

        // Register Context Management Services
        $this->registerContextServices();

        // Register File Context Services
        $this->registerFileContextServices();

        // Register UI Theming Services
        $this->registerUiServices();

        // Register Settings Services
        $this->registerSettingsServices();
    }

    /**
     * Register semantic matching services
     *
     * @return void
     */
    private function registerSemanticServices(): void
    {
        // Register SemanticMatcher
        $this->app->singleton(SemanticMatcher::class, function ($app) {
            return new SemanticMatcher(
                embedding: $app->make(EmbeddingProviderInterface::class),
                vectorStore: $app->make(VectorStoreInterface::class)
            );
        });

        // Register SemanticIndexer
        $this->app->singleton(SemanticIndexer::class, function ($app) {
            return new SemanticIndexer(
                embedding: $app->make(EmbeddingProviderInterface::class),
                vectorStore: $app->make(VectorStoreInterface::class),
                entityConfigs: config('entities', [])
            );
        });

        // Register ScopeSemanticMatcher
        $this->app->singleton(ScopeSemanticMatcher::class, function ($app) {
            return new ScopeSemanticMatcher(
                vectorStore: $app->make(VectorStoreInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class),
                config: config('ai.scope_matching', [])
            );
        });

        // Register SemanticContextSelector
        $this->app->singleton(SemanticContextSelector::class, function ($app) {
            return new SemanticContextSelector(
                vectorStore: $app->make(VectorStoreInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class),
                config: config('ai.semantic_context', [])
            );
        });
    }

    /**
     * Register auto-discovery services
     *
     * @return void
     */
    private function registerDiscoveryServices(): void
    {
        // Register SchemaInspector
        $this->app->singleton(SchemaInspector::class);

        // Register CypherQueryBuilderSpy
        $this->app->bind(CypherQueryBuilderSpy::class);

        // Register CypherPatternGenerator
        $this->app->singleton(CypherPatternGenerator::class);

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
        $this->app->singleton(AliasGenerator::class);

        // Register EmbedFieldDetector
        $this->app->singleton(EmbedFieldDetector::class, function ($app) {
            return new EmbedFieldDetector(
                schema: $app->make(SchemaInspector::class)
            );
        });

        // Register TraversalScopeGenerator
        $this->app->singleton(TraversalScopeGenerator::class);

        // Register InheritanceResolver
        $this->app->singleton(InheritanceResolver::class);

        // Register EntityAutoDiscovery
        $this->app->singleton(EntityAutoDiscovery::class, function ($app) {
            return new EntityAutoDiscovery(
                schema: $app->make(SchemaInspector::class),
                scopeAdapter: $app->make(CypherScopeAdapter::class),
                relationships: $app->make(RelationshipDiscoverer::class),
                properties: $app->make(PropertyDiscoverer::class),
                aliases: $app->make(AliasGenerator::class),
                embedFields: $app->make(EmbedFieldDetector::class),
                traversalGenerator: $app->make(TraversalScopeGenerator::class),
                inheritanceResolver: $app->make(InheritanceResolver::class)
            );
        });
    }

    /**
     * Register chat services
     *
     * @return void
     */
    private function registerChatServices(): void
    {
        // Register AI Chat Service
        $this->app->singleton(AiChatServiceInterface::class, function ($app) {
            return new AiChatService(
                config: config('ai.chat', [])
            );
        });

        // Alias for easier access
        $this->app->alias(AiChatServiceInterface::class, AiChatService::class);
    }

    /**
     * Register context management services
     *
     * @return void
     */
    private function registerContextServices(): void
    {
        // Register EntityExtractor
        $this->app->singleton(EntityExtractor::class);

        // Register ReferenceResolver
        $this->app->singleton(ReferenceResolver::class);

        // Register ConversationContextManager with dependencies
        $this->app->singleton(ConversationContextManager::class, function ($app) {
            return new ConversationContextManager(
                $app->make(EntityExtractor::class),
                $app->make(ReferenceResolver::class)
            );
        });
    }

    /**
     * Register file context services.
     */
    private function registerFileContextServices(): void
    {
        // File access resolver (singleton for consistent security checks)
        $this->app->singleton(FileAccessResolverInterface::class, FileAccessResolver::class);
        $this->app->singleton(FileAccessResolver::class);

        // Physical file indexer
        $this->app->singleton(PhysicalFileIndexer::class);

        // File context provider
        $this->app->singleton(FileContextProvider::class, function ($app) {
            return new FileContextProvider(
                $app->make(FileSearchService::class),
                $app->make(FileAccessResolverInterface::class)
            );
        });

        // Response enricher
        $this->app->singleton(ResponseFileEnricher::class);
    }

    /**
     * Register UI theming services.
     */
    private function registerUiServices(): void
    {
        // Register the configured factory implementation
        $this->app->singleton(ChatThemeFactoryInterface::class, function ($app) {
            $factoryClass = config('ai.ui.factory', ConfigChatThemeFactory::class);
            return new $factoryClass();
        });

        // Alias for convenience
        $this->app->alias(ChatThemeFactoryInterface::class, 'chat-theme-factory');

        // Register the default theme from factory
        $this->app->singleton(ChatThemeInterface::class, function ($app) {
            return $app->make(ChatThemeFactoryInterface::class)->create();
        });
    }

    /**
     * Register settings services.
     */
    private function registerSettingsServices(): void
    {
        $this->app->singleton(ChatSettingsInterface::class, function ($app) {
            return new UserChatSettings();
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__."/../routes/api.php");
        $this->loadRoutesFrom(__DIR__."/../routes/web.php");

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/ai.php' => config_path('ai.php'),
        ], 'ai-config');

        // Publish entity configuration
        $this->publishes([
            __DIR__ . '/../config/entities.php' => config_path('entities.php'),
        ], 'ai-entities');

        $this->publishes([
            __DIR__ . '/../config/larecipe.php' => config_path('larecipe.php'),
            __DIR__ . '/../resources/views' => resource_path('views'),
            __DIR__ . '/../public' => base_path('public'),
        ], 'ai-docs');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Condoedge\Ai\Console\Commands\DiagnoseCommand::class,
                \Condoedge\Ai\Console\Commands\DiscoverEntitiesCommand::class,
                \Condoedge\Ai\Console\Commands\IngestEntitiesCommand::class,
                \Condoedge\Ai\Console\Commands\SyncRelationshipsCommand::class,
                \Condoedge\Ai\Console\Commands\ProcessFilesCommand::class,
                \Condoedge\Ai\Console\Commands\IndexSemanticCommand::class,
                \Condoedge\Ai\Console\Commands\IndexScopesCommand::class,
                \Condoedge\Ai\Console\Commands\IndexContextCommand::class,
                \Condoedge\Ai\Console\Commands\ValidateConfigCommand::class,
                \Condoedge\Ai\Console\Commands\LearnFromLogsCommand::class,
            ]);
        }

        // Register sync observers for related models
        $this->registerSyncObservers();

        File::setPlugins([
            FileProcessingPlugin::class,
        ]);
    }

    /**
     * Register sync observers for related model changes
     *
     * This allows the AI system to stay in sync when related models change.
     * Currently a stub - full implementation coming in future update.
     *
     * @return void
     */
    protected function registerSyncObservers(): void
    {
        // TODO: Register observers for related model synchronization
        // This will be implemented when RelatedModelSyncObserver is complete
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
            InheritanceResolver::class,
            AiChatServiceInterface::class,
            AiChatService::class,
            EntityExtractor::class,
            ReferenceResolver::class,
            ConversationContextManager::class,
            FileAccessResolverInterface::class,
            FileAccessResolver::class,
            FileContextProvider::class,
            PhysicalFileIndexer::class,
            ResponseFileEnricher::class,
            ChatThemeFactoryInterface::class,
            ChatThemeInterface::class,
            ChatSettingsInterface::class,
        ];
    }
}
