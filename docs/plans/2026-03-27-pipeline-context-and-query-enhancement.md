# Pipeline Context Enhancement & Query Understanding Integration

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace fragile array-passing between pipeline components with a single typed `PipelineContext` DTO (containing nested sub-DTOs for each stage), make Query Understanding always-on with per-stage model selection, connect `clarified_question` to file search and prompts, add file priority tiers so CondoEdge can layer team rules over provincial laws, and skip Cypher for pure knowledge queries.

**Architecture:** One `PipelineContext` object flows through the entire pipeline. Each service writes to its typed sub-DTO property (`ctx.qu`, `ctx.semanticSelection`, `ctx.queryResult`, `ctx.executionResult`). Prompt sections read typed paths like `ctx.qu.clarifiedQuestion`. `LlmResolver` provides per-stage model selection. `FileContextEnricherInterface` lets projects inject priority logic.

**Tech Stack:** PHP 8.1+, Laravel, existing `LlmProviderInterface`, existing `HasInternalModules` section pipeline.

---

## Architecture Principles

Every change in this plan follows these principles:

### Single Responsibility
Each class does ONE thing. Current violations to fix:
- **`ContextRetriever`** currently orchestrates QU, semantic selection, schema fetching, metadata building, and quality assessment. It should only **assemble** the `PipelineContext` by calling single-purpose services.
- **`AiManager.answerQuestion()`** is a 100-line god-method doing file context, query gen, execution, enrichment, security filtering, response gen. It should be a clean pipeline where each step is a method or service with a clear name.

### Flat over nested
No deep nesting. If a method has more than 2 levels of indentation, extract a method. If a class has more than 3 collaborators, it's doing too much.

### Clear purpose per file
Each file's purpose should be obvious from its name and first 3 lines. No "manager" classes that do everything.

### DTOs carry data, services do work
DTOs have no logic except constructors and `empty()` factories. All behavior lives in services. No "smart DTOs" with business logic.

---

## Context & Problem

### Problem 1: Fragile array communication
Data flows as nested associative arrays. Adding a field requires changes in 3-4 files. No IDE autocomplete, no type safety.

### Problem 2: QU is conditional and drops `clarified_question`
QU only runs when semantic selection scores are low. Even when it does, `normalizeResponse()` drops the `clarified_question`.

### Problem 3: File search has no priority tiers
Flat semantic search across all files. CondoEdge needs team rules to outrank provincial laws.

### Problem 4: One model fits all
Same LLM singleton handles QU (needs speed), query gen (needs reasoning), and response gen (needs speed).

### Problem 5: No question type classification
Pipeline always tries to generate Cypher. "Can I tear down a wall?" should answer from files, not Cypher.

---

## DTO Strategy

`PipelineContext` is the single root object that flows through the entire pipeline. Each pipeline stage has its own sub-DTO composed as a typed property. Services return their sub-DTO, and the caller sets it on the context.

```
PipelineContext
  ├── qu: QueryUnderstandingResult         (filled by QueryUnderstandingService)
  ├── semanticSelection: SemanticSelectionResult  (filled by SemanticContextSelector)
  ├── queryResult: QueryGenerationResult   (filled by QueryGenerator)
  ├── executionResult: QueryExecutionResult (filled by QueryExecutor)
  ├── similarQueries: array                (filled by ContextRetriever)
  ├── graphSchema: array                   (filled by ContextRetriever)
  ├── relevantEntities: array              (filled by ContextRetriever)
  ├── entityMetadata: array                (filled by ContextRetriever)
  ├── fileContext: array                   (filled by AiManager via FileContextProvider)
  ├── conversationContext: array           (filled by AiChatService)
  ├── selectionInfo: array                 (filled by ContextRetriever)
  ├── errors: array                        (accumulated by all stages)
  └── quality: array                       (assessed at end)
```

One object. Typed properties. Every section reads `$ctx->qu->clarifiedQuestion` instead of `$ctx['query_understanding']['clarified_question'] ?? ''`.

---

## Tasks

### Task 1: Create all DTOs

**Files:**
- Create: `src/DTOs/QueryUnderstandingResult.php`
- Create: `src/DTOs/SemanticSelectionResult.php`
- Create: `src/DTOs/QueryGenerationResult.php`
- Create: `src/DTOs/QueryExecutionResult.php`
- Create: `src/DTOs/PipelineContext.php`

