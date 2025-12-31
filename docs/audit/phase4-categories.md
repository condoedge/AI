# Phase 4: Functional Categorization

> Architecture analysis based on BEHAVIOR, not folder structure.

## 1. Category Definitions

### 1.1 Chat Orchestration
**Purpose:** Coordinates the end-to-end chat flow from user input to response delivery.
**Responsibilities:**
- Managing conversation lifecycle (create, select, archive, delete)
- Coordinating between context retrieval, query generation, and response generation
- Handling message submission and regeneration
- Managing conversation state and metadata

### 1.2 Context Retrieval
**Purpose:** Gathers relevant context for AI to understand and answer queries.
**Responsibilities:**
- Semantic search across vector stores
- Graph traversal for related entities
- Entity extraction from user queries
- Reference resolution (pronouns, implicit references)
- File context gathering
- Scope matching and semantic context selection

### 1.3 Memory/History
**Purpose:** Manages conversation history and entity memory across sessions.
**Responsibilities:**
- Storing and retrieving conversation messages
- Tracking entity mentions across conversation
- Managing conversation context windows
- Persisting user preferences and settings

### 1.4 Prompt Construction
**Purpose:** Builds LLM prompts with appropriate context sections.
**Responsibilities:**
- Assembling prompt sections in priority order
- Including schema, patterns, and rules
- Adding conversation context and detected entities
- Managing prompt token budgets

### 1.5 Model Interaction
**Purpose:** Communicates with LLMs for query generation and response creation.
**Responsibilities:**
- Making API calls to OpenAI/Anthropic
- Generating embeddings for semantic search
- Handling streaming responses
- Managing model-specific configurations

### 1.6 Persistence
**Purpose:** Handles storage operations (Neo4j, Qdrant, MySQL).
**Responsibilities:**
- Graph database operations (create, read, update, delete nodes/relationships)
- Vector database operations (upsert, search, delete)
- Entity ingestion with compensating transactions
- File chunk storage and retrieval

### 1.7 Validation
**Purpose:** Validates data, queries, and configurations.
**Responsibilities:**
- Query safety validation (injection prevention)
- Entity configuration validation
- Input sanitization
- Schema validation

### 1.8 Observability
**Purpose:** Monitoring, logging, analytics, and resilience.
**Responsibilities:**
- Query logging and analytics
- Health checking
- Circuit breaker pattern implementation
- Rate limiting and retry policies
- Performance metrics

### 1.9 UI Components
**Purpose:** Renders chat interfaces and handles user interactions.
**Responsibilities:**
- Chat panel layout and styling
- Message bubbles and rich data display
- Conversation list and search
- Settings and help modals
- Theming and visual customization

### 1.10 Configuration
**Purpose:** Manages settings, entity configs, and system configuration.
**Responsibilities:**
- Service provider registration
- Entity auto-discovery
- User preference management
- Theme configuration
- Chat settings resolution

---

## 2. File Assignments

### 2.1 Chat Orchestration

| File | Primary Behavior |
|------|------------------|
| `src/Services/Chat/AiChatService.php` | Main chat flow coordinator - receives messages, gathers context, generates queries, produces responses |
| `src/Services/Chat/AiChatServiceInterface.php` | Contract for chat service |
| `src/Services/Chat/AiChatMessage.php` | Chat message DTO |
| `src/Services/Chat/AiChatResponseData.php` | Response data structure |
| `src/Services/Chat/AmbiguityDetector.php` | Detects ambiguous queries for clarification |
| `src/Services/AiManager.php` | Facade-level orchestrator aggregating all AI services |
| `src/Facades/AI.php` | Static facade for AiManager |

**Total: 7 files**

### 2.2 Context Retrieval

