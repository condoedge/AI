<?php

declare(strict_types=1);

namespace Condoedge\Ai;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\LlmResolver;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Services\PolymorphicTargetResolver;
use Condoedge\Ai\Services\RelationshipSyncer;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\VectorStore\QdrantStore;
use Condoedge\Ai\GraphStore\Neo4jStoreFactory;
use Condoedge\Ai\EmbeddingProviders\OpenAiEmbeddingProvider;
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
use Condoedge\Ai\Services\Retrieval\TokenBudgeter;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Services\SemanticIndexer;
use Condoedge\Ai\Services\ScopeSemanticMatcher;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Services\Discovery\SchemaInspector;
use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;
use Condoedge\Ai\Services\Discovery\CypherQueryBuilderSpy;
use Condoedge\Ai\Services\Discovery\CypherPatternGenerator;
use Condoedge\Ai\Services\Discovery\PropertyDiscoverer;
use Condoedge\Ai\Services\Discovery\RelationshipDiscoverer;
use Condoedge\Ai\Services\Discovery\AliasGenerator;
use Condoedge\Ai\Services\Discovery\TraversalScopeGenerator;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Discovery\InheritanceResolver;
use Condoedge\Ai\Services\Context\EntityExtractor;
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
use Condoedge\Ai\Support\AiFeature;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\VectorStore\DisabledVectorStore;
use Condoedge\Ai\GraphStore\DisabledGraphStore;
use Condoedge\Ai\EmbeddingProviders\DisabledEmbeddingProvider;
use Condoedge\Ai\LlmProviders\DisabledLlmProvider;
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
        // Deep, list-safe merge (Laravel's mergeConfigFrom only merges top-level keys, so any host
        // section like `rag` used to hide every newer nested package default). See ConfigMerger.
        if (!($this->app instanceof \Illuminate\Contracts\Foundation\CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');
            $config->set('ai', \Condoedge\Ai\Support\ConfigMerger::merge(require __DIR__ . '/../config/ai.php', $config->get('ai', [])));
        }

        $this->app->singleton(\Condoedge\Ai\Support\AiLogger::class);

        // Pipeline observation seam (Phase 4.1): the default observer of every
        // answerQuestion() turn is a no-op; callers pass options['observer'] to
        // record (the chat use case's MessageTraceObserver, the eval harness).
        $this->app->bindIf(
            \Condoedge\Ai\Contracts\PipelineObserverInterface::class,
            \Condoedge\Ai\Support\NullPipelineObserver::class
        );

        // Master switch off (AI_ENABLED=false): the four ways out of the process are no-ops, at
        // resolve time, so no caller can reach a server or a provider and none needs a key.
        $this->app->singleton(VectorStoreInterface::class, function ($app) {
            if (!AiFeature::enabled()) {
                return new DisabledVectorStore();
            }

            $defaultStore = config('ai.vector.default', 'qdrant');

            return match ($defaultStore) {
                'qdrant' => new QdrantStore(config('ai.vector.qdrant')),
                default => throw new \RuntimeException("Unsupported vector store: {$defaultStore}")
            };
        });

        // Factory auto-selects the Bolt or HTTP driver based on URI scheme.
        $this->app->singleton(GraphStoreInterface::class, function ($app) {
            if (!AiFeature::enabled()) {
                return new DisabledGraphStore();
            }

            $defaultStore = config('ai.graph.default', 'neo4j');

            return match ($defaultStore) {
                'neo4j' => Neo4jStoreFactory::create(config('ai.graph.neo4j')),
                default => throw new \RuntimeException("Unsupported graph store: {$defaultStore}")
            };
        });

        $this->app->singleton(EmbeddingProviderInterface::class, function ($app) {
            if (!AiFeature::enabled()) {
                return new DisabledEmbeddingProvider();
            }

            $defaultProvider = config('ai.embedding.default', 'openai');

            $provider = match ($defaultProvider) {
                'openai' => new OpenAiEmbeddingProvider(config('ai.embedding.openai')),
                default => throw new \RuntimeException("Unsupported embedding provider: {$defaultProvider}")
            };

            // Wrap with memoization: same text is embedded only once per request
            return new \Condoedge\Ai\EmbeddingProviders\MemoizedEmbeddingProvider($provider);
        });

        $this->app->singleton(LlmProviderInterface::class, function ($app) {
            if (!AiFeature::enabled()) {
                return new DisabledLlmProvider();
            }

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

        // One shared memo cache for morph-value -> catalog-label resolution
        // across the per-entity and batch ingestion paths.
        $this->app->singleton(PolymorphicTargetResolver::class);

        $this->app->singleton(DataIngestionServiceInterface::class, function ($app) {
            return new DataIngestionService(
                graphStore: $app->make(GraphStoreInterface::class),
                relationshipSyncer: new RelationshipSyncer(
                    $app->make(GraphStoreInterface::class),
                    $app->make(PolymorphicTargetResolver::class),
                ),
            );
        });

        $this->app->alias(DataIngestionServiceInterface::class, DataIngestionService::class);

        // Decorates the base ingestion service.
        $this->app->singleton(\Condoedge\Ai\Services\BatchDataIngestionService::class, function ($app) {
            return new \Condoedge\Ai\Services\BatchDataIngestionService(
                inner: $app->make(\Condoedge\Ai\Contracts\DataIngestionServiceInterface::class),
                graphStore: $app->make(\Condoedge\Ai\Contracts\GraphStoreInterface::class),
                logger: $app->make(\Condoedge\Ai\Support\AiLogger::class),
                polymorphicResolver: $app->make(PolymorphicTargetResolver::class),
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
            );

            if (config('ai.query_understanding.enabled', true)) {
                $retriever->setQueryUnderstanding($app->make(QueryUnderstandingService::class));
            }

            return $retriever;
        });

        $this->app->alias(ContextRetrieverInterface::class, ContextRetriever::class);

        $this->app->singleton(SemanticPromptBuilder::class, function ($app) {
            return new SemanticPromptBuilder(
                tokenBudgeter: $app->make(TokenBudgeter::class),
                logger: $app->make(AiLogger::class)
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
                chunkStore: $app->make(ChunkStoreInterface::class),
                ownerResolver: $app->make(\Condoedge\Ai\Contracts\FileOwnerResolverInterface::class)
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

        // Attention digest (design 2026-07-23): the runner's only graph path is
        // the pipeline QueryExecutorInterface (security invariant by wiring);
        // the composer's single LLM call rides the response_generation role.
        $this->app->singleton(\Condoedge\Ai\Services\Digest\DigestRunner::class, function ($app) {
            return new \Condoedge\Ai\Services\Digest\DigestRunner(
                $app->make(QueryExecutorInterface::class)
            );
        });

        $this->app->singleton(\Condoedge\Ai\Services\Response\DigestResponseComposer::class, function ($app) {
            return new \Condoedge\Ai\Services\Response\DigestResponseComposer(
                $app->make(LlmResolver::class)->forRole('response_generation')
            );
        });

        // Conversational turns (small talk, questions about the assistant itself): ROLE
        // `conversational_reply` (ai.llm_roles); not configured: the response_generation role.
        $this->app->singleton(\Condoedge\Ai\Services\Response\ConversationalReplyComposer::class, function ($app) {
            $role = !empty(config('ai.llm_roles.conversational_reply')) ? 'conversational_reply' : 'response_generation';

            return new \Condoedge\Ai\Services\Response\ConversationalReplyComposer(
                $app->make(LlmResolver::class)->forRole($role)
            );
        });

        // Document-search probes (eval p15): ONE small focused call on the
        // query-understanding role's (small, fast) model, made by AiManager only on
        // turns where document search runs. Its whole domain input is the host's
        // description of its corpus under ai.file_context; with neither languages
        // nor a register configured it never calls the model.
        $this->app->singleton(\Condoedge\Ai\Services\Retrieval\DocumentProbeWriter::class, function ($app) {
            return new \Condoedge\Ai\Services\Retrieval\DocumentProbeWriter(
                $app->make(LlmResolver::class)->forRole('query_understanding'),
                [
                    'corpus_languages' => config('ai.file_context.corpus_languages', []),
                    'corpus_register' => config('ai.file_context.corpus_register', ''),
                ]
            );
        });

        // Passage selection (eval p21): ONE small focused call made by AiManager
        // after a knowledge turn's passages are chosen — it names the candidates
        // the search ranked too low that a correct answer still needs. ROLE
        // `passage_selector` (ai.llm_roles); not configured: the query-understanding
        // role's (small, fast) model. Its only domain inputs are the host's own
        // texts: the project description and the corpus register.
        $this->app->singleton(\Condoedge\Ai\Services\Retrieval\PassageSelector::class, function ($app) {
            $role = !empty(config('ai.llm_roles.passage_selector')) ? 'passage_selector' : 'query_understanding';

            return new \Condoedge\Ai\Services\Retrieval\PassageSelector(
                $app->make(LlmResolver::class)->forRole($role),
                [
                    'application' => config('ai.project.description', ''),
                    'corpus_register' => config('ai.file_context.corpus_register', ''),
                    'excerpt_chars' => config('ai.file_context.passage_selection.excerpt_chars'),
                    'selected_chars' => config('ai.file_context.passage_selection.selected_chars'),
                ]
            );
        });

        // DOCUMENT MAP (index time): one plain-language gist per stored chunk on the
        // query-understanding role's (small) model, and — for a document with parallel
        // top-level parts — ONE scope call saying which parts govern this application's
        // users. ROLE `document_scope` (ai.llm_roles); not configured: the
        // query-generation role's model — the ACCURATE one (measured 2026-10-07: the
        // small model answered "applies" for every part in 5 of 5 runs, the accurate
        // one separated them in 3 of 3). Its only domain input is the host's own
        // project description.
        //
        // Both providers are ISOLATED: the same models, failing under a circuit
        // breaker of their own (`llm_index`). A backfill is thousands of calls in a
        // row; behind the chat's breaker, a rate limit it ran into would stop
        // answering users (LlmResolver::isolated()).
        $this->app->singleton(\Condoedge\Ai\Services\Files\DocumentMapBuilder::class, function ($app) {
            $resolver = $app->make(LlmResolver::class);
            $scopeRole = !empty(config('ai.llm_roles.document_scope')) ? 'document_scope' : 'query_generation';

            return new \Condoedge\Ai\Services\Files\DocumentMapBuilder(
                $resolver->isolated('query_understanding', 'llm_index'),
                $resolver->isolated($scopeRole, 'llm_index'),
                $app->make(\Condoedge\Ai\Contracts\ChunkStoreInterface::class),
                ['application' => config('ai.project.description', '')]
            );
        });

        // DOCUMENT MAP (question time): ONE small call made after the plain document
        // search reads the question against the map of the admitted documents and
        // names the passages a complete answer relies on; it replaces the probe call
        // and the passage selection for mapped documents (DocumentMapTurn decides,
        // AiManager falls back to today's path whenever it does not apply). ROLE
        // `document_map_reader` (ai.llm_roles); not configured: the
        // query-understanding role's model (measured: the accurate model picked no
        // better at five times the cost of a turn).
        $this->app->singleton(\Condoedge\Ai\Services\Retrieval\DocumentMapReader::class, function ($app) {
            $role = !empty(config('ai.llm_roles.document_map_reader')) ? 'document_map_reader' : 'query_understanding';

            return new \Condoedge\Ai\Services\Retrieval\DocumentMapReader(
                $app->make(LlmResolver::class)->forRole($role),
                ['application' => config('ai.project.description', '')]
            );
        });
        $this->app->singleton(\Condoedge\Ai\Services\Retrieval\DocumentMapTurn::class, function ($app) {
            return new \Condoedge\Ai\Services\Retrieval\DocumentMapTurn(
                $app->make(\Condoedge\Ai\Services\Retrieval\DocumentMapReader::class),
                $app->make(FileContextProvider::class),
                [],
                $app->make(\Condoedge\Ai\Contracts\ChunkStoreInterface::class)
            );
        });

        // Clarity check (eval p15): ONE small focused call on the query-understanding
        // role's (small, fast) model, made by AiManager only for a first message that
        // asks for data and names no subject. Its whole domain input is the host's
        // own config: the entities (the kinds of records, by display name and
        // synonyms) and the calculations the question matches (the named
        // quantities the readings draw on first — matched with the request's
        // memoized embedding provider, so the question is not embedded again);
        // ai.understanding.clarify_enabled switches it off.
        $this->app->singleton(\Condoedge\Ai\Services\Understanding\ClarityChecker::class, function ($app) {
            return new \Condoedge\Ai\Services\Understanding\ClarityChecker(
                $app->make(LlmResolver::class)->forRole('query_understanding'),
                embedding: $app->make(EmbeddingProviderInterface::class)
            );
        });

        // Follow-up question chips: after a document-grounded answer, ONE small focused
        // call proposes the next questions and code verifies them against the retrieved
        // passages. The attacher makes it only on such turns
        // (ai.attached_actions.follow_ups.knowledge switches it off); the clarify and
        // digest follow-up chips need no model at all.
        //
        // ROLE `follow_up_writer` (ai.llm_roles). Not configured: the query-generation
        // role's model — the ACCURATE one. Judging what a person would naturally ask next
        // is not a small-model job (measured live 2026-10-06 on six questions: the
        // query-understanding model kept 2 of 3 on-topic at best and left most turns
        // without a chip; the query-generation model wrote 16 of 17 on-topic and declined
        // the off-topic excerpts, in 1.3-1.9 s). A host that points the role at a small
        // model sets ai.attached_actions.follow_ups.model_declines to false: code gates.
        $this->app->singleton(\Condoedge\Ai\Services\Response\FollowUpWriter::class, function ($app) {
            $role = !empty(config('ai.llm_roles.follow_up_writer')) ? 'follow_up_writer' : 'query_generation';

            // The request's memoized embedding provider: a relevance floor, when one is
            // set, compares each follow-up to the user's question (already embedded).
            return new \Condoedge\Ai\Services\Response\FollowUpWriter(
                $app->make(LlmResolver::class)->forRole($role),
                $app->make(EmbeddingProviderInterface::class),
                (bool) config('ai.attached_actions.follow_ups.model_declines', true)
            );
        });

        $this->app->singleton(\Condoedge\Ai\Services\Actions\Attach\ActionAttacher::class, function ($app) {
            return new \Condoedge\Ai\Services\Actions\Attach\ActionAttacher(
                $app->make(\Condoedge\Ai\Services\Actions\AiActionRegistry::class),
                $app->make(\Condoedge\Ai\Services\Response\FollowUpWriter::class),
                new \Condoedge\Ai\Services\Actions\Attach\NavigationPages($app->make(LlmResolver::class)->forRole('navigation'))
            );
        });

        // Tasks (ai.tasks): one small focused call per task turn, ROLE `task_orchestrator`
        // (ai.llm_roles); not configured: the query-understanding role's (small) model.
        // Grounded on the task's own articles through the file search.
        $this->app->singleton(\Condoedge\Ai\Services\Tasks\TaskStepCaller::class, function ($app) {
            $role = !empty(config('ai.llm_roles.task_orchestrator')) ? 'task_orchestrator' : 'query_understanding';

            return new \Condoedge\Ai\Services\Tasks\TaskStepCaller(
                $app->make(LlmResolver::class)->forRole($role),
                $app->make(FileSearchService::class)
            );
        });
        // The chat turn gets the orchestrator only when tasks are on with a definition:
        // otherwise null, and every turn runs exactly as before.
        $this->app->when(\Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase::class)
            ->needs(\Condoedge\Ai\Services\Tasks\TaskOrchestrator::class)
            ->give(fn ($app) => \Condoedge\Ai\Services\Tasks\TaskOrchestrator::configured()
                ? $app->make(\Condoedge\Ai\Services\Tasks\TaskOrchestrator::class)
                : null);
        // The reply to an answer that offered a task: one small call, only on the message right after the offer.
        // ROLE `task_offer` (ai.llm_roles); not configured: the task step's role, else query understanding.
        $this->app->singleton(\Condoedge\Ai\Services\Tasks\TaskOffer::class, function ($app) {
            $role = array_values(array_filter(['task_offer', 'task_orchestrator'], fn (string $role) => !empty(config("ai.llm_roles.{$role}"))))[0]
                ?? 'query_understanding';

            return new \Condoedge\Ai\Services\Tasks\TaskOffer($app->make(LlmResolver::class)->forRole($role));
        });
        $this->app->when(\Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase::class)
            ->needs(\Condoedge\Ai\Services\Tasks\TaskOffer::class)
            ->give(fn ($app) => \Condoedge\Ai\Services\Tasks\TaskOrchestrator::configured()
                ? $app->make(\Condoedge\Ai\Services\Tasks\TaskOffer::class)
                : null);

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
                responseEntityEnricher: $app->make(ResponseEntityEnricher::class),
                exportComposer: $app->make(\Condoedge\Ai\Services\Response\ExportResponseComposer::class),
                digestRunner: $app->make(\Condoedge\Ai\Services\Digest\DigestRunner::class),
                digestComposer: $app->make(\Condoedge\Ai\Services\Response\DigestResponseComposer::class),
                probeWriter: $app->make(\Condoedge\Ai\Services\Retrieval\DocumentProbeWriter::class),
                clarityChecker: $app->make(\Condoedge\Ai\Services\Understanding\ClarityChecker::class),
                actionAttacher: $app->make(\Condoedge\Ai\Services\Actions\Attach\ActionAttacher::class),
                passageSelector: $app->make(\Condoedge\Ai\Services\Retrieval\PassageSelector::class),
                documentMapTurn: $app->make(\Condoedge\Ai\Services\Retrieval\DocumentMapTurn::class),
                // Uploaded files are linked to a record: the question's scope and
                // the words that say whose document a passage is.
                documentScopeResolver: new \Condoedge\Ai\Services\Retrieval\DocumentScopeResolver(
                    $app->make(\Condoedge\Ai\Services\Files\RecordAncestry::class)
                ),
                documentOwnerLabels: new \Condoedge\Ai\Services\Retrieval\DocumentOwnerLabels(
                    $app->make(QueryExecutorInterface::class)
                ),
                conversationalComposer: $app->make(\Condoedge\Ai\Services\Response\ConversationalReplyComposer::class)
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
        $this->registerActionServices();
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
            // (The corpus languages/register under ai.file_context are no longer QU
            // input: the document-search probes are written by DocumentProbeWriter.
            // Nor is ai.understanding.clarify_enabled: the clarify judgment is the
            // focused ClarityChecker call's, not a QU output.)
            $quConfig = config('ai.query_understanding', []);
            // The QU output budget lives under ai.understanding too (Task 1.17).
            // It is authoritative over a legacy ai.query_understanding.max_tokens
            // left in a host's published config (its 500 default truncated the reply).
            $quConfig['max_tokens'] = (int) config('ai.understanding.max_tokens', 1200);

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

        $this->app->singleton(TraversalScopeGenerator::class);

        $this->app->singleton(InheritanceResolver::class);

        $this->app->singleton(EntityAutoDiscovery::class, function ($app) {
            return new EntityAutoDiscovery(
                schema: $app->make(SchemaInspector::class),
                scopeAdapter: $app->make(CypherScopeAdapter::class),
                relationships: $app->make(RelationshipDiscoverer::class),
                properties: $app->make(PropertyDiscoverer::class),
                aliases: $app->make(AliasGenerator::class),
                traversalGenerator: $app->make(TraversalScopeGenerator::class),
                inheritanceResolver: $app->make(InheritanceResolver::class)
            );
        });

        $this->app->singleton(\Condoedge\Ai\Services\Discovery\AiConfigEnhancer::class, function ($app) {
            return new \Condoedge\Ai\Services\Discovery\AiConfigEnhancer(
                llm: $app->make(\Condoedge\Ai\Services\LlmResolver::class)->forRole('config_enhancement')
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
    }

    /**
     * Register context management services
     *
     * @return void
     */
    private function registerContextServices(): void
    {
        // Register EntityExtractor (structural: labels of an executed cypher)
        $this->app->singleton(EntityExtractor::class);

        // Register ConversationContextManager with dependencies
        $this->app->singleton(ConversationContextManager::class, function ($app) {
            return new ConversationContextManager(
                $app->make(EntityExtractor::class)
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

        // UPLOADED files are linked to the record they are filed on. The default
        // reads the host's entity configuration (File entity: morph parent +
        // declared belongs-to relationships) and needs no host code; a host
        // whose files are linked another way binds its own resolver.
        $this->app->singleton(\Condoedge\Ai\Services\Files\RecordAncestry::class);
        $this->app->singletonIf(
            \Condoedge\Ai\Contracts\FileOwnerResolverInterface::class,
            fn ($app) => new \Condoedge\Ai\Services\Files\ConfigFileOwnerResolver(
                $app->make(\Condoedge\Ai\Services\Files\RecordAncestry::class)
            )
        );

        $this->app->singleton(FileContextProvider::class, function ($app) {
            return new FileContextProvider(
                $app->make(FileSearchService::class),
                $app->make(FileAccessResolverInterface::class),
                null,
                $app->make(\Condoedge\Ai\Contracts\FileContextEnricherInterface::class),
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

        // Scoped: Laravel drops it after each request and queue job, so a revoked membership is read again.
        $this->app->scoped(\Condoedge\Ai\Services\Security\RowScope::MEMO, fn () => new \ArrayObject());

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
        // Master switch off (AI_ENABLED=false): background features forced off, cached config included.
        \Condoedge\Ai\Support\AiFeature::applyTo($this->app->make('config'));

        Gate::policy(AiConversation::class, AiConversationPolicy::class);

        // The admin conversations / feedback page shows OTHER people's conversations:
        // denied by default. A host grants it with its own Gate::define (last define
        // wins, and a host that defined it first is left alone).
        if (!Gate::has(\Condoedge\Ai\Services\Admin\ConversationInsights::ABILITY)) {
            Gate::define(\Condoedge\Ai\Services\Admin\ConversationInsights::ABILITY, fn ($user = null) => false);
        }

        // Master switch off: no chat, export, admin or health route at all.
        if (\Condoedge\Ai\Support\AiFeature::enabled()) {
            $this->loadRoutesFrom(__DIR__."/../routes/api.php");
            $this->loadRoutesFrom(__DIR__."/../routes/web.php");
        }

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
            // chat-scroll.js was retired: scrolling now comes from the shared chat kit
            // (condoedge/utils ChatScripts / window.KompoChatKit), injected by the komponents
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
                \Condoedge\Ai\Console\Commands\LinkFilesCommand::class,
                \Condoedge\Ai\Console\Commands\IndexSemanticCommand::class,
                \Condoedge\Ai\Console\Commands\IndexScopesCommand::class,
                \Condoedge\Ai\Console\Commands\IndexContextCommand::class,
                \Condoedge\Ai\Console\Commands\ValidateConfigCommand::class,
                \Condoedge\Ai\Console\Commands\SetupCommand::class,
                \Condoedge\Ai\Console\Commands\EnhanceConfigCommand::class,
                \Condoedge\Ai\Console\Commands\DocumentMapCommand::class,
                // ai:eval lives in the dev-only condoedge/ai-eval package.
            ]);
        }

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
            EntityAutoDiscovery::class,
            InheritanceResolver::class,
            AiChatServiceInterface::class,
            AiChatService::class,
            EntityExtractor::class,
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
