<?php

declare(strict_types=1);

namespace Condoedge\Ai;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\LlmResolver;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\VectorStore\QdrantStore;
use Condoedge\Ai\GraphStore\Neo4jStoreFactory;
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
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Contracts\PdfParserInterface;
use Condoedge\Ai\Services\SemanticChunker;
use Condoedge\Ai\Services\QdrantChunkStore;
use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\Extractors\TextExtractor;
use Condoedge\Ai\Services\Extractors\MarkdownExtractor;
use Condoedge\Ai\Services\Extractors\OcrPdfParser;
use Condoedge\Ai\Services\Extractors\PdfExtractor;
use Condoedge\Ai\Services\Extractors\DocxExtractor;
use Condoedge\Ai\Services\PatternLibrary;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Services\SemanticIndexer;
use Condoedge\Ai\Services\ScopeSemanticMatcher;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Services\Chat\RegenerateMessageService;
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
use Condoedge\Ai\Services\Response\ResponseEntityEnricher;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\FileCitationHandler;
use Condoedge\Ai\Models\Plugins\FileProcessingPlugin;
use Condoedge\Ai\Models\Plugins\FileAccessScopePlugin;
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Services\UI\ConfigChatThemeFactory;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\Settings\UserChatSettings;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Policies\AiConversationPolicy;
use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Auth\KompoAuthAdapter;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Utils\Facades\FileModel;

