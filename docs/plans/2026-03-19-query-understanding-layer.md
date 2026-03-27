# Query Understanding Layer Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a Query Understanding (QU) layer that runs before semantic context selection to enrich user questions with entity resolution, name lookup, concept expansion, and intent classification — dramatically improving context selection accuracy.

**Architecture:** A new `QueryUnderstandingService` performs a lightweight LLM call with the question + entity catalog (labels, aliases, property groups). It outputs: identified entity types, extracted person/place names, concept expansions, and a rewritten search query. This enriched output feeds into the existing `SemanticContextSelector` and `ContextRetriever` to improve entity matching. The service also feeds resolved names into the prompt so the LLM can generate correct property filters.

**Tech Stack:** PHP 8.1+, Laravel, OpenAI/Anthropic LLM (uses existing `LlmProviderInterface`), existing entity configs.

---

## Context & Problem

When users ask "Is Bassem in my team?" or "What is my insurance information?", the semantic context selector fails because:
- "Bassem" is a person's NAME, not an entity type — vector similarity against entity descriptions won't match
- "insurance" is a PROPERTY CONCEPT on Unit, not an entity — the selector can't map it to Unit
- The system has no way to resolve names to records or map concepts to their parent entities

Production RAG systems solve this with a Query Understanding layer that pre-processes the question before retrieval.

## Bugs to Fix Along the Way

These existing bugs were discovered during investigation and MUST be fixed as part of this work:

### Bug 1: RelationshipsSection.getRelevantEntityConfigs() — broken entity lookup
**File:** `src/Services/PromptSections/RelationshipsSection.php:104-111`
**Problem:** Line 107 does `array_map(fn($m) => $m['label'] ?? '', $metadata)` but `$metadata` is `entity_metadata` with keys `detected_entities`, `entity_metadata`, `detected_scopes` — not an array of objects with `label`.
**Impact:** RelationshipsSection never shows relationships when using the metadata fallback path. The LLM has no relationship direction info for many queries.
**Fix:** Use `$metadata['detected_entities']` and resolve to configs properly.

### Bug 2: ExampleEntitiesSection date hint — only shows `<` operator
**File:** `src/Services/PromptSections/ExampleEntitiesSection.php:91`
**Problem:** Shows `compare as: property < '2024-01-15'` — only `<`. LLM may not know it can use `=`, `>`, `>=`, etc.
**Fix:** Change to show multiple comparison operators.

---

## Tasks

### Task 1: Fix RelationshipsSection entity config lookup

**Files:**
- Modify: `src/Services/PromptSections/RelationshipsSection.php:90-116`

**Step 1: Fix the getRelevantEntityConfigs method**

Replace the broken metadata fallback (lines 103-112) with correct entity resolution:

```php
private function getRelevantEntityConfigs(array $context): array
{
    $allConfigs = config('entities', []);

    // If semantic selection identified specific entities, use only those
    $selectedEntities = $context['selection_info']['selected_entities'] ?? [];
    if (!empty($selectedEntities)) {
        return array_filter($allConfigs, function ($config) use ($selectedEntities) {
            $label = $config['graph']['label'] ?? '';
            return in_array($label, $selectedEntities);
        });
    }

    // If entity_metadata has detected entities, resolve them to configs
    $metadata = $context['entity_metadata'] ?? [];
    $detectedEntities = $metadata['detected_entities'] ?? [];
    if (!empty($detectedEntities)) {
        return array_filter($allConfigs, function ($config, $fqcn) use ($detectedEntities) {
            $label = $config['graph']['label'] ?? '';
            // Match by either FQCN or short label
            return in_array($fqcn, $detectedEntities) || in_array($label, $detectedEntities);
        }, ARRAY_FILTER_USE_BOTH);
    }

    // Fallback: load all (but this should be avoided)
    return $allConfigs;
}
```

**Step 2: Verify by running test question**

Run: `cd "C:/Users/jkend/Documents/Projects/kompo/CondoEdge" && php artisan ai:test-question "Who lives in unit 3?" --user=302 --debug`
Expected: RelationshipsSection should now show relationship directions in the prompt.

