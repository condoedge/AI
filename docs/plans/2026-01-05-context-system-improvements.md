# Context System Improvements Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Bring the context retrieval and query generation system to 10/10 by fixing data flow issues, ensuring all collected context is properly used, and improving follow-up question handling.

**Architecture:** The system collects rich context (similar queries, schema, entities, files, conversation history) but loses valuable data between turns. We'll fix the conversation context snapshot to preserve insights, execution stats, semantic selection info, and file metadata across turns.

**Tech Stack:** PHP 8.2, Laravel, Qdrant vector store, Neo4j graph database

---

## Task 1: Store Insights and Visualizations in Conversation Context

**Problem:** ResponseGenerator produces `insights` and `visualizations` but they're not stored in conversation context, making them unavailable for follow-up questions.

**Files:**
- Modify: `src/Services/Context/ConversationContextManager.php`
- Modify: `src/Services/Chat/AiChatService.php`
- Test: `tests/Unit/Services/Context/ConversationContextManagerTest.php`

**Step 1: Write the failing test**

Add to `tests/Unit/Services/Context/ConversationContextManagerTest.php`:

```php
/** @test */
public function it_stores_insights_and_visualizations_in_context(): void
{
    $conversation = AiConversation::create(['user_id' => 1]);

    $this->manager->recordResponse(
        $conversation,
        'There are 150 customers.',
        'MATCH (c:Customer) RETURN count(c)',
        [
            'data' => [['count' => 150]],
            'referenced_files' => [],
            'insights' => ['Customer count is stable', 'No growth detected'],
            'visualizations' => ['bar_chart', 'pie_chart'],
        ]
    );

    $conversation->refresh();
    $snapshot = $conversation->context_snapshot;

    $this->assertEquals(['Customer count is stable', 'No growth detected'], $snapshot['last_insights']);
    $this->assertEquals(['bar_chart', 'pie_chart'], $snapshot['last_visualizations']);
}
```

**Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Services/Context/ConversationContextManagerTest.php --filter=it_stores_insights_and_visualizations_in_context -v
```

Expected: FAIL - `last_insights` key doesn't exist

**Step 3: Update ConversationContextManager.recordResponse()**

In `src/Services/Context/ConversationContextManager.php`, update `recordResponse()`:

```php
public function recordResponse(
    AiConversation $conversation,
    string $response,
    ?string $cypherQuery,
    array $queryResult
): void {
    // ... existing code ...

    // Extract insights and visualizations
    $insights = $queryResult['insights'] ?? [];
    $visualizations = $queryResult['visualizations'] ?? [];

    // Build snapshot update
    $snapshotUpdate = [
        'focused_entity' => $focusedEntity,
        'mentioned_entities' => $newEntities,
        'last_relationships' => $cypherEntities['relationships'],
        'last_cypher_query' => $cypherQuery,
        'last_result_count' => count($queryResult['data'] ?? []),
        'last_result_sample' => $resultSample,
        'focused_entity_filter' => $entityFilter,
        'last_answer_summary' => Str::limit($response, 200),
        'updated_at' => now()->toIso8601String(),
    ];

    // Only update if there are new values (preserve previous)
    if (!empty($referencedFiles)) {
        $snapshotUpdate['last_referenced_files'] = $referencedFiles;
    }
    if (!empty($insights)) {
        $snapshotUpdate['last_insights'] = $insights;
    }
    if (!empty($visualizations)) {
        $snapshotUpdate['last_visualizations'] = $visualizations;
    }

    $conversation->updateContextSnapshot($snapshotUpdate);
}
```

**Step 4: Update AiChatService to pass insights/visualizations**

In `src/Services/Chat/AiChatService.php`, update `askWithConversation()`:

```php
$contextManager->recordResponse(
    $conversation,
    $answerText,
    $cypherQuery ?? '',
    [
        'data' => $queryData,
        'referenced_files' => $aiResponse['referenced_files'] ?? [],
        'insights' => $aiResponse['insights'] ?? [],
        'visualizations' => $aiResponse['visualizations'] ?? [],
    ]
);
```

**Step 5: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/Services/Context/ConversationContextManagerTest.php --filter=it_stores_insights_and_visualizations_in_context -v
```

