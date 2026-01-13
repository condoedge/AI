# PHASE 2: Module Discovery & Refinement

## Documentation vs Code Mapping

### 1. COMMANDS (reference/commands.md)

| Documented | Actual Code | Status |
|------------|-------------|--------|
| ai:discover | ai:discover | OK - options match |
| ai:ingest | ai:ingest | OK - but missing --docs option in docs |
| ai:sync-relationships | ai:sync-relationships | OK |
| ai:index-semantic | ai:index-semantic | OK |
| ai:index-context | ai:index-context | OK |
| ai:index-scopes | ai:index-scopes | OK |
| ai:process-files | ai:process-files | OK |
| ai:clear | DOES NOT EXIST | DELETE from docs |
| ai:status | DOES NOT EXIST | DELETE from docs |
| ai:test | DOES NOT EXIST | DELETE from docs |
| ai:query | DOES NOT EXIST | DELETE from docs |
| ai:config | DOES NOT EXIST | DELETE from docs |
| ai:publish | DOES NOT EXIST | DELETE from docs |
| ai:ingest-eager | DOES NOT EXIST | DELETE from docs |
| NOT DOCUMENTED | ai:diagnose | ADD to docs |
| NOT DOCUMENTED | ai:config:validate | ADD to docs |

### 2. FACADES API (reference/facades.md)

| Documented Method | Actual Method | Status |
|-------------------|---------------|--------|
| chat() | chat() | OK |
| query() | executeQuery() | RENAME in docs |
| generateQuery() | generateQuery() | OK |
| ingest() | ingest() | OK |
| bulkIngest() | ingestBatch() | RENAME in docs |
| remove() | remove() | OK |
| search() | DOES NOT EXIST | DELETE or clarify |
| searchFiles() | DOES NOT EXIST | DELETE - use FileSearch facade |
| getContext() | retrieveContext() | RENAME in docs |
| getSchema() | getSchema() | OK |
| embed() | embed() | OK |
| neo4j() | DOES NOT EXIST | DELETE from docs |
| qdrant() | DOES NOT EXIST | DELETE from docs |
| llm() | DOES NOT EXIST | DELETE from docs |
| getConfig() | DOES NOT EXIST | DELETE from docs |
| isEnabled() | DOES NOT EXIST | DELETE from docs |
| status() | DOES NOT EXIST | DELETE from docs |

**Undocumented facade methods:**
- sync(), ingestBatch(), searchSimilar(), getExampleEntities(), storeQuery()
- embedBatch(), getEmbeddingDimensions(), getEmbeddingModel()
- chatJson(), complete(), stream(), getLlmModel(), getLlmProvider()
- getLlmMaxTokens(), countTokens(), validateQuery(), sanitizeQuery()
- getQueryTemplates(), detectQueryTemplate(), askQuestion()
- executeCount(), executePaginated(), explainQuery(), testQuery()
- ask(), extractInsights(), suggestVisualizations(), answerQuestion()

### 3. PROMPT SECTIONS (extending/prompt-sections.md)

| Documented Section | Actual Section | Status |
|--------------------|----------------|--------|
| SystemSection | NOT IN CODE | Fictional - remove |
| SchemaSection | SchemaSection | OK |
| ScopesSection | DetectedScopesSection | Clarify naming |
| ExamplesSection | ExampleEntitiesSection + SimilarQueriesSection | Split - clarify |
| GuidelinesSection | GuidelinesSection (Response only) | Clarify context |

**Undocumented prompt sections (17 actual):**
- ProjectContextSection
- GenericContextSection
- CurrentUserContextSection
- RelationshipsSection
- FileContextSection
- ConversationContextSection
- EntityActionAwarenessSection
- DetectedEntitiesSection
- PatternLibrarySection
- QueryRulesSection
- QuestionSection
- TaskInstructionsSection

**Undocumented response sections (12 actual):**
- SystemPromptSection
- PrivacyAndSecurityGuidelinesSection
- ResponseProjectContextSection
- OriginalQuestionSection
- QueryInfoSection
- FileContextSection
- ResponseConversationContextSection
- ResultsDataSection
- ResponseEntityActionsSection
- StatisticsSection
- GuidelinesSection
- ResponseTaskSection

### 4. UNDOCUMENTED FEATURES

| Feature | Code Location | Priority |
|---------|---------------|----------|
| Entity Actions System | config entity_actions, ActionLinkHandler | HIGH |
| Generic Actions System | config generic_actions, ActionLinkHandler | HIGH |
| Chat Theming | Services/UI/*, config ui section | HIGH |
| User Chat Settings | Services/Settings/* | MEDIUM |
| Conversation Export | Services/Chat/Exporter/* | MEDIUM |
| Response Enrichers | Services/Response/*Enricher | MEDIUM |
| Content Link Processors | Services/Response/*Handler | MEDIUM |
| Input Sanitizer | Services/Security/InputSanitizer | MEDIUM |
| Query Result Filter | Services/Security/QueryResultFilter | MEDIUM |
| Cypher Sanitizer | GraphStore/CypherSanitizer | MEDIUM |
| Circuit Breaker | Services/Resilience/CircuitBreaker | LOW |
| Rate Limiter | Services/Resilience/RateLimiter | LOW |
| Retry Policy | Services/Resilience/RetryPolicy | LOW |

### 5. CONFIG SECTIONS INVENTORY

**Documented in configuration.md:**
- project, discovery, auto_sync, graph, vector, llm, embedding
- query_generation, query_execution, response_generation, rag
- file_processing, chat

**Undocumented config sections:**
- access_control
- sync_triggers
- model_namespaces
- rate_limits
- relationship_weights
- semantic_matching (detailed)
- semantic_context
- scope_matching
- file_context
- ui
- query_patterns (reference to ai-patterns.php)
- entities (reference to entities.php)
- entity_id_fields
- entity_actions
- generic_actions
- query_generator_sections
- response_generator_sections