**Step 1: QueryUnderstandingResult**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class QueryUnderstandingResult
{
    public function __construct(
        /** @var string[] Entity labels relevant to the question */
        public readonly array $entityTypes = [],
        /** @var string[] Person/contact names mentioned */
        public readonly array $personNames = [],
        /** @var string[] Property concepts ("insurance", "parking") */
        public readonly array $propertyConcepts = [],
        /** Rewritten query optimized for semantic search */
        public readonly string $searchQuery = '',
        /** Whether user is asking about their own data */
        public readonly bool $isPossessive = false,
        /** count|list|lookup|aggregate|compare|other */
        public readonly string $intent = 'other',
        /** data_query|knowledge_query|hybrid */
        public readonly string $questionType = 'hybrid',
        /** Clarified underlying question for better search/prompting */
        public readonly string $clarifiedQuestion = '',
    ) {}

    public static function empty(string $question = ''): self
    {
        return new self(
            isPossessive: $question !== '' && self::looksLikePossessive($question),
        );
    }

    private static function looksLikePossessive(string $question): bool
    {
        return (bool) preg_match('/\b(my|mine|i\s+have|i\s+own|belong.*to\s+me)\b/i', $question);
    }
}
```

**Step 2: SemanticSelectionResult**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class SemanticSelectionResult
{
    public function __construct(
        /** @var array<string, array{score: float, matched_text: string, config: ?array}> keyed by FQCN */
        public array $entities = [],
        /** @var array<string, array> keyed by relKey */
        public array $relationships = [],
        /** @var array<string, array{score: float, entity: string, matched_text: string}> keyed by scopeKey */
        public array $scopes = [],
        /** 'semantic' | 'keyword' */
        public readonly string $selectionMethod = 'semantic',
    ) {}
}
```

**Step 3: QueryGenerationResult**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class QueryGenerationResult
{
    public function __construct(
        public readonly ?string $cypher = null,
        public readonly string $explanation = '',
        public readonly float $confidence = 0.0,
        public readonly array $warnings = [],
        public readonly array $metadata = [],
    ) {}

    public function hasCypher(): bool
    {
        return $this->cypher !== null && $this->cypher !== '';
    }
}
```

**Step 4: QueryExecutionResult**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class QueryExecutionResult
{
    public function __construct(
        public readonly bool $success = false,
        public readonly array $data = [],
        public readonly array $stats = [],
        public readonly array $metadata = [],
        public readonly array $errors = [],
    ) {}

    public static function empty(): self
    {
        return new self(success: true);
    }
}
```

**Step 5: PipelineContext**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

/**
 * Single typed context object flowing through the entire AI pipeline.
 *
 * Built by ContextRetriever, enriched by AiManager, read by QueryGenerator,
 * ResponseGenerator, and all prompt/response sections.
 */
class PipelineContext
{
    public QueryUnderstandingResult $qu;
    public SemanticSelectionResult $semanticSelection;
    public QueryGenerationResult $queryResult;
    public QueryExecutionResult $executionResult;

    public array $similarQueries = [];
    public array $graphSchema = [];
    public array $relevantEntities = [];
    public array $entityMetadata = [];
    public array $selectionInfo = [];
    public array $errors = [];
    public array $quality = [];

    /** File context from FileContextProvider */
    public array $fileContext = [];

    /** Conversation context from chat pipeline */
    public array $conversationContext = [];

    public function __construct()
    {
        $this->qu = new QueryUnderstandingResult();
        $this->semanticSelection = new SemanticSelectionResult();
        $this->queryResult = new QueryGenerationResult();
        $this->executionResult = QueryExecutionResult::empty();
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }
}
```

---

### Task 2: Create LlmResolver for per-stage model selection

**Files:**
- Create: `src/Services/LlmResolver.php`
- Modify: `config/ai.php` — add `llm_roles` section after `llm` section (~line 299)
- Modify: `src/AiServiceProvider.php` — register LlmResolver

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;

class LlmResolver
{
    private array $cache = [];

    public function __construct(
        private readonly LlmProviderInterface $defaultProvider,
        private readonly array $roleConfig = []
    ) {}

    public function forRole(string $role): LlmProviderInterface
    {
        if (empty($this->roleConfig[$role])) {
            return $this->defaultProvider;
        }

        if (isset($this->cache[$role])) {
            return $this->cache[$role];
        }

        $config = $this->roleConfig[$role];
        $provider = $config['provider'] ?? config('ai.llm.default', 'openai');
        $baseConfig = config("ai.llm.{$provider}", []);
        $mergedConfig = array_merge($baseConfig, array_filter($config, fn($v) => $v !== null));

        $this->cache[$role] = match ($provider) {
            'openai' => new \Condoedge\Ai\LlmProviders\OpenAiLlmProvider($mergedConfig),
            'anthropic' => new \Condoedge\Ai\LlmProviders\AnthropicLlmProvider($mergedConfig),
            default => $this->defaultProvider,
        };

        return $this->cache[$role];
    }

    public function default(): LlmProviderInterface
    {
        return $this->defaultProvider;
    }
}
```