| File | Primary Behavior |
|------|------------------|
| `src/Services/ContextRetriever.php` | Main context retrieval - vector search + graph traversal |
| `src/Contracts/ContextRetrieverInterface.php` | Contract for context retrieval |
| `src/Services/Context/ConversationContextManager.php` | Manages conversation-level context (entity memory, references) |
| `src/Services/Context/EntityExtractor.php` | Extracts entity mentions from user messages |
| `src/Services/Context/ReferenceResolver.php` | Resolves pronouns and implicit references |
| `src/Services/Context/FileContextProvider.php` | Provides file-based context for queries |
| `src/Services/Context/FileAccessResolver.php` | Resolves file access permissions |
| `src/Contracts/FileAccessResolverInterface.php` | Contract for file access resolution |
| `src/Services/SemanticMatcher.php` | Matches queries to entities semantically |
| `src/Services/SemanticContextSelector.php` | Selects relevant context based on semantic similarity |
| `src/Services/ScopeSemanticMatcher.php` | Matches query scopes to entity scopes |
| `src/Services/FileSearchService.php` | Searches for relevant files/chunks |
| `src/Facades/FileSearch.php` | Facade for file search |

**Total: 13 files**

### 2.3 Memory/History

| File | Primary Behavior |
|------|------------------|
| `src/Models/AiConversation.php` | Conversation entity with messages relationship |
| `src/Models/AiMessage.php` | Individual message storage |
| `src/Models/AiUserSetting.php` | User preferences persistence |

**Total: 3 files**

### 2.4 Prompt Construction

| File | Primary Behavior |
|------|------------------|
| `src/Services/SemanticPromptBuilder.php` | Orchestrates prompt assembly from sections |
| `src/Contracts/PromptSectionInterface.php` | Contract for prompt sections |
| `src/Services/PromptSections/BasePromptSection.php` | Base class for prompt sections |
| `src/Services/PromptSections/ConversationContextSection.php` | Adds conversation history to prompt |
| `src/Services/PromptSections/CurrentUserContextSection.php` | Adds current user context |
| `src/Services/PromptSections/DetectedEntitiesSection.php` | Adds detected entities |
| `src/Services/PromptSections/DetectedScopesSection.php` | Adds detected scopes |
| `src/Services/PromptSections/ExampleEntitiesSection.php` | Adds example entity instances |
| `src/Services/PromptSections/FileContextSection.php` | Adds file context |
| `src/Services/PromptSections/GenericContextSection.php` | Generic context wrapper |
| `src/Services/PromptSections/PatternLibrarySection.php` | Adds query patterns |
| `src/Services/PromptSections/ProjectContextSection.php` | Adds project-specific context |
| `src/Services/PromptSections/QueryRulesSection.php` | Adds Cypher query rules |
| `src/Services/PromptSections/QuestionSection.php` | Adds user question |
| `src/Services/PromptSections/RelationshipsSection.php` | Adds schema relationships |
| `src/Services/PromptSections/SchemaSection.php` | Adds Neo4j schema |
| `src/Services/PromptSections/SimilarQueriesSection.php` | Adds similar past queries |
| `src/Services/PromptSections/TaskInstructionsSection.php` | Adds task instructions |
| `src/Services/PatternLibrary.php` | Stores and matches query patterns |
| `src/Contracts/SectionModuleInterface.php` | Contract for section modules |
| `src/Services/HasInternalModules.php` | Trait for module management |

**Total: 21 files**

### 2.5 Model Interaction