---

### Task 2: Fix ExampleEntitiesSection date comparison hint

**Files:**
- Modify: `src/Services/PromptSections/ExampleEntitiesSection.php:90-91`

**Step 1: Update date format hint**

Replace line 91:
```php
return "'{$value}' (string date — compare as: property < '{$value}')";
```

With:
```php
return "'{$value}' (string date — compare with string operators: =, <, >, <=, >=)";
```

---

### Task 3: Create QueryUnderstandingService

**Files:**
- Create: `src/Services/QueryUnderstandingService.php`

This is the core new module. It takes a raw question and returns enriched metadata.

**Step 1: Create the service**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Support\AiLogger;

/**
 * QueryUnderstandingService
 *
 * Pre-processes user questions before semantic context selection.
 * Uses a fast LLM call to:
 * 1. Identify which entity types the question is about
 * 2. Extract person/place names for record lookup
 * 3. Expand property concepts to their parent entities
 * 4. Classify the user's intent
 *
 * This dramatically improves semantic context selection accuracy
 * for questions like "Is Bassem in my team?" or "What is my insurance?"
 */
class QueryUnderstandingService
{
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly AiLogger $logger,
        private readonly array $config = []
    ) {}

    /**
     * Analyze a question and return enriched understanding.
     *
     * @param string $question Raw user question
     * @param array $entityCatalog Summary of available entities (labels, aliases, property groups)
     * @return array Enriched question understanding
     */
    public function analyze(string $question, array $entityCatalog): array
    {
        $this->logger->startTimer('query_understanding');

        try {
            $prompt = $this->buildPrompt($question, $entityCatalog);

            $response = $this->llm->chatJson([
                ['role' => 'system', 'content' => 'You are a query understanding system. Analyze the user question and return structured JSON. Be precise and concise.'],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.0,
                'max_tokens' => 500,
            ]);

            $result = is_object($response) ? (array) $response : $response;

            $this->logger->info('query_understanding', 'Analysis complete', [
                'entities' => $result['entity_types'] ?? [],
                'names' => $result['person_names'] ?? [],
                'concepts' => $result['property_concepts'] ?? [],
                'duration_ms' => $this->logger->stopTimer('query_understanding'),
            ]);

            return $this->normalizeResult($result);

        } catch (\Exception $e) {
            $this->logger->error('query_understanding', 'Analysis failed: ' . $e->getMessage());
            $this->logger->stopTimer('query_understanding');

            // Graceful degradation - return empty understanding
            return $this->emptyResult();
        }
    }

    /**
     * Build a compact entity catalog from entity configs.
     * This is the "menu" the LLM uses to understand what entities exist.
     */
    public static function buildEntityCatalog(array $entityConfigs): array
    {
        $catalog = [];

        foreach ($entityConfigs as $fqcn => $config) {
            $label = $config['graph']['label'] ?? class_basename($fqcn);
            $metadata = $config['metadata'] ?? [];
            $properties = $config['graph']['properties'] ?? [];

            $entry = [
                'label' => $label,
                'aliases' => $metadata['aliases'] ?? [],
                'description' => $metadata['description'] ?? '',
            ];

            // Group properties into semantic categories for concept mapping
            $propertyGroups = [];
            foreach ($metadata['common_properties'] ?? [] as $prop => $desc) {
                // Extract concept keywords from property descriptions
                if (is_string($desc) && strlen($desc) > 5) {
                    $propertyGroups[$prop] = $desc;
                }
            }
            if (!empty($propertyGroups)) {
                $entry['property_groups'] = $propertyGroups;
            }

            $catalog[$label] = $entry;
        }

        return $catalog;
    }

    private function buildPrompt(string $question, array $entityCatalog): string
    {
        $prompt = "Analyze this question for a condo management system.\n\n";
        $prompt .= "QUESTION: \"{$question}\"\n\n";

        $prompt .= "AVAILABLE ENTITIES:\n";
        foreach ($entityCatalog as $label => $info) {
            $aliases = !empty($info['aliases']) ? ' (aliases: ' . implode(', ', array_slice($info['aliases'], 0, 5)) . ')' : '';
            $prompt .= "- {$label}{$aliases}: {$info['description']}\n";
        }

        $prompt .= "\nReturn JSON with:\n";
        $prompt .= "{\n";
        $prompt .= "  \"entity_types\": [\"Label1\", \"Label2\"],  // Which entity types is this question about?\n";
        $prompt .= "  \"person_names\": [\"Name1\"],  // Any person names mentioned (not entity types)?\n";
        $prompt .= "  \"property_concepts\": [{\"concept\": \"insurance\", \"likely_entity\": \"Unit\", \"likely_properties\": [\"insurance_company\", \"insurance_term\"]}],  // Concepts that map to entity properties\n";
        $prompt .= "  \"search_query\": \"expanded query for semantic search\",  // Rewrite question for better entity matching\n";
        $prompt .= "  \"intent\": \"lookup|count|list|compare|aggregate\"  // What does the user want?\n";
        $prompt .= "}\n";

        return $prompt;
    }

    private function normalizeResult(array $result): array
    {
        return [
            'entity_types' => array_filter((array) ($result['entity_types'] ?? [])),
            'person_names' => array_filter((array) ($result['person_names'] ?? [])),
            'property_concepts' => (array) ($result['property_concepts'] ?? []),
            'search_query' => $result['search_query'] ?? '',
            'intent' => $result['intent'] ?? 'lookup',
        ];
    }

    private function emptyResult(): array
    {
        return [
            'entity_types' => [],
            'person_names' => [],
            'property_concepts' => [],
            'search_query' => '',
            'intent' => 'lookup',
        ];
    }
}
```

---

### Task 4: Integrate QueryUnderstanding into ContextRetriever

**Files:**
- Modify: `src/Services/ContextRetriever.php`

**Step 1: Add QueryUnderstandingService dependency**

Add to constructor:
```php
private ?QueryUnderstandingService $queryUnderstanding = null;
```

Add setter:
```php
public function setQueryUnderstanding(QueryUnderstandingService $qu): self
{
    $this->queryUnderstanding = $qu;
    return $this;
}
```

**Step 2: Call QU before semantic selection in retrieveContext()**

In `retrieveContext()`, before the semantic context selection block (around line 211), add:

```php
// Pre-process question with Query Understanding (if available)
$queryUnderstandingResult = null;
if ($this->queryUnderstanding !== null) {
    try {
        $entityCatalog = QueryUnderstandingService::buildEntityCatalog($this->entityConfigs);
        $queryUnderstandingResult = $this->queryUnderstanding->analyze($question, $entityCatalog);
        $context['query_understanding'] = $queryUnderstandingResult;
    } catch (\Exception $e) {
        $this->logger->error('context_retriever', 'Query understanding failed: ' . $e->getMessage());
    }
}
```

**Step 3: Use QU results to enhance semantic selection**

When calling `selectRelevantContext`, pass the QU result:

```php
$semanticContext = $this->contextSelector->selectRelevantContext(
    $question,
    $this->entityConfigs,
    [
        'threshold' => config('ai.semantic_context.threshold', 0.65),
        'top_k' => config('ai.semantic_context.top_k', 10),
        'query_understanding' => $queryUnderstandingResult,
    ]
);
```

**Step 4: Pass resolved names to the context for prompt building**

After building entity_metadata, add person name context:

```php
if ($queryUnderstandingResult !== null && !empty($queryUnderstandingResult['person_names'])) {
    $context['resolved_names'] = $queryUnderstandingResult['person_names'];
}
if ($queryUnderstandingResult !== null && !empty($queryUnderstandingResult['property_concepts'])) {
    $context['property_concepts'] = $queryUnderstandingResult['property_concepts'];
}
```

---

### Task 5: Enhance SemanticContextSelector with QU results

**Files:**
- Modify: `src/Services/SemanticContextSelector.php`

**Step 1: Use QU entity_types to force-include entities**

In `selectRelevantContext()`, after the vector search results are processed but before sorting, add:

```php
// Force-include entities identified by Query Understanding
$quResult = $options['query_understanding'] ?? null;
if ($quResult !== null && !empty($quResult['entity_types'])) {
    foreach ($quResult['entity_types'] as $entityType) {
        $fullName = $this->findFullEntityName($entityType, $entityConfigs);
        if ($fullName && !isset($seenEntities[$fullName]) && !isset($seenEntities[$entityType])) {
            $seenEntities[$fullName] = true;
            $seenEntities[$entityType] = true;
            $selectedEntities[$fullName] = [
                'score' => 0.8, // High confidence from QU
                'matched_text' => "Identified by query understanding",
                'config' => $entityConfigs[$fullName] ?? null,
            ];
        }
    }
}