Config addition in `config/ai.php` after `llm` section:
```php
'llm_roles' => [
    // 'query_understanding' => ['model' => 'gpt-4o-mini', 'temperature' => 0.0],
    // 'query_generation' => null,
    // 'response_generation' => ['model' => 'gpt-4o-mini'],
],
```

Register in AiServiceProvider:
```php
$this->app->singleton(LlmResolver::class, function ($app) {
    return new LlmResolver(
        defaultProvider: $app->make(LlmProviderInterface::class),
        roleConfig: config('ai.llm_roles', [])
    );
});
```

---

### Task 3: Update QueryUnderstandingService — always-on, returns DTO

**Files:**
- Modify: `src/Services/QueryUnderstandingService.php`
- Modify: `src/AiServiceProvider.php` — wire LlmResolver

Changes:
- `analyze()` returns `QueryUnderstandingResult`
- `normalizeResponse()` builds the DTO including `clarifiedQuestion` and `questionType`
- `emptyResult()` becomes `QueryUnderstandingResult::empty()`
- Prompt updated to request `question_type`
- Remove the `enabled` config check (always runs now — gating moved to caller if needed)

Wire via `LlmResolver->forRole('query_understanding')` in provider.

---

### Task 4: Update SemanticContextSelector — returns DTO

**Files:**
- Modify: `src/Services/SemanticContextSelector.php` — returns `SemanticSelectionResult`

---

### Task 5: Update ContextRetriever — always call QU, build PipelineContext

**Files:**
- Modify: `src/Services/ContextRetriever.php` — returns `PipelineContext`
- Modify: `src/Contracts/ContextRetrieverInterface.php` — return type `PipelineContext`

Key restructuring:
1. Always call QU first → set `$ctx->qu`
2. Semantic selection with QU results → set `$ctx->semanticSelection`
3. Remove all the conditional `needsQU` logic
4. Similar queries, schema, examples, metadata → set on `$ctx` directly

---

### Task 6: Decompose ContextRetriever and AiManager for SRP

**The problem:** `ContextRetriever.retrieveContext()` is a 250-line method doing 6 different things. `AiManager.answerQuestion()` is 100 lines mixing orchestration with inline logic.

**Files:**
- Modify: `src/Services/ContextRetriever.php` — slim down to orchestration only
- Modify: `src/Services/AiManager.php` — clean pipeline with named steps

**ContextRetriever refactor:**

The method `retrieveContext()` should become a clean sequence of calls:

```php
public function retrieveContext(string $question, array $options = []): PipelineContext
{
    $ctx = new PipelineContext();

    $ctx->qu = $this->runQueryUnderstanding($question);
    $ctx->semanticSelection = $this->runSemanticSelection($question, $ctx->qu, $options);
    $ctx->similarQueries = $this->findSimilarQueries($question, $options);
    $ctx->graphSchema = $this->buildSchema($ctx->semanticSelection);
    $ctx->relevantEntities = $this->fetchExamples($ctx->semanticSelection, $options);
    $ctx->entityMetadata = $this->buildEntityMetadata($question, $ctx->semanticSelection);
    $ctx->selectionInfo = $this->summarizeSelection($ctx->semanticSelection, $ctx->qu);
    $ctx->quality = $this->assessQuality($ctx);

    return $ctx;
}
```

Each private method is short (10-30 lines), does one thing, and the orchestration reads like a recipe. The existing code inside `retrieveContext()` is redistributed into these methods — no new logic, just extraction.

**AiManager.answerQuestion() refactor:**

Same principle — the method becomes a clean pipeline:

```php
public function answerQuestion(string $question, array $options = []): array
{
    $ctx = $this->retrieveContext($question, $options);
    $ctx = $this->mergeConversationContext($ctx, $options);
    $ctx = $this->retrieveAndSetFileContext($ctx, $question, $options);

    if ($ctx->qu->questionType === 'knowledge_query' && !empty($ctx->fileContext)) {
        $ctx->queryResult = new QueryGenerationResult(metadata: ['source' => 'file_context']);
        $ctx->executionResult = QueryExecutionResult::empty();
    } else {
        $ctx->queryResult = $this->generateAndStoreQuery($question, $ctx, $options);
        $ctx->executionResult = $this->executeAndEnrich($ctx->queryResult, $options);
    }

    return $this->buildResponse($question, $ctx, $options);
}
```

Each extracted method has a clear name and single purpose. No 100-line inline blocks.

---

### Task 7: Update all consumers to use PipelineContext

**Files (mechanical migration — biggest task by file count):**
- Modify: `src/Services/QueryGenerator.php` — accepts `PipelineContext`
- Modify: `src/Contracts/QueryGeneratorInterface.php`
- Modify: `src/Services/SemanticPromptBuilder.php` — passes `PipelineContext` to sections
- Modify: `src/Contracts/PromptSectionInterface.php` — `format(string $question, PipelineContext $context, ...)`
- Modify: `src/Contracts/ResponseSectionInterface.php` — `format(PipelineContext $context, ...)`
- Modify every prompt section in `src/Services/PromptSections/`
- Modify every response section in `src/Services/ResponseSections/`

Pattern:
```php
// Before
$names = $context['resolved_names'] ?? [];
$scopes = $context['entity_metadata']['detected_scopes'] ?? [];
$cypher = $context['conversation_context']['last_cypher_query'] ?? '';

// After
$names = $context->qu->personNames;
$scopes = $context->entityMetadata['detected_scopes'] ?? [];
$cypher = $context->conversationContext['last_cypher_query'] ?? '';
```

---

### Task 8: Create FileContextEnricherInterface + default

**Files:**
- Create: `src/Contracts/FileContextEnricherInterface.php`
- Create: `src/Services/Context/DefaultFileContextEnricher.php`
- Modify: `src/Services/Context/FileContextProvider.php` — inject + call enricher
- Modify: `src/Services/PromptSections/FileContextSection.php` — show authority_note + priority instructions
- Modify: `src/AiServiceProvider.php` — register default enricher

```php
interface FileContextEnricherInterface
{
    public function enrich(array $files, string $question, mixed $user): array;
}
```

Default is pass-through. FileContextProvider calls enricher after search, re-sorts by `priority_score` if present.

FileContextSection shows `authority_note` and adds instruction: "When files have different authority levels, prioritize the more specific source."

---

### Task 9: Wire question_type — skip Cypher for knowledge queries

Already handled in Task 6 (AiManager decomposition). The `answerQuestion()` refactor includes:
- Use `$ctx->qu->clarifiedQuestion` for file search
- Check `$ctx->qu->questionType === 'knowledge_query'` and skip Cypher if file context found

---

### Task 10: CondoEdge-specific — file enricher + LLM roles

**Files (in ../condoedge):**
- Create: `app/Services/Ai/CondoEdgeFileContextEnricher.php`
- Modify: `app/Providers/AppServiceProvider.php` — bind enricher
- Modify: `config/ai.php` — add `llm_roles`

Enricher classifies files:
- `team_specific` (DB files matching user's team) → +1.0 boost
- `provincial_law` (physical files matching CCQ/law patterns) → +0.5 boost
- `general` → no boost

Both tiers included — enricher orders and annotates, response generator knows which takes precedence.

---

### Task 11: Integration verification

```bash
cd "C:/Users/jkend/Documents/Projects/kompo/CondoEdge"
php artisan ai:test-question "Can I tear down a wall in my unit?" --user=302 --debug
php artisan ai:test-question "How many units are there?" --user=302 --debug
php artisan ai:test-question "What insurance rules apply to my unit?" --user=302 --debug
```

---

## Implementation Order

```
Task 1: DTOs                    (no deps)
Task 2: LlmResolver             (no deps)
   ↓
Task 3: QU update               (needs 1, 2)
Task 4: Semantic selector DTO   (needs 1)
   ↓
Task 5: ContextRetriever        (needs 1, 3, 4)
   ↓
Task 6: Consumer migration      (needs 1, 5) — biggest task by file count
   ↓
Task 7: File enricher           (independent, can start anytime)
   ↓
Task 8: question_type gating    (needs 5, 6)
   ↓
Task 9: CondoEdge specifics     (needs 7, 8)
Task 10: Verification           (needs all)
```

Parallelizable: Tasks 1 + 2 + 7 can run simultaneously.