/**
 * AI System Service Provider
 *
 * Registers AI system services in the Laravel container for automatic
 * dependency injection throughout the application. Services are consumable via
 * the AI facade, constructor injection of AiManager, or the individual
 * contract interfaces.
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
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ai.php',
            'ai'
        );

        $this->app->singleton(\Condoedge\Ai\Support\AiLogger::class);

        $this->app->singleton(VectorStoreInterface::class, function ($app) {
            $defaultStore = config('ai.vector.default', 'qdrant');

            return match ($defaultStore) {
                'qdrant' => new QdrantStore(config('ai.vector.qdrant')),
                default => throw new \RuntimeException("Unsupported vector store: {$defaultStore}")
            };
        });

        // Factory auto-selects the Bolt or HTTP driver based on URI scheme.
        $this->app->singleton(GraphStoreInterface::class, function ($app) {
            $defaultStore = config('ai.graph.default', 'neo4j');

            return match ($defaultStore) {
                'neo4j' => Neo4jStoreFactory::create(config('ai.graph.neo4j')),
                default => throw new \RuntimeException("Unsupported graph store: {$defaultStore}")
            };
        });

        $this->app->singleton(EmbeddingProviderInterface::class, function ($app) {
            $defaultProvider = config('ai.embedding.default', 'openai');

            $provider = match ($defaultProvider) {
                'openai' => new OpenAiEmbeddingProvider(config('ai.embedding.openai')),
                'anthropic' => new AnthropicEmbeddingProvider(config('ai.embedding.anthropic')),
                default => throw new \RuntimeException("Unsupported embedding provider: {$defaultProvider}")
            };

            // Wrap with memoization: same text is embedded only once per request
            return new \Condoedge\Ai\EmbeddingProviders\MemoizedEmbeddingProvider($provider);
        });

        $this->app->singleton(LlmProviderInterface::class, function ($app) {
            $defaultProvider = config('ai.llm.default', 'openai');

            return match ($defaultProvider) {
                'openai' => new OpenAiLlmProvider(config('ai.llm.openai')),
                'anthropic' => new AnthropicLlmProvider(config('ai.llm.anthropic')),
                default => throw new \RuntimeException("Unsupported LLM provider: {$defaultProvider}")
            };
        });

        // Per-role model selection.
        $this->app->singleton(LlmResolver::class, function ($app) {
            return new LlmResolver(
                defaultProvider: $app->make(LlmProviderInterface::class),
                roleConfig: config('ai.llm_roles', [])
            );
        });

        $this->app->singleton(DataIngestionServiceInterface::class, function ($app) {
            return new DataIngestionService(
                vectorStore: $app->make(VectorStoreInterface::class),
                graphStore: $app->make(GraphStoreInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class)
            );
        });

        $this->app->alias(DataIngestionServiceInterface::class, DataIngestionService::class);

        // Decorates the base ingestion service.
        $this->app->singleton(\Condoedge\Ai\Services\BatchDataIngestionService::class, function ($app) {
            return new \Condoedge\Ai\Services\BatchDataIngestionService(
                inner: $app->make(\Condoedge\Ai\Contracts\DataIngestionServiceInterface::class),
                vectorStore: $app->make(\Condoedge\Ai\Contracts\VectorStoreInterface::class),
                graphStore: $app->make(\Condoedge\Ai\Contracts\GraphStoreInterface::class),
                embeddingProvider: $app->make(\Condoedge\Ai\Contracts\EmbeddingProviderInterface::class),
                logger: $app->make(\Condoedge\Ai\Support\AiLogger::class),
            );
        });

        $this->app->singleton(ContextRetrieverInterface::class, function ($app) {
            $retriever = new ContextRetriever(
                vectorStore: $app->make(VectorStoreInterface::class),
                graphStore: $app->make(GraphStoreInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class),
                logger: $app->make(AiLogger::class),
                entityConfigs: null, // Load from config
                scopeMatcher: $app->make(ScopeSemanticMatcher::class),
                contextSelector: $app->make(SemanticContextSelector::class),
                reranker: $app->make(\Condoedge\Ai\Services\Retrieval\RerankerInterface::class),
            );

            if (config('ai.query_understanding.enabled', true)) {
                $retriever->setQueryUnderstanding($app->make(QueryUnderstandingService::class));
            }

            return $retriever;
        });

        $this->app->alias(ContextRetrieverInterface::class, ContextRetriever::class);

        $this->app->singleton(PatternLibrary::class, function ($app) {
            $patterns = config('ai.query_patterns', []);
            return new PatternLibrary($patterns);
        });

        $this->app->singleton(SemanticPromptBuilder::class, function ($app) {
            return new SemanticPromptBuilder(
                patternLibrary: $app->make(PatternLibrary::class)
            );
        });

        // The way to add a new section to the Semantic Prompt Builders
        // $this->app->make(SemanticPromptBuilder::class)->addSection($section);

        $this->app->singleton(QueryGeneratorInterface::class, function ($app) {
            return new QueryGenerator(
                llm: $app->make(LlmResolver::class)->forRole('query_generation'),
                graphStore: $app->make(GraphStoreInterface::class),
                config: config('ai.query_generation', []),
                promptBuilder: $app->make(SemanticPromptBuilder::class),
                logger: $app->make(AiLogger::class)
            );
        });

        $this->app->alias(QueryGeneratorInterface::class, QueryGenerator::class);

        $this->app->singleton(QueryExecutorInterface::class, function ($app) {
            return new QueryExecutor(
                graphStore: $app->make(GraphStoreInterface::class),
                config: config('ai.query_execution', [])
            );
        });

        $this->app->alias(QueryExecutorInterface::class, QueryExecutor::class);

        $this->app->singleton(ResponseGeneratorInterface::class, function ($app) {
            return new ResponseGenerator(
                llm: $app->make(LlmResolver::class)->forRole('response_generation'),
                config: config('ai.response_generation', [])
            );
        });

        $this->app->alias(ResponseGeneratorInterface::class, ResponseGenerator::class);

        $this->app->singleton(FileChunkerInterface::class, function ($app) {
            return new SemanticChunker();
        });

        $this->app->singleton(ChunkStoreInterface::class, function ($app) {
            return new QdrantChunkStore(
                vectorStore: $app->make(VectorStoreInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class),
                collection: config('ai.file_processing.collection', 'file_chunks')
            );
        });

        $this->app->singleton(PdfParserInterface::class, function ($app) {
            return new OcrPdfParser();
        });

        $this->app->singleton(FileExtractorRegistry::class, function ($app) {
            $registry = new FileExtractorRegistry();

            $registry->registerMany([
                new TextExtractor(),
                new MarkdownExtractor(),
                new PdfExtractor($app->make(PdfParserInterface::class)),
                new DocxExtractor(),
            ]);

            return $registry;
        });

        $this->app->singleton(FileProcessorInterface::class, function ($app) {
            return new FileProcessor(
                extractorRegistry: $app->make(FileExtractorRegistry::class),
                chunker: $app->make(FileChunkerInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class),
                chunkStore: $app->make(ChunkStoreInterface::class)
            );
        });

        $this->app->alias(FileProcessorInterface::class, FileProcessor::class);

        $this->app->singleton('file-search', function ($app) {
            return new FileSearchService(
                chunkStore: $app->make(ChunkStoreInterface::class),
                graphStore: $app->make(GraphStoreInterface::class),
                additionalCollections: $this->resolveAdditionalSearchCollections()
            );
        });

        $this->app->alias('file-search', FileSearchService::class);

        $this->app->singleton('ai', function ($app) {
            return new AiManager(
                ingestion: $app->make(DataIngestionServiceInterface::class),
                context: $app->make(ContextRetrieverInterface::class),
                embedding: $app->make(EmbeddingProviderInterface::class),
                llm: $app->make(LlmProviderInterface::class),
                queryGenerator: $app->make(QueryGeneratorInterface::class),
                queryExecutor: $app->make(QueryExecutorInterface::class),
                responseGenerator: $app->make(ResponseGeneratorInterface::class),
                vectorStore: $app->make(VectorStoreInterface::class),
                fileContextProvider: $app->make(FileContextProvider::class),
                responseFileEnricher: $app->make(ResponseFileEnricher::class),
                responseEntityEnricher: $app->make(ResponseEntityEnricher::class)
            );
        });

        $this->app->alias('ai', AiManager::class);

        $this->registerSemanticServices();
        $this->registerDiscoveryServices();
        $this->registerChatServices();
        $this->registerContextServices();
        $this->registerFileContextServices();
        $this->registerUiServices();
        $this->registerSettingsServices();
        $this->registerSecurityServices();
        $this->registerRetrievalServices();
        $this->registerActionServices();
    }

    /**
     * Register retrieval (second-stage re-ranking) services.
     */
    private function registerRetrievalServices(): void
    {
        $this->app->singleton(\Condoedge\Ai\Services\Retrieval\RerankerInterface::class, function ($app) {
            if (config('ai.rag.rerank_enabled', false)) {
                return new \Condoedge\Ai\Services\Retrieval\LlmReranker(
                    $app->make(LlmProviderInterface::class)
                );
            }

            return new \Condoedge\Ai\Services\Retrieval\NullReranker();
        });
    }

    /**
     * Register agentic action services (action registry + dispatcher).
     *
     * The registry self-ingests config('ai.actions') class-strings on boot the house-Plugin way
     * (new $class()), so a host registers an action exactly like it registers a Plugin — by
     * listing the class-string in config, with NO bespoke service-provider code here.
     */
    private function registerActionServices(): void
    {
        $this->app->singleton(\Condoedge\Ai\Services\Actions\AiActionRegistry::class);
        $this->app->singleton(\Condoedge\Ai\Services\Actions\ActionDispatcher::class);
    }

    /**
     * Register semantic matching services
     *
     * @return void
     */
    private function registerSemanticServices(): void
    {
        $this->app->singleton(SemanticIndexer::class, function ($app) {
            return new SemanticIndexer(
                embedding: $app->make(EmbeddingProviderInterface::class),
                vectorStore: $app->make(VectorStoreInterface::class),
                entityConfigs: config('entities', [])
            );
        });

        $this->app->singleton(ScopeSemanticMatcher::class, function ($app) {
            return new ScopeSemanticMatcher(
                vectorStore: $app->make(VectorStoreInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class),
                config: config('ai.scope_matching', [])
            );
        });

        $this->app->singleton(SemanticContextSelector::class, function ($app) {
            return new SemanticContextSelector(
                vectorStore: $app->make(VectorStoreInterface::class),
                embeddingProvider: $app->make(EmbeddingProviderInterface::class),
                logger: $app->make(AiLogger::class),
                config: config('ai.semantic_context', [])
            );
        });

        $this->app->singleton(QueryUnderstandingService::class, function ($app) {
            // Corpus languages live under file_context (where retrieval reads them);
            // surface them into the QU config so the single QU LLM call can emit the
            // question translated into each — the cross-lingual fan-out angles.
            $quConfig = config('ai.query_understanding', []);
            $quConfig['corpus_languages'] = config('ai.file_context.corpus_languages', []);

            return new QueryUnderstandingService(
                llm: $app->make(LlmResolver::class)->forRole('query_understanding'),
                logger: $app->make(AiLogger::class),
                config: $quConfig
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
        $this->app->singleton(SchemaInspector::class);

        $this->app->bind(CypherQueryBuilderSpy::class);

        $this->app->singleton(CypherPatternGenerator::class);

        $this->app->singleton(CypherScopeAdapter::class, function ($app) {
            return new CypherScopeAdapter(
                spy: $app->make(CypherQueryBuilderSpy::class),
                generator: $app->make(CypherPatternGenerator::class)
            );
        });

        $this->app->singleton(PropertyDiscoverer::class, function ($app) {
            return new PropertyDiscoverer(
                schema: $app->make(SchemaInspector::class)
            );
        });

        $this->app->singleton(RelationshipDiscoverer::class, function ($app) {
            return new RelationshipDiscoverer(
                schema: $app->make(SchemaInspector::class)
            );
        });

        $this->app->singleton(AliasGenerator::class);

        $this->app->singleton(EmbedFieldDetector::class, function ($app) {
            return new EmbedFieldDetector(
                schema: $app->make(SchemaInspector::class)
            );
        });

        $this->app->singleton(TraversalScopeGenerator::class);

        $this->app->singleton(InheritanceResolver::class);

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

        $this->app->singleton(\Condoedge\Ai\Services\Discovery\AiConfigEnhancer::class, function ($app) {
            return new \Condoedge\Ai\Services\Discovery\AiConfigEnhancer(
                llm: $app->make(LlmProviderInterface::class)
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
        $this->app->singleton(AiChatServiceInterface::class, function ($app) {
            $aiChatService = config('ai.chat.service', AiChatService::class);
            
            return new $aiChatService(
                config: config('ai.chat', [])
            );
        });

        $this->app->alias(AiChatServiceInterface::class, AiChatService::class);

        // Thin guard over RegenerateAnswerUseCase; the use case is container-autowired,
        // so let the container resolve its dependencies.
        $this->app->singleton(RegenerateMessageService::class);
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
        // File access resolver (singleton for consistent security checks).
        // Bind the concrete as the shared singleton, then alias the interface to it so that
        // app(FileAccessResolverInterface::class) and app(FileAccessResolver::class) return the
        // exact same instance. (Aliasing the interface->concrete on a class-string singleton
        // binding would recurse infinitely, so bind concrete + alias interface instead.)
        $this->app->singleton(FileAccessResolver::class);
        $this->app->alias(FileAccessResolver::class, FileAccessResolverInterface::class);

        $this->app->singleton(PhysicalFileIndexer::class);

        // File context enricher (override in project service provider for priority logic)
        $this->app->singleton(
            \Condoedge\Ai\Contracts\FileContextEnricherInterface::class,
            \Condoedge\Ai\Services\Context\DefaultFileContextEnricher::class
        );

        $this->app->singleton(FileContextProvider::class, function ($app) {
            return new FileContextProvider(
                $app->make(FileSearchService::class),
                $app->make(FileAccessResolverInterface::class),
                null,
                $app->make(\Condoedge\Ai\Contracts\FileContextEnricherInterface::class),
                null,
                $app->make(ChunkStoreInterface::class)
            );
        });

        $this->app->singleton(ResponseFileEnricher::class);
        $this->app->singleton(ResponseEntityEnricher::class);

        $this->app->singleton(ActionLinkHandler::class);
        $this->app->singleton(FileCitationHandler::class);
        $this->app->singleton(ContentLinkProcessor::class, function ($app) {
            return new ContentLinkProcessor(
                $app->make(ActionLinkHandler::class),
                $app->make(FileCitationHandler::class)
            );
        });
    }

    /**
     * Register UI theming services.
     */
    private function registerUiServices(): void
    {
        $this->app->singleton(ChatThemeFactoryInterface::class, function ($app) {
            $factoryClass = config('ai.ui.factory', ConfigChatThemeFactory::class);
            return new $factoryClass();
        });

        $this->app->alias(ChatThemeFactoryInterface::class, 'chat-theme-factory');

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
     * Register security services.
     */
    private function registerSecurityServices(): void
    {
        // KompoAuthAdapter depends on kompo/auth globals/classes that are not a
        // dependency of this package; bind it only when kompo/auth is present so
        // standalone installs fail closed via NullAuthAdapter instead of fataling.
        $this->app->singleton(AiAuthAdapterInterface::class, function ($app) {
            if (function_exists('globalSecurityBypass')
                || class_exists(\Kompo\Auth\Models\Teams\Permission::class)) {
                return new KompoAuthAdapter();
            }

            return new \Condoedge\Ai\Auth\NullAuthAdapter();
        });

        $this->app->singleton(TeamPathResolver::class);

        $this->app->singleton(AiSecurityService::class, function ($app) {
            return new AiSecurityService(
                $app->make(AiAuthAdapterInterface::class),
                $app->make(TeamPathResolver::class)
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
        Gate::policy(AiConversation::class, AiConversationPolicy::class);

        $this->loadRoutesFrom(__DIR__."/../routes/api.php");
        $this->loadRoutesFrom(__DIR__."/../routes/web.php");

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->loadJsonTranslationsFrom(__DIR__ . '/../resources/lang');

        $this->publishes([
            __DIR__ . '/../config/ai.php' => config_path('ai.php'),
        ], 'ai-config');

        $this->publishes([
            __DIR__ . '/../config/entities.php' => config_path('entities.php'),
        ], 'ai-entities');

        $this->publishes([
            __DIR__ . '/../config/larecipe.php' => config_path('larecipe.php'),
            __DIR__ . '/../resources/views' => resource_path('views'),
            __DIR__ . '/../public' => base_path('public'),
        ], 'ai-docs');

        $this->publishes([
            __DIR__ . '/../resources/lang' => $this->app->langPath('vendor/ai'),
        ], 'ai-lang');

        $this->publishes([
            __DIR__.'/../resources/js/chat-scroll.js' => public_path('vendor/condoedge/ai/js/chat-scroll.js'),
            __DIR__.'/../resources/js/chat-message-injector.js' => public_path('vendor/condoedge/ai/js/chat-message-injector.js'),
            __DIR__.'/../resources/css/ai-chat.css' => public_path('vendor/condoedge/ai/css/ai-chat.css'),
        ], 'ai-assets');

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
                \Condoedge\Ai\Console\Commands\SetupCommand::class,
                \Condoedge\Ai\Console\Commands\TestQuestionCommand::class,
                \Condoedge\Ai\Console\Commands\EnhanceConfigCommand::class,
                \Condoedge\Ai\Console\Commands\TestConversationCommand::class,
                \Condoedge\Ai\Console\Commands\EvalCommand::class,
            ]);
        }

        $this->registerSyncObservers();

        // Register FileModel plugins if the facade is available
        // (may not be available in isolated package tests)
        try {
            if (class_exists(\Condoedge\Utils\Facades\FileModel::class)) {
                FileModel::setPlugins([
                    FileProcessingPlugin::class,
                    FileAccessScopePlugin::class,
                ]);
            }
        } catch (\Throwable $e) {
            // FileModel facade not available (e.g., in package tests)
        }
    }

    /**
     * Register sync observers for related model changes
     *
     * This allows the AI system to stay in sync when related models change.
     * Observers are registered based on config/ai.php 'sync_triggers' configuration.
     *
     * @return void
     */
    protected function registerSyncObservers(): void
    {
        $syncTriggers = config('ai.sync_triggers', []);

        if (empty($syncTriggers)) {
            return;
        }

        $relatedModels = [];
        foreach ($syncTriggers as $config) {
            $relatedModels = array_merge($relatedModels, $config['on_related'] ?? []);
        }

        $relatedModels = array_unique($relatedModels);

        $observer = $this->app->make(\Condoedge\Ai\Observers\RelatedModelSyncObserver::class);
        $namespaces = config('ai.model_namespaces', ['App\\Models']);

        foreach ($relatedModels as $modelName) {
            $modelClass = $this->resolveModelClass($modelName, $namespaces);

            if ($modelClass && class_exists($modelClass)) {
                $modelClass::observe($observer);
            }
        }
    }

    /**
     * Resolve a model class name from short name
     *
     * @param string $modelName Short model name
     * @param array $namespaces Namespaces to search
     * @return string|null Full class name or null
     */
    private function resolveModelClass(string $modelName, array $namespaces): ?string
    {
        if (class_exists($modelName)) {
            return $modelName;
        }

        foreach ($namespaces as $namespace) {
            $fullClass = "{$namespace}\\{$modelName}";
            if (class_exists($fullClass)) {
                return $fullClass;
            }
        }

        return null;
    }

    /**
     * Resolve the additional collections that file search should union into its
     * results alongside the primary file_chunks collection.
     *
     * By default this includes the physical/documentation collection so local
     * docs ingested via `ai:ingest --docs` are returned by content/filename
     * search (B4). An explicit `ai.file_context.search_collections` list, when
     * configured, takes precedence. The primary collection is always excluded.
     *
     * @return array<string>
     */
    protected function resolveAdditionalSearchCollections(): array
    {
        $primary = config('ai.file_processing.collection', 'file_chunks');

        $configured = config('ai.file_context.search_collections');

        if (is_array($configured)) {
            $collections = $configured;
        } else {
            // Default: also search the physical docs collection.
            $physical = config('ai.file_context.physical_collection', 'documentation_chunks');
            $collections = $physical ? [$physical] : [];
        }

        // Exclude the primary collection (already searched) and any empties.
        return array_values(array_filter(
            array_unique($collections),
            fn ($c) => is_string($c) && trim($c) !== '' && $c !== $primary
        ));
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
            LlmResolver::class,
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
            ResponseEntityEnricher::class,
            ChatThemeFactoryInterface::class,
            ChatThemeInterface::class,
            ChatSettingsInterface::class,
            RegenerateMessageService::class,
            AiAuthAdapterInterface::class,
            KompoAuthAdapter::class,
            \Condoedge\Ai\Auth\NullAuthAdapter::class,
            TeamPathResolver::class,
            AiSecurityService::class,
            QueryUnderstandingService::class,
            \Condoedge\Ai\Services\Discovery\AiConfigEnhancer::class,
        ];
    }
}