// Use expanded search query for better vector matching
if ($quResult !== null && !empty($quResult['search_query'])) {
    // Re-search with expanded query if original returned few results
    if (count($selectedEntities) <= 1) {
        $expandedEmbedding = $this->embeddingProvider->embed($quResult['search_query']);
        $expandedResults = $this->vectorStore->search(
            $collectionName,
            $expandedEmbedding,
            $topK,
            []
        );
        // Process expanded results same as original (merge, don't replace)
        // ... (same processing loop as lines 96-208)
    }
}
```

**Step 2: Use property_concepts to map concepts to entities**

```php
// Force-include entities from property concept mapping
if ($quResult !== null && !empty($quResult['property_concepts'])) {
    foreach ($quResult['property_concepts'] as $concept) {
        $likelyEntity = $concept['likely_entity'] ?? '';
        if (empty($likelyEntity)) continue;

        $fullName = $this->findFullEntityName($likelyEntity, $entityConfigs);
        if ($fullName && !isset($seenEntities[$fullName])) {
            $seenEntities[$fullName] = true;
            $selectedEntities[$fullName] = [
                'score' => 0.75,
                'matched_text' => "Property concept: " . ($concept['concept'] ?? ''),
                'config' => $entityConfigs[$fullName] ?? null,
            ];
        }
    }
}
```

---

### Task 6: Create NameResolutionSection prompt section

**Files:**
- Create: `src/Services/PromptSections/NameResolutionSection.php`

When QU detects person names, this section tells the LLM to search for them by name in the graph.

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * NameResolutionSection
 *
 * When query understanding detects person names, this section
 * instructs the LLM how to find those people in the graph.
 *
 * Priority: 18 (after CurrentUserContext, before Schema)
 */
class NameResolutionSection extends BasePromptSection
{
    protected string $name = 'name_resolution';
    protected int $priority = 18;

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        return !empty($context['resolved_names']) || !empty($context['property_concepts']);
    }

    public function format(string $question, array $context, array $options = []): string
    {
        $output = '';

        $names = $context['resolved_names'] ?? [];
        if (!empty($names)) {
            $output .= $this->header('PERSON NAME RESOLUTION');
            $output .= "The question mentions these person names. Search for them in the graph:\n\n";

            foreach ($names as $name) {
                $output .= "- \"{$name}\": Search in User (by name), Contact (by first_name, last_name), or Supplier (by name).\n";
                $output .= "  Use: WHERE toLower(entity.name) CONTAINS toLower(\"{$name}\") or WHERE toLower(entity.first_name) CONTAINS toLower(\"{$name}\") or WHERE toLower(entity.last_name) CONTAINS toLower(\"{$name}\")\n\n";
            }
        }

        $concepts = $context['property_concepts'] ?? [];
        if (!empty($concepts)) {
            $output .= $this->header('PROPERTY CONCEPT MAPPING');
            $output .= "The question asks about these concepts which are PROPERTIES on entities (not separate entities):\n\n";

            foreach ($concepts as $concept) {
                $conceptName = $concept['concept'] ?? '';
                $entity = $concept['likely_entity'] ?? '';
                $props = $concept['likely_properties'] ?? [];

                if ($conceptName && $entity) {
                    $output .= "- \"{$conceptName}\" → Properties on {$entity}: " . implode(', ', $props) . "\n";
                    $output .= "  Query these properties from the {$entity} entity.\n\n";
                }
            }
        }

        return $output;
    }
}
```

