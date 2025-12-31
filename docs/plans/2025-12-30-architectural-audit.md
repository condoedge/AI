# Exhaustive Architectural Audit - Kompo AI Package

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete file-by-file architectural audit to identify dead code, architectural drift, unused properties, and optimization opportunities in the AI chat system.

**Architecture:** Phase-locked execution with 6 phases: Raw Inventory, File-by-File Review, Usage Tracing, Functional Categorization, AI-System Audit, and Cleanup Plan.

**Tech Stack:** PHP/Laravel, Neo4j, Qdrant, OpenAI/Anthropic APIs, Kompo UI framework

---

## Phase 1: Raw Inventory (Tasks 1-3)

### Task 1: Inventory Source Directory Structure

**Files:**
- Create: `docs/audit/phase1-inventory.md`

**Step 1: Create audit directory**

```bash
mkdir -p docs/audit
```

**Step 2: List all src directories**

```bash
find src -type d | sort > docs/audit/phase1-src-dirs.txt
```

**Step 3: List all src files**

```bash
find src -type f -name "*.php" | sort > docs/audit/phase1-src-files.txt
```

**Step 4: Count src files**

```bash
wc -l docs/audit/phase1-src-files.txt
```
Expected: ~118 files

**Step 5: Commit inventory**

```bash
git add docs/audit/
git commit -m "audit: phase 1 - src directory inventory"
```

---

### Task 2: Inventory Test Directory Structure

**Files:**
- Append to: `docs/audit/phase1-inventory.md`

**Step 1: List all test directories**

```bash
find tests -type d | sort > docs/audit/phase1-test-dirs.txt
```

**Step 2: List all test files**

```bash
find tests -type f -name "*.php" | sort > docs/audit/phase1-test-files.txt
```

**Step 3: Count test files**

```bash
wc -l docs/audit/phase1-test-files.txt
```
Expected: ~80+ files

**Step 4: Commit test inventory**

```bash
git add docs/audit/
git commit -m "audit: phase 1 - test directory inventory"
```

---

### Task 3: Inventory Config, Routes, Database, and Resources

**Files:**
- Append to: `docs/audit/phase1-inventory.md`

**Step 1: List config files**

```bash
ls -la config/ > docs/audit/phase1-config-files.txt
```

**Step 2: List route files**

```bash
ls -la routes/ > docs/audit/phase1-route-files.txt
```

**Step 3: List database files**

```bash
find database -type f | sort > docs/audit/phase1-database-files.txt
```

**Step 4: List resource files**

```bash
find resources -type f | sort > docs/audit/phase1-resource-files.txt
```

**Step 5: Commit full inventory**

```bash
git add docs/audit/
git commit -m "audit: phase 1 complete - full file inventory"
```

---

## Phase 2: File-by-File Review (Tasks 4-33)

### Task 4: Review Contracts/Interfaces

**Files:**
- Create: `docs/audit/phase2-contracts.md`
- Read: `src/Contracts/*.php` (15 files)

**Step 1: Create phase 2 review document**

```markdown
# Phase 2: File-by-File Review - Contracts

## Review Checklist
| File | Reviewed | Referenced | Notes |
|------|----------|------------|-------|
```

**Step 2: Review each contract file**

For each file in `src/Contracts/`:
1. Read the file completely
2. Document:
   - What this interface defines (factually)
   - Methods it declares
   - Dependencies (use statements)
   - Implementation status (find implementers via grep)

**Step 3: Search for implementations**

```bash
grep -r "implements ChunkStoreInterface" src/ --include="*.php"
grep -r "implements ContextRetrieverInterface" src/ --include="*.php"
# Repeat for all 15 interfaces
```

**Step 4: Document findings in review table**