| File | Primary Behavior |
|------|------------------|
| `src/Services/QueryGenerator.php` | Generates Cypher from natural language via LLM |
| `src/Contracts/QueryGeneratorInterface.php` | Contract for query generation |
| `src/Services/QueryExecutor.php` | Executes generated Cypher queries |
| `src/Contracts/QueryExecutorInterface.php` | Contract for query execution |
| `src/Services/ResponseGenerator.php` | Generates natural language responses via LLM |
| `src/Contracts/ResponseGeneratorInterface.php` | Contract for response generation |
| `src/Services/ResponseSections/BaseResponseSection.php` | Base class for response sections |
| `src/Services/ResponseSections/GuidelinesSection.php` | Response guidelines |
| `src/Services/ResponseSections/OriginalQuestionSection.php` | Original question for context |
| `src/Services/ResponseSections/PrivacyAndSecurityGuidelinesSection.php` | Security guidelines |
| `src/Services/ResponseSections/QueryInfoSection.php` | Query information section |
| `src/Services/ResponseSections/ResponseProjectContextSection.php` | Project context for response |
| `src/Services/ResponseSections/ResponseTaskSection.php` | Task instructions for response |
| `src/Services/ResponseSections/ResultsDataSection.php` | Query results data |
| `src/Services/ResponseSections/StatisticsSection.php` | Statistics section |
| `src/Services/ResponseSections/SystemPromptSection.php` | System prompt section |
| `src/Contracts/ResponseSectionInterface.php` | Contract for response sections |
| `src/Services/Response/ResponseFileEnricher.php` | Enriches responses with file references |
| `src/LlmProviders/OpenAiLlmProvider.php` | OpenAI API client for completions |
| `src/LlmProviders/AnthropicLlmProvider.php` | Anthropic API client for completions |
| `src/Contracts/LlmProviderInterface.php` | Contract for LLM providers |
| `src/EmbeddingProviders/OpenAiEmbeddingProvider.php` | OpenAI embeddings API client |
| `src/EmbeddingProviders/AnthropicEmbeddingProvider.php` | Anthropic embeddings API client |
| `src/Contracts/EmbeddingProviderInterface.php` | Contract for embedding providers |

**Total: 24 files**

### 2.6 Persistence

| File | Primary Behavior |
|------|------------------|
| `src/Services/DataIngestionService.php` | Dual-store ingestion with compensating transactions |
| `src/Contracts/DataIngestionServiceInterface.php` | Contract for data ingestion |
| `src/GraphStore/Neo4jStore.php` | Neo4j graph database operations |
| `src/Contracts/GraphStoreInterface.php` | Contract for graph stores |
| `src/VectorStore/QdrantStore.php` | Qdrant vector database operations |
| `src/Contracts/VectorStoreInterface.php` | Contract for vector stores |
| `src/Services/QdrantChunkStore.php` | File chunk storage in Qdrant |
| `src/Contracts/ChunkStoreInterface.php` | Contract for chunk storage |
| `src/Services/SemanticIndexer.php` | Indexes entities for semantic search |
| `src/Services/SemanticChunker.php` | Chunks text for vector storage |
| `src/Contracts/FileChunkerInterface.php` | Contract for file chunking |
| `src/Services/FileProcessor.php` | Processes files for ingestion |
| `src/Contracts/FileProcessorInterface.php` | Contract for file processing |
| `src/Services/FileExtractorRegistry.php` | Registry of file extractors |
| `src/Services/Extractors/TextExtractor.php` | Extracts text from .txt files |
| `src/Services/Extractors/MarkdownExtractor.php` | Extracts from .md files |
| `src/Services/Extractors/PdfExtractor.php` | Extracts from PDF files |
| `src/Services/Extractors/DocxExtractor.php` | Extracts from DOCX files |
| `src/Contracts/FileExtractorInterface.php` | Contract for file extractors |
| `src/Services/Files/PhysicalFileIndexer.php` | Indexes physical files |
| `src/Jobs/IngestEntityJob.php` | Queue job for entity ingestion |
| `src/Jobs/RemoveEntityJob.php` | Queue job for entity removal |
| `src/Jobs/SyncEntityJob.php` | Queue job for entity sync |
| `src/Observers/RelatedModelSyncObserver.php` | Model observer for auto-sync |
| `src/Models/Plugins/FileProcessingPlugin.php` | File model plugin for processing |
| `src/Domain/Contracts/Nodeable.php` | Contract for entities storable in graph |
| `src/Domain/Contracts/Searchable.php` | Contract for searchable entities |
| `src/Domain/Traits/HasNodeableConfig.php` | Trait for nodeable configuration |
| `src/Domain/ValueObjects/GraphConfig.php` | Graph storage configuration |
| `src/Domain/ValueObjects/VectorConfig.php` | Vector storage configuration |
| `src/Domain/ValueObjects/NodeableConfig.php` | Combined nodeable configuration |
| `src/Domain/ValueObjects/RelationshipConfig.php` | Relationship configuration |
| `src/DTOs/FileChunk.php` | File chunk data structure |
| `src/DTOs/ProcessingResult.php` | Processing result data structure |