---

### Task 7: Register new services in AiServiceProvider

**Files:**
- Modify: `src/AiServiceProvider.php`

**Step 1: Register QueryUnderstandingService**

In the service provider's register method, add:

```php
$this->app->singleton(QueryUnderstandingService::class, function ($app) {
    return new QueryUnderstandingService(
        $app->make(LlmProviderInterface::class),
        $app->make(AiLogger::class),
        config('ai.query_understanding', [])
    );
});
```

**Step 2: Wire QU into ContextRetriever**

Where ContextRetriever is registered, add the QU service:

```php
// After ContextRetriever is created, set QU if enabled
if (config('ai.query_understanding.enabled', true)) {
    $retriever->setQueryUnderstanding($app->make(QueryUnderstandingService::class));
}
```

**Step 3: Register NameResolutionSection in default prompt sections**

Add to the `query_generator_sections` config array (or register dynamically).

---

### Task 8: Add config for Query Understanding

**Files:**
- Modify: `config/ai.php`

Add after the `semantic_context` section:

```php
/*
|--------------------------------------------------------------------------
| Query Understanding
|--------------------------------------------------------------------------
|
| Pre-processes questions before semantic context selection.
| Uses a fast LLM call to identify entities, resolve names,
| and expand concepts for better context matching.
|
*/
'query_understanding' => [
    'enabled' => env('AI_QUERY_UNDERSTANDING_ENABLED', true),

    // Maximum tokens for the QU prompt (keep small for speed)
    'max_tokens' => 500,

    // Temperature (0.0 for deterministic)
    'temperature' => 0.0,
],
```