**Step 5: Commit contract review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - contracts review"
```

---

### Task 5: Review Domain Layer (Contracts, Traits, Value Objects)

**Files:**
- Create: `docs/audit/phase2-domain.md`
- Read: `src/Domain/Contracts/*.php` (2 files)
- Read: `src/Domain/Traits/*.php` (1 file)
- Read: `src/Domain/ValueObjects/*.php` (4 files)

**Step 1: Review Nodeable interface**

Read `src/Domain/Contracts/Nodeable.php`:
- Document interface methods
- Search for implementers: `grep -r "implements Nodeable" src/`

**Step 2: Review Searchable interface**

Read `src/Domain/Contracts/Searchable.php`:
- Document interface methods
- Search for implementers: `grep -r "implements Searchable" src/`

**Step 3: Review HasNodeableConfig trait**

Read `src/Domain/Traits/HasNodeableConfig.php`:
- Document provided methods
- Search for users: `grep -r "use HasNodeableConfig" src/`

**Step 4: Review each Value Object**

For each file in `src/Domain/ValueObjects/`:
- Document properties and methods
- Trace usage: `grep -r "GraphConfig" src/` etc.

**Step 5: Commit domain review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - domain layer review"
```

---

### Task 6: Review DTOs

**Files:**
- Create: `docs/audit/phase2-dtos.md`
- Read: `src/DTOs/*.php` (2 files)

**Step 1: Review FileChunk DTO**

Read `src/DTOs/FileChunk.php`:
- Document properties
- Document factory methods
- Trace usage: `grep -r "FileChunk" src/`

**Step 2: Review ProcessingResult DTO**

Read `src/DTOs/ProcessingResult.php`:
- Document properties
- Document factory methods
- Trace usage: `grep -r "ProcessingResult" src/`

**Step 3: Commit DTO review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - DTOs review"
```

---

### Task 7: Review Embedding Providers

**Files:**
- Create: `docs/audit/phase2-embedding-providers.md`
- Read: `src/EmbeddingProviders/*.php` (2 files)

**Step 1: Review AnthropicEmbeddingProvider**

Read `src/EmbeddingProviders/AnthropicEmbeddingProvider.php`:
- Document methods
- Document API interactions
- Verify implements EmbeddingProviderInterface

**Step 2: Review OpenAiEmbeddingProvider**

Read `src/EmbeddingProviders/OpenAiEmbeddingProvider.php`:
- Document methods
- Document API interactions
- Verify implements EmbeddingProviderInterface

**Step 3: Trace registration and usage**

```bash
grep -r "EmbeddingProvider" src/ --include="*.php"
grep -r "embeddings" config/ --include="*.php"
```

**Step 4: Commit embedding providers review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - embedding providers review"
```

---

### Task 8: Review Exceptions

**Files:**
- Create: `docs/audit/phase2-exceptions.md`
- Read: `src/Exceptions/*.php` (9 files)

**Step 1: List all exception classes**

```bash
ls src/Exceptions/
```

**Step 2: For each exception, trace usage**

```bash
grep -r "CircuitBreakerOpenException" src/ --include="*.php"
grep -r "CypherInjectionException" src/ --include="*.php"
grep -r "DataConsistencyException" src/ --include="*.php"
grep -r "QueryExecutionException" src/ --include="*.php"
grep -r "QueryGenerationException" src/ --include="*.php"
grep -r "QueryTimeoutException" src/ --include="*.php"
grep -r "QueryValidationException" src/ --include="*.php"
grep -r "ReadOnlyViolationException" src/ --include="*.php"
grep -r "UnsafeQueryException" src/ --include="*.php"
```

**Step 3: Document which exceptions are thrown vs caught**

**Step 4: Commit exceptions review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - exceptions review"
```

---

### Task 9: Review Facades

**Files:**
- Create: `docs/audit/phase2-facades.md`
- Read: `src/Facades/*.php` (2 files)

**Step 1: Review AI Facade**

Read `src/Facades/AI.php`:
- Document accessor method
- Trace what it resolves to

**Step 2: Review FileSearch Facade**

Read `src/Facades/FileSearch.php`:
- Document accessor method
- Trace what it resolves to

**Step 3: Search facade usage**

```bash
grep -r "AI::" src/ tests/ --include="*.php"
grep -r "FileSearch::" src/ tests/ --include="*.php"
```

**Step 4: Commit facades review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - facades review"
```

---

### Task 10: Review Graph Store

**Files:**
- Create: `docs/audit/phase2-graph-store.md`
- Read: `src/GraphStore/*.php` (2 files)

**Step 1: Review CypherSanitizer**

Read `src/GraphStore/CypherSanitizer.php`:
- Document sanitization methods
- Trace usage in queries

**Step 2: Review Neo4jStore**

Read `src/GraphStore/Neo4jStore.php`:
- Document all public methods
- Document query patterns
- Document error handling
- Verify implements GraphStoreInterface

**Step 3: Trace graph store usage**

```bash
grep -r "Neo4jStore" src/ --include="*.php"
grep -r "GraphStore" src/ --include="*.php"
```

**Step 4: Commit graph store review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - graph store review"
```

---

### Task 11: Review HTTP Layer

**Files:**
- Create: `docs/audit/phase2-http.md`
- Read: `src/Http/Controllers/*.php` (2 files)

**Step 1: Review ConversationController**

Read `src/Http/Controllers/ConversationController.php`:
- Document endpoints
- Document request/response flow
- Trace route registration

**Step 2: Review HealthController**

Read `src/Http/Controllers/HealthController.php`:
- Document health check logic
- Trace route registration

**Step 3: Cross-reference with routes**

```bash
cat routes/api.php
cat routes/web.php
```

**Step 4: Commit HTTP review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - HTTP layer review"
```

---

### Task 12: Review Jobs

**Files:**
- Create: `docs/audit/phase2-jobs.md`
- Read: `src/Jobs/*.php` (3 files)

**Step 1: Review IngestEntityJob**

Read `src/Jobs/IngestEntityJob.php`:
- Document job payload
- Document handle() logic
- Trace dispatchers

**Step 2: Review RemoveEntityJob**

Read `src/Jobs/RemoveEntityJob.php`:
- Document job payload
- Document handle() logic
- Trace dispatchers

**Step 3: Review SyncEntityJob**

Read `src/Jobs/SyncEntityJob.php`:
- Document job payload
- Document handle() logic
- Trace dispatchers

**Step 4: Search for job dispatches**

```bash
grep -r "IngestEntityJob::dispatch" src/ --include="*.php"
grep -r "RemoveEntityJob::dispatch" src/ --include="*.php"
grep -r "SyncEntityJob::dispatch" src/ --include="*.php"
```

**Step 5: Commit jobs review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - jobs review"
```

---

### Task 13: Review Kompo Components - Main

**Files:**
- Create: `docs/audit/phase2-kompo-main.md`
- Read: `src/Kompo/AiChatFloating.php`
- Read: `src/Kompo/AiChatPanel.php`
- Read: `src/Kompo/ChatMessageForm.php`
- Read: `src/Kompo/ConversationListQuery.php`

**Step 1: Review AiChatFloating**

- Document component structure
- Document props and methods
- Trace parent/child relationships

**Step 2: Review AiChatPanel**

- Document component structure
- Document props and methods
- Trace service dependencies

**Step 3: Review ChatMessageForm**

- Document form fields
- Document submission logic
- Trace message handling

**Step 4: Review ConversationListQuery**

- Document query structure
- Document data flow

**Step 5: Commit Kompo main review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - Kompo main components review"
```

---

### Task 14: Review Kompo Modals

**Files:**
- Create: `docs/audit/phase2-kompo-modals.md`
- Read: `src/Kompo/Modals/*.php` (4 files)

**Step 1: Review ChatHelpModal**

- Document modal content
- Trace trigger points

**Step 2: Review ChatSettingsModal**

- Document settings exposed
- Document save logic

**Step 3: Review EditMessageModal**

- Document edit functionality
- Trace message update flow

**Step 4: Review FilePreviewModal**

- Document preview rendering
- Trace file access

**Step 5: Commit Kompo modals review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - Kompo modals review"
```

---

### Task 15: Review Kompo Traits

**Files:**
- Create: `docs/audit/phase2-kompo-traits.md`
- Read: `src/Kompo/Traits/*.php` (4 files)

**Step 1: Review HasAvatars trait**

- Document methods
- Search users: `grep -r "use HasAvatars" src/`

**Step 2: Review HasChatSettings trait**

- Document methods
- Search users: `grep -r "use HasChatSettings" src/`

**Step 3: Review HasChatTheme trait**

- Document methods
- Search users: `grep -r "use HasChatTheme" src/`

**Step 4: Review HasTypingIndicator trait**

- Document methods
- Search users: `grep -r "use HasTypingIndicator" src/`

**Step 5: Commit Kompo traits review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - Kompo traits review"
```

---

### Task 16: Review LLM Providers

**Files:**
- Create: `docs/audit/phase2-llm-providers.md`
- Read: `src/LlmProviders/*.php` (2 files)

**Step 1: Review AnthropicLlmProvider**

- Document methods
- Document API interactions
- Verify implements LlmProviderInterface

**Step 2: Review OpenAiLlmProvider**

- Document methods
- Document API interactions
- Verify implements LlmProviderInterface

**Step 3: Trace registration and usage**

```bash
grep -r "LlmProvider" src/ --include="*.php"
grep -r "llm" config/ --include="*.php"
```

**Step 4: Commit LLM providers review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - LLM providers review"
```

---

### Task 17: Review Models

**Files:**
- Create: `docs/audit/phase2-models.md`
- Read: `src/Models/*.php` (4 files)
- Read: `src/Models/Plugins/*.php` (1 file)

**Step 1: Review AiConversation model**

- Document relationships
- Document attributes
- Document methods
- Trace usage

**Step 2: Review AiMessage model**

- Document relationships
- Document attributes
- Document methods
- Trace usage

**Step 3: Review AiQueryLog model**

- Document attributes
- Document methods
- Trace usage (is it used for learning?)

**Step 4: Review AiUserSetting model**

- Document attributes
- Document relationships
- Trace settings resolution

**Step 5: Review FileProcessingPlugin**

- Document plugin hook points
- Trace registration

**Step 6: Commit models review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - models review"
```

---

### Task 18: Review Observers

**Files:**
- Create: `docs/audit/phase2-observers.md`
- Read: `src/Observers/*.php` (1 file)

**Step 1: Review RelatedModelSyncObserver**

- Document observed events
- Document sync logic
- Trace registration (ServiceProvider)

**Step 2: Search for observer registration**

```bash
grep -r "RelatedModelSyncObserver" src/ --include="*.php"
grep -r "observe" src/AiServiceProvider.php
```

**Step 3: Commit observers review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - observers review"
```

---

### Task 19: Review Core Services - AiManager

**Files:**
- Create: `docs/audit/phase2-services-core.md`
- Read: `src/Services/AiManager.php`

**Step 1: Review AiManager**

- Document all public methods
- Document internal state
- Document dependencies
- Trace facade resolution

**Step 2: Map AiManager method usage**

```bash
grep -r "AiManager" src/ --include="*.php"
grep -r "->query(" src/ --include="*.php"
grep -r "->answer(" src/ --include="*.php"
```

**Step 3: Commit AiManager review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - AiManager review"
```

---

### Task 20: Review Context & Query Services

**Files:**
- Append to: `docs/audit/phase2-services-core.md`
- Read: `src/Services/ContextRetriever.php`
- Read: `src/Services/QueryExecutor.php`
- Read: `src/Services/QueryGenerator.php`
- Read: `src/Services/ResponseGenerator.php`

**Step 1: Review ContextRetriever**

- Document retrieval logic
- Document context sources
- Trace data flow

**Step 2: Review QueryExecutor**

- Document execution logic
- Document error handling
- Trace query sources

**Step 3: Review QueryGenerator**

- Document generation logic
- Document prompt construction
- Trace LLM interactions

**Step 4: Review ResponseGenerator**

- Document response formatting
- Document enrichment logic
- Trace LLM interactions

**Step 5: Commit query services review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - query services review"
```

---

### Task 21: Review Semantic Services

**Files:**
- Create: `docs/audit/phase2-services-semantic.md`
- Read: `src/Services/SemanticChunker.php`
- Read: `src/Services/SemanticContextSelector.php`
- Read: `src/Services/SemanticIndexer.php`
- Read: `src/Services/SemanticMatcher.php`
- Read: `src/Services/SemanticPromptBuilder.php`
- Read: `src/Services/ScopeSemanticMatcher.php`

**Step 1: Review each semantic service**

For each file:
- Document purpose
- Document inputs/outputs
- Trace dependencies
- Trace usage

**Step 2: Map semantic service interactions**

```bash
grep -r "SemanticChunker" src/ --include="*.php"
grep -r "SemanticContextSelector" src/ --include="*.php"
grep -r "SemanticIndexer" src/ --include="*.php"
grep -r "SemanticMatcher" src/ --include="*.php"
grep -r "SemanticPromptBuilder" src/ --include="*.php"
grep -r "ScopeSemanticMatcher" src/ --include="*.php"
```

**Step 3: Commit semantic services review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - semantic services review"
```

---

### Task 22: Review File Services

**Files:**
- Create: `docs/audit/phase2-services-files.md`
- Read: `src/Services/FileProcessor.php`
- Read: `src/Services/FileSearchService.php`
- Read: `src/Services/FileExtractorRegistry.php`
- Read: `src/Services/DataIngestionService.php`
- Read: `src/Services/Files/PhysicalFileIndexer.php`

**Step 1: Review each file service**

For each file:
- Document purpose
- Document file handling logic
- Trace dependencies

**Step 2: Map file processing flow**

```bash
grep -r "FileProcessor" src/ --include="*.php"
grep -r "FileSearchService" src/ --include="*.php"
grep -r "DataIngestionService" src/ --include="*.php"
```

**Step 3: Commit file services review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - file services review"
```

---

### Task 23: Review Extractors

**Files:**
- Create: `docs/audit/phase2-extractors.md`
- Read: `src/Services/Extractors/*.php` (4 files)

**Step 1: Review each extractor**

For each file:
- Document supported formats
- Document extraction logic
- Trace registration

**Step 2: Search extractor registration**

```bash
grep -r "registerExtractor" src/ --include="*.php"
grep -r "DocxExtractor" src/ --include="*.php"
grep -r "MarkdownExtractor" src/ --include="*.php"
grep -r "PdfExtractor" src/ --include="*.php"
grep -r "TextExtractor" src/ --include="*.php"
```

**Step 3: Commit extractors review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - extractors review"
```

---

### Task 24: Review Analytics & Cache Services

**Files:**
- Create: `docs/audit/phase2-services-analytics-cache.md`
- Read: `src/Services/Analytics/QueryAnalytics.php`
- Read: `src/Services/Cache/QueryResultCache.php`

**Step 1: Review QueryAnalytics**

- Document tracked metrics
- Document storage mechanism
- Trace usage points

**Step 2: Review QueryResultCache**

- Document cache strategy
- Document invalidation
- Trace usage points

**Step 3: Commit analytics & cache review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - analytics and cache review"
```

---

### Task 25: Review Chat Services

**Files:**
- Create: `docs/audit/phase2-services-chat.md`
- Read: `src/Services/Chat/*.php` (4 files)

**Step 1: Review AiChatService**

- Document chat flow
- Document message handling
- Trace dependencies

**Step 2: Review AiChatMessage**

- Document message structure
- Trace usage

**Step 3: Review AiChatResponseData**

- Document response structure
- Trace usage

**Step 4: Review AmbiguityDetector**

- Document detection logic
- Trace usage in chat flow

**Step 5: Commit chat services review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - chat services review"
```

---

### Task 26: Review Context Services

**Files:**
- Create: `docs/audit/phase2-services-context.md`
- Read: `src/Services/Context/*.php` (5 files)

**Step 1: Review each context service**

For each file:
- Document purpose
- Document context handling
- Trace data flow

**Step 2: Map context service interactions**

```bash
grep -r "ConversationContextManager" src/ --include="*.php"
grep -r "EntityExtractor" src/ --include="*.php"
grep -r "FileAccessResolver" src/ --include="*.php"
grep -r "FileContextProvider" src/ --include="*.php"
grep -r "ReferenceResolver" src/ --include="*.php"
```

**Step 3: Commit context services review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - context services review"
```

---

### Task 27: Review Discovery Services

**Files:**
- Create: `docs/audit/phase2-services-discovery.md`
- Read: `src/Services/Discovery/*.php` (10 files)

**Step 1: Review each discovery service**

For each file:
- Document purpose
- Document discovery logic
- Trace dependencies

**Step 2: Map discovery service usage**

```bash
grep -r "AliasGenerator" src/ --include="*.php"
grep -r "CypherPatternGenerator" src/ --include="*.php"
grep -r "EntityAutoDiscovery" src/ --include="*.php"
# etc. for all 10 files
```

**Step 3: Commit discovery services review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - discovery services review"
```

---

### Task 28: Review Learning Services

**Files:**
- Create: `docs/audit/phase2-services-learning.md`
- Read: `src/Services/Learning/QueryLearner.php`

**Step 1: Review QueryLearner**

- Document learning mechanism
- Document pattern storage
- Trace usage in query flow

**Step 2: Search usage**

```bash
grep -r "QueryLearner" src/ --include="*.php"
```

**Step 3: Commit learning services review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - learning services review"
```

---

### Task 29: Review Prompt Sections

**Files:**
- Create: `docs/audit/phase2-prompt-sections.md`
- Read: `src/Services/PromptSections/*.php` (15 files)

**Step 1: Review BasePromptSection**

- Document base interface
- Document rendering logic

**Step 2: Review each prompt section**

For each section file:
- Document what context it provides
- Document render() output
- Trace registration

**Step 3: Map prompt section usage**

```bash
grep -r "PromptSection" src/ --include="*.php"
grep -r "addSection" src/ --include="*.php"
```

**Step 4: Commit prompt sections review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - prompt sections review"
```

---

### Task 30: Review Resilience Services

**Files:**
- Create: `docs/audit/phase2-services-resilience.md`
- Read: `src/Services/Resilience/*.php` (3 files)

**Step 1: Review CircuitBreaker**

- Document states
- Document threshold logic
- Trace usage

**Step 2: Review RateLimiter**

- Document limiting strategy
- Trace usage

**Step 3: Review RetryPolicy**

- Document retry logic
- Document backoff strategy
- Trace usage

**Step 4: Commit resilience services review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - resilience services review"
```

---

### Task 31: Review Response Services

**Files:**
- Create: `docs/audit/phase2-response-services.md`
- Read: `src/Services/Response/ResponseFileEnricher.php`
- Read: `src/Services/ResponseSections/*.php` (10 files)

**Step 1: Review ResponseFileEnricher**

- Document enrichment logic
- Trace usage

**Step 2: Review each response section**

For each section file:
- Document what it adds to response
- Document render() output
- Trace registration

**Step 3: Map response section usage**

```bash
grep -r "ResponseSection" src/ --include="*.php"
```

**Step 4: Commit response services review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - response services review"
```

---

### Task 32: Review Security Services

**Files:**
- Create: `docs/audit/phase2-services-security.md`
- Read: `src/Services/Security/*.php` (4 files)

**Step 1: Review each security service**

For each file:
- Document security mechanism
- Document data handling
- Trace usage

**Step 2: Map security service usage**

```bash
grep -r "AccessLevelResolver" src/ --include="*.php"
grep -r "PromptContextBuilder" src/ --include="*.php"
grep -r "SensitiveDataSanitizer" src/ --include="*.php"
grep -r "TeamFilteredQuery" src/ --include="*.php"
```

**Step 3: Commit security services review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - security services review"
```

---

### Task 33: Review Settings & UI Services

**Files:**
- Create: `docs/audit/phase2-services-ui-settings.md`
- Read: `src/Services/Settings/*.php` (3 files)
- Read: `src/Services/UI/*.php` (5 files)
- Read: `src/Services/UI/Themes/*.php` (3 files)

**Step 1: Review Settings services**

For each settings file:
- Document settings resolution
- Trace priority chain

**Step 2: Review UI services**

For each UI file:
- Document theming logic
- Trace factory patterns

**Step 3: Review Theme implementations**

For each theme file:
- Document color schemes
- Trace selection mechanism

**Step 4: Commit UI & settings review**

```bash
git add docs/audit/
git commit -m "audit: phase 2 - UI and settings review"
```

---

## Phase 3: Usage & Flow Tracing (Tasks 34-39)

### Task 34: Trace Chat Entry Point Flow

**Files:**
- Create: `docs/audit/phase3-flow-chat.md`

**Step 1: Identify chat entry points**

Start from:
- `AiChatPanel.php` - UI component
- `ChatMessageForm.php` - Form submission
- `ConversationController.php` - API endpoint

**Step 2: Trace message submission flow**

```
UI (AiChatPanel)
  → Form (ChatMessageForm)
    → Controller (ConversationController)
      → Service (AiChatService)
        → Context (ConversationContextManager)
        → Query (QueryGenerator → QueryExecutor)
        → Response (ResponseGenerator)
          → Message stored (AiMessage)
```

**Step 3: Document each step with file:line references**

**Step 4: Identify unreached code in this flow**

**Step 5: Commit chat flow trace**

```bash
git add docs/audit/
git commit -m "audit: phase 3 - chat flow trace"
```

---

### Task 35: Trace Data Ingestion Flow

**Files:**
- Create: `docs/audit/phase3-flow-ingestion.md`

**Step 1: Identify ingestion entry points**

Start from:
- `IngestEntitiesCommand.php`
- `ProcessFilesCommand.php`
- `IngestEntityJob.php`

**Step 2: Trace entity ingestion flow**

```
Command/Job
  → DataIngestionService
    → FileProcessor (if file)
    → Graph Store (Neo4j)
    → Vector Store (Qdrant)
    → SemanticIndexer
```

**Step 3: Document each step with file:line references**

**Step 4: Identify unreached code in this flow**

**Step 5: Commit ingestion flow trace**

```bash
git add docs/audit/
git commit -m "audit: phase 3 - ingestion flow trace"
```

---

### Task 36: Trace Query Generation Flow

**Files:**
- Create: `docs/audit/phase3-flow-query.md`

**Step 1: Start from user question**

**Step 2: Trace through prompt construction**

```
User Question
  → AiManager.query()
    → ContextRetriever
      → Semantic matching
      → Graph queries
    → QueryGenerator
      → Prompt sections assembled
      → LLM call
    → QueryExecutor
      → Cypher validation
      → Neo4j execution
    → ResponseGenerator
      → Response sections assembled
      → LLM call
```

**Step 3: Document each prompt section contribution**

**Step 4: Identify unused prompt sections**

**Step 5: Commit query flow trace**

```bash
git add docs/audit/
git commit -m "audit: phase 3 - query flow trace"
```

---

### Task 37: Trace Context Resolution Flow

**Files:**
- Create: `docs/audit/phase3-flow-context.md`

**Step 1: Map all context sources**

- Conversation history
- File context
- Entity context
- User context
- Project context

**Step 2: Trace each context type through system**

**Step 3: Identify context collected but never used**

**Step 4: Identify context needed but never collected**

**Step 5: Commit context flow trace**

```bash
git add docs/audit/
git commit -m "audit: phase 3 - context flow trace"
```

---

### Task 38: Trace Settings Resolution Flow

**Files:**
- Create: `docs/audit/phase3-flow-settings.md`

**Step 1: Map settings sources**

- AiUserSetting model
- Config files
- Defaults

**Step 2: Trace priority chain**

```
UserChatSettings
  → AiUserSetting (database)
  → Config (ai.php)
  → Defaults (AbstractChatSettings)
```

**Step 3: Verify all settings are consumed**

**Step 4: Identify unused settings properties**

**Step 5: Commit settings flow trace**

```bash
git add docs/audit/
git commit -m "audit: phase 3 - settings flow trace"
```

---

### Task 39: Document Dead Code Candidates

**Files:**
- Create: `docs/audit/phase3-dead-code.md`

**Step 1: Compile list of files never reached in any flow**

**Step 2: Compile list of methods never called**

**Step 3: Compile list of properties never accessed**

**Step 4: Mark each as:**
- Confirmed dead code
- Potentially dead (needs more investigation)
- Legacy (replaced but not removed)
- Future feature (not yet integrated)

**Step 5: Commit dead code analysis**

```bash
git add docs/audit/
git commit -m "audit: phase 3 - dead code candidates"
```

---

## Phase 4: Functional Categorization (Tasks 40-42)

### Task 40: Create Functional Category Map

**Files:**
- Create: `docs/audit/phase4-categories.md`

**Step 1: Define functional categories**

```markdown
## Functional Categories

### 1. Chat Orchestration
Files that coordinate the chat flow

### 2. Context Retrieval
Files that gather and prepare context

### 3. Memory / History
Files that manage conversation history

### 4. Prompt Construction
Files that build prompts for LLMs

### 5. Model Interaction
Files that communicate with LLMs

### 6. Persistence
Files that handle storage

### 7. Validation
Files that validate data

### 8. Observability
Files that provide monitoring/logging

### 9. UI Components
Files that render user interfaces

### 10. Configuration
Files that manage settings
```

**Step 2: Commit category framework**

```bash
git add docs/audit/
git commit -m "audit: phase 4 - category framework"
```

---

### Task 41: Assign Files to Categories

**Files:**
- Append to: `docs/audit/phase4-categories.md`

**Step 1: Assign each src file to exactly one category**

Use Phase 2 reviews to inform assignment.

**Step 2: Identify orphan files (fit no category)**

**Step 3: Identify cross-category leaks (file doing multiple concerns)**

**Step 4: Commit category assignments**

```bash
git add docs/audit/
git commit -m "audit: phase 4 - category assignments"
```

---

### Task 42: Document Category Boundaries

**Files:**
- Create: `docs/audit/phase4-boundaries.md`

**Step 1: Document expected boundaries**

For each category:
- What it should depend on
- What it should NOT depend on
- What should depend on it

**Step 2: Identify boundary violations**

Files that depend on things they shouldn't.

**Step 3: Commit boundary analysis**

```bash
git add docs/audit/
git commit -m "audit: phase 4 - boundary analysis"
```

---

## Phase 5: AI System Audit (Tasks 43-47)

### Task 43: Audit Conversation History Usage

**Files:**
- Create: `docs/audit/phase5-ai-audit.md`

**Step 1: Trace conversation history through system**

- How is history loaded?
- How is it formatted for prompts?
- Is all history used, or truncated?
- Are there relevance filters?

**Step 2: Identify gaps**

- History collected but not used
- History needed but not available
- History formatted inefficiently

**Step 3: Commit conversation audit**

```bash
git add docs/audit/
git commit -m "audit: phase 5 - conversation history audit"
```

---

### Task 44: Audit Context Consistency

**Files:**
- Append to: `docs/audit/phase5-ai-audit.md`

**Step 1: Verify context passed consistently**

- Same context format across components
- No data loss in transformations
- No duplicated context

**Step 2: Identify inconsistencies**

**Step 3: Commit context consistency audit**

```bash
git add docs/audit/
git commit -m "audit: phase 5 - context consistency audit"
```

---

### Task 45: Audit Embedding/Retriever Alignment

**Files:**
- Append to: `docs/audit/phase5-ai-audit.md`

**Step 1: Verify embeddings are used**

- Are embeddings generated?
- Are they stored correctly?
- Are they retrieved for queries?
- Are retrieval results used in prompts?

**Step 2: Identify misalignments**

- Embeddings generated but not used
- Retrieval results discarded
- Wrong embedding model for use case

**Step 3: Commit embedding audit**

```bash
git add docs/audit/
git commit -m "audit: phase 5 - embedding alignment audit"
```

---

### Task 46: Audit Data Collection vs Consumption

**Files:**
- Append to: `docs/audit/phase5-ai-audit.md`

**Step 1: List all data collected**

- User inputs
- System metrics
- Query logs
- Entity properties
- File contents

**Step 2: For each data type, verify consumption**

- Is it used in prompts?
- Is it used in decisions?
- Is it used in responses?

**Step 3: Identify orphan data**

Data collected but never consumed.

**Step 4: Commit data audit**

```bash
git add docs/audit/
git commit -m "audit: phase 5 - data collection audit"
```

---

### Task 47: Audit Model Output Quality Signals

**Files:**
- Append to: `docs/audit/phase5-ai-audit.md`

**Step 1: Identify missing signals**

- User feedback not captured
- Query success/failure not tracked
- Response quality not measured

**Step 2: Identify unused signals**

- Signals collected but not used for improvement

**Step 3: Propose signal improvements**

**Step 4: Commit signal audit**

```bash
git add docs/audit/
git commit -m "audit: phase 5 - quality signal audit"
```

---

## Phase 6: Cleanup & Improvement Plan (Tasks 48-52)

### Task 48: Create Removal Plan

**Files:**
- Create: `docs/audit/phase6-cleanup-plan.md`

**Step 1: List confirmed dead code for removal**

For each item:
- File/method to remove
- Justification (never reached, replaced, etc.)
- Dependencies to update
- Tests to remove/update

**Step 2: Prioritize by risk**

- Low risk: No dependencies
- Medium risk: Few dependencies
- High risk: Many dependencies

**Step 3: Commit removal plan**

```bash
git add docs/audit/
git commit -m "audit: phase 6 - removal plan"
```

---

### Task 49: Create Merge Plan

**Files:**
- Append to: `docs/audit/phase6-cleanup-plan.md`

**Step 1: Identify duplicate logic**

Files doing the same thing differently.

**Step 2: Plan merges**

For each merge:
- Files to merge
- Target structure
- Migration path
- Tests needed

**Step 3: Commit merge plan**

```bash
git add docs/audit/
git commit -m "audit: phase 6 - merge plan"
```

---

### Task 50: Create Refactor Plan

**Files:**
- Append to: `docs/audit/phase6-cleanup-plan.md`

**Step 1: Identify boundary violations to fix**

**Step 2: Identify files with multiple concerns to split**

**Step 3: Plan refactors**

For each refactor:
- Current structure
- Target structure
- Migration steps
- Tests needed

**Step 4: Commit refactor plan**

```bash
git add docs/audit/
git commit -m "audit: phase 6 - refactor plan"
```

---

### Task 51: Create AI Improvement Plan

**Files:**
- Append to: `docs/audit/phase6-cleanup-plan.md`

**Step 1: List AI-specific improvements**

- Context usage improvements
- Prompt optimization
- Response quality improvements
- Missing signals to add

**Step 2: Prioritize by impact**

- High impact: Directly improves model output
- Medium impact: Improves efficiency
- Low impact: Code quality only

**Step 3: Commit AI improvement plan**

```bash
git add docs/audit/
git commit -m "audit: phase 6 - AI improvement plan"
```

---

### Task 52: Create Final Audit Summary

**Files:**
- Create: `docs/audit/AUDIT-SUMMARY.md`

**Step 1: Compile executive summary**

- Total files audited
- Dead code identified
- Boundary violations found
- AI system issues found

**Step 2: Prioritized action list**

1. Critical (must fix)
2. Important (should fix)
3. Nice to have (could fix)

**Step 3: Expected impact**

- Correctness improvements
- Model quality improvements
- Maintainability improvements
- Performance improvements

**Step 4: Commit final summary**

```bash
git add docs/audit/
git commit -m "audit: phase 6 complete - final audit summary"
```

---

## Execution Notes

**Phase Dependencies:**
- Phase 1 must complete before Phase 2
- Phase 2 must complete before Phase 3
- Phase 3 must complete before Phase 4
- Phase 4 must complete before Phase 5
- Phase 5 must complete before Phase 6

**Confirmation Required:**
After each phase, ask: "PHASE N complete. May I proceed to Phase N+1?"

**Meta-Behavior Reminders:**
- If something feels "off", investigate it
- If something exists twice, explain why
- If something collects data, ensure it is consumed
- If something was replaced, find the replacement
- Assume NOTHING is accidental