**Total: 34 files**

### 2.7 Validation

| File | Primary Behavior |
|------|------------------|
| `src/GraphStore/CypherSanitizer.php` | Sanitizes Cypher queries for safety |
| `src/Services/Security/PromptContextBuilder.php` | Builds safe prompt context |
| `src/Services/Security/SensitiveDataSanitizer.php` | Sanitizes sensitive data from logs |
| `src/Services/Security/TeamFilteredQuery.php` | Applies team-based security filters |
| `src/Services/Security/AccessLevelResolver.php` | Resolves user access levels |
| `src/Exceptions/CypherInjectionException.php` | Cypher injection detected |
| `src/Exceptions/UnsafeQueryException.php` | Unsafe query detected |
| `src/Exceptions/ReadOnlyViolationException.php` | Write attempted in read-only mode |
| `src/Exceptions/QueryValidationException.php` | Query validation failed |

**Total: 9 files**

### 2.8 Observability

| File | Primary Behavior |
|------|------------------|
| `src/Models/AiQueryLog.php` | Query logging model |
| `src/Services/Analytics/QueryAnalytics.php` | Query analytics and metrics |
| `src/Services/Cache/QueryResultCache.php` | Caches query results |
| `src/Services/Resilience/CircuitBreaker.php` | Circuit breaker for fault tolerance |
| `src/Services/Resilience/RateLimiter.php` | Rate limiting for API calls |
| `src/Services/Resilience/RetryPolicy.php` | Retry policy with backoff |
| `src/Services/Learning/QueryLearner.php` | Learns from successful queries |
| `src/Http/Controllers/HealthController.php` | Health check endpoint |
| `src/Exceptions/CircuitBreakerOpenException.php` | Circuit breaker open exception |
| `src/Exceptions/DataConsistencyException.php` | Data consistency exception |
| `src/Exceptions/QueryExecutionException.php` | Query execution exception |
| `src/Exceptions/QueryGenerationException.php` | Query generation exception |
| `src/Exceptions/QueryTimeoutException.php` | Query timeout exception |
| `src/Console/Commands/DiagnoseCommand.php` | Diagnostic command |

**Total: 14 files**

### 2.9 UI Components

| File | Primary Behavior |
|------|------------------|
| `src/Kompo/AiChatPanel.php` | Main chat panel with conversation management |
| `src/Kompo/AiChatFloating.php` | Floating chat widget |
| `src/Kompo/ChatMessageForm.php` | Message input form |
| `src/Kompo/ConversationListQuery.php` | Conversation list query component |
| `src/Kompo/Modals/ChatHelpModal.php` | Help modal |
| `src/Kompo/Modals/ChatSettingsModal.php` | Settings modal |
| `src/Kompo/Modals/EditMessageModal.php` | Message edit modal |
| `src/Kompo/Modals/FilePreviewModal.php` | File preview modal |
| `src/Kompo/Traits/HasAvatars.php` | Avatar rendering trait |
| `src/Kompo/Traits/HasTypingIndicator.php` | Typing indicator trait |
| `src/Kompo/Traits/HasChatTheme.php` | Theme access trait |
| `src/Kompo/Traits/HasChatSettings.php` | Settings access trait |
| `src/Kompo/Traits/HasMethodsAsProperties.php` | Property accessor trait |
| `src/Services/UI/SafeMarkdownRenderer.php` | Safe markdown rendering |
| `src/Services/UI/AbstractChatTheme.php` | Base theme class |
| `src/Services/UI/ChatThemeInterface.php` | Theme contract |
| `src/Services/UI/Themes/ConfigTheme.php` | Config-based theme |
| `src/Services/UI/Themes/GreenTheme.php` | Green theme preset |
| `src/Services/UI/Themes/IndigoTheme.php` | Indigo theme preset |
| `src/Http/Controllers/ConversationController.php` | Conversation API endpoints |

**Total: 20 files**

### 2.10 Configuration