---

### Task 9: Update TestQuestionCommand to show QU results

**Files:**
- Modify: `src/Console/Commands/TestQuestionCommand.php`

Add after context retrieval output:

```php
// Show Query Understanding results
$qu = $context['query_understanding'] ?? null;
if ($qu && $showDebug) {
    $this->newLine();
    $this->comment('Query Understanding:');
    if (!empty($qu['entity_types'])) {
        $this->line('  Entity types: ' . implode(', ', $qu['entity_types']));
    }
    if (!empty($qu['person_names'])) {
        $this->line('  Person names: ' . implode(', ', $qu['person_names']));
    }
    if (!empty($qu['property_concepts'])) {
        foreach ($qu['property_concepts'] as $c) {
            $this->line('  Concept: "' . ($c['concept'] ?? '') . '" → ' . ($c['likely_entity'] ?? '') . ' (' . implode(', ', $c['likely_properties'] ?? []) . ')');
        }
    }
    if (!empty($qu['search_query'])) {
        $this->line('  Expanded query: ' . $qu['search_query']);
    }
    $this->line('  Intent: ' . ($qu['intent'] ?? 'unknown'));
}
```

---

### Task 10: End-to-end verification

Run these test questions and verify they all produce correct answers:

1. `"Is Bassem in my team?"` — Should find User "Bassem El Hachem" via name search
2. `"What is my insurance information?"` — Should map "insurance" → Unit properties
3. `"Can I get details about my unit?"` — Should still work (regression)
4. `"Can you give me the members of condoedge team?"` — Should still work (regression)
5. `"Who lives in unit PH?"` — Should still work (regression)
6. `"For which units does Bassem have access?"` — Should resolve Bassem → Contact → ContactUnit → Unit
7. `"How many units have expired insurance?"` — Should map to Unit.insurance_term scope
8. `"Show me the board members"` — Should find BoardMember entity

Clear the questions cache before each test:
```bash
php artisan tinker --execute="app(\Condoedge\Ai\Contracts\VectorStoreInterface::class)->deleteAll('questions');"
```