Expected: PASS

**Step 6: Commit**

```bash
git add src/Services/Context/ConversationContextManager.php src/Services/Chat/AiChatService.php tests/Unit/Services/Context/ConversationContextManagerTest.php
git commit -m "feat(context): store insights and visualizations in conversation context"
```

---

## Task 2: Store Execution Statistics in Conversation Context

**Problem:** Query execution stats (time, result count, truncation) are lost between turns.

**Files:**
- Modify: `src/Services/Context/ConversationContextManager.php`
- Modify: `src/Services/Chat/AiChatService.php`
- Test: `tests/Unit/Services/Context/ConversationContextManagerTest.php`

**Step 1: Write the failing test**

```php
/** @test */
public function it_stores_execution_stats_in_context(): void
{
    $conversation = AiConversation::create(['user_id' => 1]);

    $this->manager->recordResponse(
        $conversation,
        'Found 150 customers.',
        'MATCH (c:Customer) RETURN c LIMIT 100',
        [
            'data' => array_fill(0, 100, ['name' => 'Test']),
            'stats' => [
                'execution_time_ms' => 45,
                'rows_returned' => 100,
                'rows_available' => 150,
                'was_truncated' => true,
            ],
        ]
    );

    $conversation->refresh();
    $snapshot = $conversation->context_snapshot;

    $this->assertEquals(45, $snapshot['last_execution_stats']['execution_time_ms']);
    $this->assertTrue($snapshot['last_execution_stats']['was_truncated']);
}
```

**Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Services/Context/ConversationContextManagerTest.php --filter=it_stores_execution_stats_in_context -v
```

**Step 3: Update recordResponse() to store stats**

```php
// In recordResponse(), add:
$executionStats = $queryResult['stats'] ?? [];

// In snapshotUpdate:
if (!empty($executionStats)) {
    $snapshotUpdate['last_execution_stats'] = $executionStats;
}
```

**Step 4: Update AiChatService to pass stats**

```php
$contextManager->recordResponse(
    $conversation,
    $answerText,
    $cypherQuery ?? '',
    [
        'data' => $queryData,
        'referenced_files' => $aiResponse['referenced_files'] ?? [],
        'insights' => $aiResponse['insights'] ?? [],
        'visualizations' => $aiResponse['visualizations'] ?? [],
        'stats' => $executionResult['stats'] ?? [],
    ]
);
```

**Step 5: Run test and verify pass**

**Step 6: Commit**

```bash
git add -A && git commit -m "feat(context): store execution statistics in conversation context"
```

---

## Task 3: Include Insights in ConversationContextSection Prompt

**Problem:** Even after storing insights, they're not included in the prompt for follow-up questions.

**Files:**
- Modify: `src/Services/Context/ConversationContextManager.php` (buildPromptContext)
- Modify: `src/Services/PromptSections/ConversationContextSection.php`
- Test: `tests/Unit/Services/PromptSections/ConversationContextSectionTest.php`

**Step 1: Write the failing test**

```php
/** @test */
public function it_includes_insights_in_prompt(): void
{
    $section = new ConversationContextSection();

    $context = [
        'conversation_context' => [
            'last_insights' => ['Customer growth is 15%', 'Most customers are in NYC'],
            'focused_entity' => 'Customer',
        ],
    ];

    $result = $section->format('follow up question', $context, []);

    $this->assertStringContainsString('Previous Insights', $result);
    $this->assertStringContainsString('Customer growth is 15%', $result);
    $this->assertStringContainsString('Most customers are in NYC', $result);
}
```

**Step 2: Run test to verify it fails**

**Step 3: Update buildPromptContext() to include insights**

In `ConversationContextManager.php`:

```php
public function buildPromptContext(AiConversation $conversation, int $maxHistory = 5): array
{
    $snapshot = $conversation->context_snapshot ?? [];
    $recentMessages = $conversation->getRecentMessages($maxHistory);

    return [
        'focused_entity' => $snapshot['focused_entity'] ?? null,
        'focused_entity_filter' => $snapshot['focused_entity_filter'] ?? null,
        'last_result_sample' => $snapshot['last_result_sample'] ?? [],
        'last_result_count' => $snapshot['last_result_count'] ?? 0,
        'last_cypher_query' => $snapshot['last_cypher_query'] ?? null,
        'last_query_type' => $snapshot['last_query_type'] ?? null,
        'last_relationships' => $snapshot['last_relationships'] ?? [],
        'mentioned_entities' => $snapshot['mentioned_entities'] ?? [],
        'last_answer_summary' => $snapshot['last_answer_summary'] ?? null,
        'last_referenced_files' => $snapshot['last_referenced_files'] ?? [],
        'last_insights' => $snapshot['last_insights'] ?? [],
        'last_visualizations' => $snapshot['last_visualizations'] ?? [],
        'last_execution_stats' => $snapshot['last_execution_stats'] ?? [],
        'recent_exchanges' => $this->formatRecentExchanges($recentMessages),
    ];
}
```

**Step 4: Update ConversationContextSection.format()**

```php
// Add after the file references section:
if (!empty($conversationContext['last_insights'])) {
    $output .= "\n**Previous Insights:**\n";
    foreach ($conversationContext['last_insights'] as $insight) {
        $output .= "- {$insight}\n";
    }
}