| File | Primary Behavior |
|------|------------------|
| `src/AiServiceProvider.php` | Service container registration |
| `src/Services/Settings/AbstractChatSettings.php` | Base settings class |
| `src/Services/Settings/ChatSettingsInterface.php` | Settings contract |
| `src/Services/Settings/UserChatSettings.php` | User-specific settings with priority chain |
| `src/Services/UI/ChatThemeFactoryInterface.php` | Theme factory contract |
| `src/Services/UI/ConfigChatThemeFactory.php` | Config-based theme factory |
| `src/Services/UI/UserChatThemeFactory.php` | User preference theme factory |
| `src/Services/Discovery/EntityAutoDiscovery.php` | Auto-discovers entity configurations |
| `src/Services/Discovery/SchemaInspector.php` | Inspects database schema |
| `src/Services/Discovery/PropertyDiscoverer.php` | Discovers entity properties |
| `src/Services/Discovery/RelationshipDiscoverer.php` | Discovers relationships |
| `src/Services/Discovery/AliasGenerator.php` | Generates semantic aliases |
| `src/Services/Discovery/EmbedFieldDetector.php` | Detects embeddable fields |
| `src/Services/Discovery/CypherScopeAdapter.php` | Adapts scopes for Cypher |
| `src/Services/Discovery/CypherPatternGenerator.php` | Generates Cypher patterns |
| `src/Services/Discovery/CypherQueryBuilderSpy.php` | Spies on query building |
| `src/Services/Discovery/TraversalScopeGenerator.php` | Generates traversal scopes |
| `src/Services/Discovery/InheritanceResolver.php` | Resolves model inheritance |
| `src/Console/Commands/DiscoverEntitiesCommand.php` | Entity discovery command |
| `src/Console/Commands/IngestEntitiesCommand.php` | Entity ingestion command |
| `src/Console/Commands/SyncRelationshipsCommand.php` | Relationship sync command |
| `src/Console/Commands/ProcessFilesCommand.php` | File processing command |
| `src/Console/Commands/IndexSemanticCommand.php` | Semantic indexing command |
| `src/Console/Commands/IndexScopesCommand.php` | Scope indexing command |
| `src/Console/Commands/IndexContextCommand.php` | Context indexing command |
| `src/Console/Commands/ValidateConfigCommand.php` | Config validation command |
| `src/Console/Commands/LearnFromLogsCommand.php` | Pattern learning command |

**Total: 27 files**

---

## 3. Orphan Files

Files that fit no category cleanly:

| File | Issue |
|------|-------|
| None identified | All 172 files categorized |

---

## 4. Cross-Category Leaks

Files doing multiple concerns that should be separated:

### 4.1 High Severity (Multiple Core Concerns)

| File | Categories Mixed | Issues |
|------|-----------------|--------|
| `src/Services/Chat/AiChatService.php` | Chat Orchestration + Context Retrieval + Memory | God class orchestrating entire flow; should delegate more |
| `src/Kompo/AiChatPanel.php` | UI + Chat Orchestration + Memory | UI component handles conversation CRUD; mixes presentation with business logic |
| `src/Services/DataIngestionService.php` | Persistence + Validation | Contains validation logic inline with persistence; should use separate validator |
| `src/AiServiceProvider.php` | Configuration + ALL categories | Massive provider registering everything; should be split into feature providers |

### 4.2 Medium Severity (2 Concerns Mixed)

| File | Categories Mixed | Issues |
|------|-----------------|--------|
| `src/Kompo/ChatMessageForm.php` | UI + Chat Orchestration | Form handles message submission and response streaming |
| `src/Services/QueryGenerator.php` | Model Interaction + Prompt Construction | Builds prompts internally rather than using SemanticPromptBuilder |
| `src/Services/ResponseGenerator.php` | Model Interaction + Prompt Construction | Builds response prompts internally |
| `src/Services/ContextRetriever.php` | Context Retrieval + Validation | Contains access control checks inline |
| `src/GraphStore/Neo4jStore.php` | Persistence + Validation | Has CypherSanitizer integration inline |
| `src/Services/Context/ConversationContextManager.php` | Context Retrieval + Memory | Manages both context and entity memory |
| `src/Services/Chat/AiChatMessage.php` | Memory + Persistence | DTO that also handles database operations |

### 4.3 Low Severity (Minor Overlap)

| File | Categories Mixed | Notes |
|------|-----------------|-------|
| `src/Services/SemanticPromptBuilder.php` | Prompt Construction + Configuration | Loads config directly |
| `src/Services/PatternLibrary.php` | Prompt Construction + Observability | Has learning/analytics hooks |
| `src/Http/Controllers/ConversationController.php` | UI + Memory | API controller accessing models directly |
| `src/Services/Discovery/EntityAutoDiscovery.php` | Configuration + Validation | Validates discovered configs |

---

## 5. Boundary Violations

Dependencies flowing in wrong directions:

### 5.1 Core Services Depending on UI

| From | To | Violation |
|------|-----|-----------|
| None detected | - | Clean separation |

### 5.2 Persistence Layer Leaking to UI

| From | To | Violation |
|------|-----|-----------|
| `src/Kompo/AiChatPanel.php` | `src/Models/AiConversation.php` | UI directly queries/modifies models |
| `src/Kompo/ChatMessageForm.php` | `src/Models/AiMessage.php` | Form creates messages directly |
| `src/Kompo/Modals/ChatSettingsModal.php` | `src/Models/AiUserSetting.php` | Modal writes to database |

### 5.3 Circular Dependencies

| Component A | Component B | Issue |
|-------------|-------------|-------|
| `AiChatService` | `ContextRetriever` | Chat calls Context, Context may need Chat history |
| `SemanticPromptBuilder` | `PatternLibrary` | Builder uses Library, Library may reference Builder |
| `QueryGenerator` | `SemanticPromptBuilder` | Generator builds prompts but also has internal prompt logic |

### 5.4 Configuration Bypassing

| File | Issue |
|------|-------|
| `src/Services/Chat/AiChatService.php` | Reads config directly instead of through settings service |
| `src/VectorStore/QdrantStore.php` | Reads config in constructor instead of injection |
| `src/GraphStore/Neo4jStore.php` | Reads config in constructor instead of injection |

### 5.5 Missing Interface Usage

| File | Issue |
|------|-------|
| `src/Kompo/AiChatPanel.php` | Uses concrete `AiConversation` instead of repository interface |
| `src/Services/Analytics/QueryAnalytics.php` | Uses concrete `AiQueryLog` instead of interface |
| `src/Services/Learning/QueryLearner.php` | Uses concrete models instead of interfaces |

---

## 6. Summary Statistics

| Category | File Count | Percentage |
|----------|------------|------------|
| Persistence | 34 | 19.8% |
| Configuration | 27 | 15.7% |
| Model Interaction | 24 | 14.0% |
| Prompt Construction | 21 | 12.2% |
| UI Components | 20 | 11.6% |
| Observability | 14 | 8.1% |
| Context Retrieval | 13 | 7.6% |
| Validation | 9 | 5.2% |
| Chat Orchestration | 7 | 4.1% |
| Memory/History | 3 | 1.7% |
| **Total** | **172** | **100%** |

---

## 7. Architectural Observations

### 7.1 Strengths
1. **Strong contract usage**: Most core services have interfaces
2. **Clear persistence separation**: Vector and Graph stores are properly abstracted
3. **Modular prompt system**: Prompt sections are well-organized and extensible
4. **Good resilience patterns**: CircuitBreaker, RateLimiter, RetryPolicy exist

### 7.2 Weaknesses
1. **UI-Domain coupling**: Kompo components directly manipulate models
2. **God classes**: AiChatService and AiChatPanel do too much
3. **Inline validation**: Validation logic scattered across persistence layer
4. **Config coupling**: Many services read config directly instead of injection
5. **Missing repository layer**: Models accessed directly throughout

### 7.3 Recommendations
1. Extract conversation management from AiChatPanel into ConversationService
2. Create repository interfaces for AiConversation, AiMessage, AiQueryLog
3. Extract validation into dedicated validators (EntityValidator, QueryValidator)
4. Split AiServiceProvider into feature-specific providers
5. Move config reads to constructor injection consistently

---

*Generated: 2024-12-30*
*Files analyzed: 172*