if (!empty($conversationContext['last_execution_stats']['was_truncated'])) {
    $stats = $conversationContext['last_execution_stats'];
    $output .= "\n**Note:** Previous results were truncated ";
    $output .= "(showing {$stats['rows_returned']} of {$stats['rows_available']} available).\n";
}
```

**Step 5: Run test and verify pass**

**Step 6: Commit**

```bash
git add -A && git commit -m "feat(context): include insights and stats in conversation prompt"
```

---

## Task 4: Add shouldInclude() to CurrentUserContextSection

**Problem:** CurrentUserContextSection is always included, wasting tokens when user context isn't relevant.

**Files:**
- Modify: `src/Services/PromptSections/CurrentUserContextSection.php`
- Test: `tests/Unit/Services/PromptSections/CurrentUserContextSectionTest.php`

**Step 1: Write the failing test**

```php
/** @test */
public function it_excludes_section_when_no_user_context(): void
{
    $section = new CurrentUserContextSection();

    // Mock no authenticated user
    Auth::shouldReceive('check')->andReturn(false);

    $result = $section->shouldInclude('How many orders?', [], []);

    $this->assertFalse($result);
}

/** @test */
public function it_includes_section_when_user_authenticated(): void
{
    $section = new CurrentUserContextSection();

    Auth::shouldReceive('check')->andReturn(true);
    Auth::shouldReceive('user')->andReturn((object)['id' => 1, 'name' => 'Test']);

    $result = $section->shouldInclude('How many orders?', [], []);

    $this->assertTrue($result);
}
```

**Step 2: Run test to verify it fails**

**Step 3: Add shouldInclude() method**

```php
public function shouldInclude(string $question, array $context, array $options = []): bool
{
    // Only include if there's an authenticated user
    if (!Auth::check()) {
        return false;
    }

    // Check if question might need user context
    $userKeywords = ['my', 'mine', 'i ', 'me ', 'assigned to me', 'my team'];
    $questionLower = strtolower($question);

    foreach ($userKeywords as $keyword) {
        if (str_contains($questionLower, $keyword)) {
            return true;
        }
    }

    // Include anyway if user is authenticated (might be useful)
    return true;
}
```

**Step 4: Run test and verify pass**

**Step 5: Commit**

```bash
git add -A && git commit -m "feat(prompt): add conditional inclusion for CurrentUserContextSection"
```

---

## Task 5: Store Full File Metadata in Conversation Context

**Problem:** File references lose download URLs and permissions after being stored in conversation context.

**Files:**
- Modify: `src/Services/Context/ConversationContextManager.php`
- Modify: `src/Services/ResponseSections/ResponseConversationContextSection.php`
- Test: `tests/Unit/Services/ResponseSections/ResponseConversationContextSectionTest.php`

**Step 1: Write the failing test**

```php
/** @test */
public function it_includes_file_download_urls_in_prompt(): void
{
    $section = new ResponseConversationContextSection();

    $context = [
        'conversation_context' => [
            'last_referenced_files' => [
                [
                    'ref' => 1,
                    'id' => 123,
                    'name' => 'report.pdf',
                    'snippet' => 'Quarterly sales report...',
                    'download_url' => '/files/123/download',
                    'can_download' => true,
                ],
            ],
        ],
    ];

    $result = $section->format($context, []);

    $this->assertStringContainsString('report.pdf', $result);
    $this->assertStringContainsString('/files/123/download', $result);
}
```

**Step 2: Run test to verify it fails**

**Step 3: Update ResponseConversationContextSection to show URLs**

```php
public function format(array $context, array $options = []): string
{
    // ... existing code ...

    if (!empty($conversationContext['last_referenced_files'])) {
        $output .= "**Files Referenced in Previous Response:**\n\n";

        foreach ($conversationContext['last_referenced_files'] as $index => $file) {
            $refNum = $file['ref'] ?? ($index + 1);
            $filename = $file['name'] ?? $file['filename'] ?? 'Unknown file';
            $fileId = $file['id'] ?? $file['file_id'] ?? '';
            $snippet = $file['snippet'] ?? '';
            $downloadUrl = $file['download_url'] ?? null;
            $canDownload = $file['can_download'] ?? false;

            $output .= "**[{$refNum}] {$filename}** (ID: {$fileId})\n";

            if ($canDownload && $downloadUrl) {
                $output .= "Download: {$downloadUrl}\n";
            }

            if (!empty($snippet)) {
                $output .= "Content:\n```\n{$snippet}\n```\n\n";
            }
        }

        // ... instructions ...
    }

    // ... rest of method ...
}
```

**Step 4: Run test and verify pass**

**Step 5: Commit**

```bash
git add -A && git commit -m "feat(context): include file download URLs in conversation context"
```

---

## Task 6: Pass Semantic Selection Info to Prompt Sections

**Problem:** Semantic selection results (`selection_info`) are stored but not passed to prompt sections, causing them to generate content for all entities instead of just selected ones.

**Files:**
- Modify: `src/Services/SemanticPromptBuilder.php`
- Modify: `src/Services/PromptSections/ExampleEntitiesSection.php`
- Test: `tests/Unit/Services/PromptSections/ExampleEntitiesSectionTest.php`

**Step 1: Write the failing test**

```php
/** @test */
public function it_only_shows_semantically_selected_entities(): void
{
    $section = new ExampleEntitiesSection();

    $context = [
        'relevant_entities' => [
            'Customer' => [['name' => 'John'], ['name' => 'Jane']],
            'Order' => [['id' => 1], ['id' => 2]],
            'Product' => [['sku' => 'ABC']],
        ],
        'selection_info' => [
            'selected_entities' => ['Customer', 'Order'],
            'method' => 'semantic',
        ],
    ];

    $result = $section->format('Show customers with orders', $context, []);

    $this->assertStringContainsString('Customer', $result);
    $this->assertStringContainsString('Order', $result);
    $this->assertStringNotContainsString('Product', $result);
}
```

**Step 2: Run test to verify it fails**

**Step 3: Update ExampleEntitiesSection to use selection_info**

```php
public function format(string $question, array $context, array $options = []): string
{
    $entities = $context['relevant_entities'] ?? [];
    $selectionInfo = $context['selection_info'] ?? [];

    if (empty($entities)) {
        return '';
    }

    // Filter entities if semantic selection was used
    if (!empty($selectionInfo['selected_entities'])) {
        $selectedLabels = $selectionInfo['selected_entities'];
        $entities = array_filter(
            $entities,
            fn($label) => in_array($label, $selectedLabels, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    // ... rest of formatting logic ...
}
```

**Step 4: Run test and verify pass**

**Step 5: Commit**

```bash
git add -A && git commit -m "feat(prompt): filter example entities by semantic selection"
```

---

## Task 7: Use Conversation Context for File Search Filtering

**Problem:** File search doesn't consider conversation context (focused entity, previous results), leading to overly broad searches.

**Files:**
- Modify: `src/Services/Context/FileContextProvider.php`
- Test: `tests/Unit/Services/Context/FileContextProviderTest.php`

**Step 1: Write the failing test**

```php
/** @test */
public function it_uses_conversation_context_for_file_search(): void
{
    // Setup mocks
    $mockSearchService = Mockery::mock(FileSearchService::class);
    $mockFilenameExtractor = Mockery::mock(FilenameExtractor::class);

    $mockFilenameExtractor->shouldReceive('extract')
        ->with('Which of those have offices in NYC?')
        ->andReturn([]);

    // Should search with conversation context keywords
    $mockSearchService->shouldReceive('searchByContent')
        ->with(
            Mockery::on(function ($query) {
                // Query should include focused entity context
                return str_contains($query, 'Team') || str_contains($query, 'offices in NYC');
            }),
            Mockery::any()
        )
        ->andReturn([]);

    $provider = new FileContextProvider($mockSearchService, $mockFilenameExtractor);

    $result = $provider->searchRelevantFiles(
        'Which of those have offices in NYC?',
        null,
        [
            'conversation_context' => [
                'focused_entity' => 'Team',
                'last_answer_summary' => 'Found 5 active teams including Sales, Marketing...',
            ],
        ]
    );
}
```

**Step 2: Run test to verify it fails**

**Step 3: Update FileContextProvider to use conversation context**

```php
public function searchRelevantFiles(string $question, mixed $user, array $options = []): array
{
    // ... existing code ...

    // Enhance query with conversation context
    $conversationContext = $options['conversation_context'] ?? [];
    $enhancedQuery = $this->enhanceQueryWithContext($question, $conversationContext);

    // Use enhanced query for content search
    $contentResults = $this->searchService->searchByContent($enhancedQuery, $searchOptions);

    // ... rest of method ...
}

private function enhanceQueryWithContext(string $question, array $conversationContext): string
{
    $enhanced = $question;

    // Add focused entity context
    if (!empty($conversationContext['focused_entity'])) {
        $entity = $conversationContext['focused_entity'];
        // Check if question is a follow-up (references "those", "them", etc.)
        if ($this->isFollowUpQuestion($question)) {
            $enhanced = "{$entity}: {$question}";
        }
    }

    return $enhanced;
}

private function isFollowUpQuestion(string $question): bool
{
    $followUpPatterns = ['those', 'them', 'these', 'which of', 'any of', 'the same'];
    $questionLower = strtolower($question);

    foreach ($followUpPatterns as $pattern) {
        if (str_contains($questionLower, $pattern)) {
            return true;
        }
    }

    return false;
}
```

**Step 4: Run test and verify pass**

**Step 5: Commit**

```bash
git add -A && git commit -m "feat(file-context): use conversation context to enhance file search"
```

---

## Task 8: Store Query Template in Conversation Context

**Problem:** When QueryGenerator detects a template (count, list, find_by_property), this information is lost after the response.

**Files:**
- Modify: `src/Services/QueryGenerator.php`
- Modify: `src/Services/Context/ConversationContextManager.php`
- Modify: `src/Services/Chat/AiChatService.php`
- Test: `tests/Unit/Services/Context/ConversationContextManagerTest.php`

**Step 1: Write the failing test**

```php
/** @test */
public function it_stores_detected_template_in_context(): void
{
    $conversation = AiConversation::create(['user_id' => 1]);

    $this->manager->recordResponse(
        $conversation,
        'There are 150 customers.',
        'MATCH (c:Customer) RETURN count(c)',
        [
            'data' => [['count' => 150]],
            'detected_template' => 'count',
            'query_type' => 'aggregation',
        ]
    );

    $conversation->refresh();
    $snapshot = $conversation->context_snapshot;

    $this->assertEquals('count', $snapshot['last_detected_template']);
    $this->assertEquals('aggregation', $snapshot['last_query_type']);
}
```

**Step 2: Run test to verify it fails**

**Step 3: Update QueryGenerator to return template info**

```php
// In QueryGenerator.generate(), include template detection in result:
return [
    'cypher' => $cypher,
    'explanation' => $explain ? $this->generateExplanation($cypher, $question) : '',
    'confidence' => $this->calculateConfidence($cypher, $context),
    'warnings' => $validation['warnings'],
    'metadata' => [
        'template_used' => $template['name'] ?? null,
        'query_type' => $this->detectQueryType($cypher),
        'retry_count' => $retryCount,
        'complexity' => $validation['complexity'],
    ],
];
```

**Step 4: Update recordResponse() to store template info**

```php
// In snapshotUpdate:
$detectedTemplate = $queryResult['detected_template'] ?? null;
$queryType = $queryResult['query_type'] ?? null;

if ($detectedTemplate) {
    $snapshotUpdate['last_detected_template'] = $detectedTemplate;
}
if ($queryType) {
    $snapshotUpdate['last_query_type'] = $queryType;
}
```

**Step 5: Run test and verify pass**

**Step 6: Commit**

```bash
git add -A && git commit -m "feat(context): store detected query template in conversation context"
```

---

## Task 9: Add Integration Test for Full Context Flow

**Problem:** Need end-to-end verification that all context flows correctly through multiple conversation turns.

**Files:**
- Create: `tests/Feature/ConversationContextFlowTest.php`

**Step 1: Write the integration test**

```php
<?php

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class ConversationContextFlowTest extends TestCase
{
    /** @test */
    public function it_preserves_context_across_multiple_turns(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $chatService = app(AiChatService::class);

        // Mock AI facade to return predictable responses
        // ... setup mocks ...

        // Turn 1: Initial question
        $response1 = $chatService->askWithConversation(
            'How many customers do we have?',
            $conversation
        );

        $conversation->refresh();
        $snapshot1 = $conversation->context_snapshot;

        // Verify context was stored
        $this->assertNotNull($snapshot1['last_cypher_query']);
        $this->assertNotNull($snapshot1['last_result_count']);

        // Turn 2: Follow-up question
        $response2 = $chatService->askWithConversation(
            'Which of those are active?',
            $conversation
        );

        $conversation->refresh();
        $snapshot2 = $conversation->context_snapshot;

        // Verify previous context was preserved and new context added
        $this->assertArrayHasKey('focused_entity', $snapshot2);
        $this->assertArrayHasKey('last_cypher_query', $snapshot2);

        // Turn 3: Another follow-up
        $response3 = $chatService->askWithConversation(
            'Show me the details of the first one',
            $conversation
        );

        $conversation->refresh();
        $snapshot3 = $conversation->context_snapshot;

        // Verify chain of context
        $this->assertNotEmpty($snapshot3['mentioned_entities']);
    }

    /** @test */
    public function it_preserves_file_references_across_turns(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        // Simulate first turn with file reference
        $conversation->updateContextSnapshot([
            'last_referenced_files' => [
                ['id' => 1, 'name' => 'report.pdf', 'snippet' => 'Q1 sales...'],
            ],
        ]);

        // Simulate second turn (no new files)
        $conversation->updateContextSnapshot([
            'focused_entity' => 'Sales',
            'last_cypher_query' => 'MATCH (s:Sale) RETURN s',
        ]);

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        // File references should still be there
        $this->assertNotEmpty($snapshot['last_referenced_files']);
        $this->assertEquals('report.pdf', $snapshot['last_referenced_files'][0]['name']);
    }
}
```

**Step 2: Run test**

```bash
vendor/bin/phpunit tests/Feature/ConversationContextFlowTest.php -v
```

**Step 3: Commit**

```bash
git add tests/Feature/ConversationContextFlowTest.php
git commit -m "test(context): add integration tests for conversation context flow"
```

---

## Summary

| Task | Description | Priority |
|------|-------------|----------|
| 1 | Store insights/visualizations in conversation context | High |
| 2 | Store execution statistics in conversation context | High |
| 3 | Include insights in ConversationContextSection prompt | High |
| 4 | Add shouldInclude() to CurrentUserContextSection | Medium |
| 5 | Store full file metadata (URLs) in conversation context | Medium |
| 6 | Pass semantic selection info to prompt sections | High |
| 7 | Use conversation context for file search filtering | Medium |
| 8 | Store query template in conversation context | Low |
| 9 | Add integration test for full context flow | High |

**Estimated complexity:** Medium - mostly additive changes, no major refactoring required.

**Risk areas:**
- Task 6 (semantic selection filtering) may affect query generation quality - test thoroughly
- Task 7 (file search enhancement) may introduce false positives - tune keywords carefully
