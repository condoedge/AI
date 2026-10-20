# Conversation Context Management

Track conversation context across turns so users can ask natural follow-up questions ("how many of those…", "export that", "et celles-là ?").

---

## Overview

A conversation's state lives in the `context_snapshot` JSON column of `AiConversation`. It is **written once per turn, after the pipeline ran**, and handed to the next turn as its `conversation_context`.

The **semantic** judgments about the *current* question are made by the Query Understanding (QU) LLM call, which receives that conversation context:

- whether the question refers back to the result just shown (`followUpReference`);
- which entity types it is about (`entityTypes`);
- its intent (`count | list | lookup | aggregate | compare | other`), language and export format.

There is no pre-pipeline regex/word-list analysis of the question. The question reaches the pipeline **verbatim**; it is never rewritten.

```
User: "Show me the members of my team"         → QU: entity_types [User], follow_up false
AI:   [roster, 523 rows]                        → snapshot: last_cypher_query, sample, last_entity_types [Team, User]

User: "Ok, could you add their last login?"     → QU: follow_up_reference true, entity_types []
AI:   [same roster + last login]                → retrieval re-seeded from last_entity_types (anchor)

User: "Parfait — exporte-la en Excel"           → QU: follow_up_reference true, export_format excel, language fr
AI:   [Excel of the previous result, French copy]
```

---

## The Turn Flow

```
AskQuestionUseCase::execute()
  │
  ├─ 0. Security gate (ChatSecurityGuard) — before anything else
  │
  ├─ 1. conversation_context = ConversationContextManager::buildPromptContext($conversation)
  │     (the snapshot recorded after the PREVIOUS turn + recent exchanges)
  │     user message persisted (context_used = that exact context)
  │
  ├─ 2. AI::answerQuestion($question, ['conversation_context' => …, 'user' => …, …])
  │       ├─ QU (QueryUnderstandingService::analyze) sees the conversation block
  │       ├─ entity selection: QU entity types (+ anchor / conversation focus, see below)
  │       ├─ file context, query generation, execution, composition
  │       └─ response metadata stamps QU's subject, entity_types, intent
  │
  └─ 3. ConversationContextManager::recordResponse() — snapshot write-back,
        committed together with the assistant message
```

### How the focus is decided

| Where | Rule |
|-------|------|
| This turn's selection | QU `entityTypes` are merged into semantic selection (`ai.semantic_context.qu_entity_score`, 0.8). |
| Conversation focus carry | The snapshot's `focused_entity` (recorded after the previous turn) is soft-included (`conversation_focus_score`, 0.55) **only when QU named no entity type**. |
| Augmentation anchor | When QU says `followUpReference` **and** a data result is recorded (`last_cypher_query`), the previous turn's `last_entity_types` (fallback `focused_entity`) are re-seeded at `anchor_entity_score` (0.8), in addition to whatever QU produced. |
| Snapshot after the turn | `focused_entity` = first label of the **executed** Cypher; if no Cypher ran, QU `entityTypes[0]` (from response `metadata.entity_types`); with neither, the previous focus is kept. |

### What `followUpReference` drives downstream

- **Export of the previous result:** an export ask that refers back uses the recorded result (`last_cypher_query` is re-executed through the scoped executor to recover the full set; digest turns keep their rows in full).
- **Augmentation anchor:** see the table above.
- **File-search follow-up angle:** the verbatim-question angle is searched as `"{focused_entity}: {question}"`.
- **Few-shot hygiene:** a follow-up turn is never stored as a question→Cypher example.

---

## Components

### ConversationContextManager

**Location:** `src/Services/Context/ConversationContextManager.php` (container singleton)

```php
$manager = app(\Condoedge\Ai\Services\Context\ConversationContextManager::class);

// After a turn (AskQuestionUseCase does this for you)
$manager->recordResponse($conversation, $answerText, $cypherOrEmpty, [
    'data' => $rows,
    'referenced_files' => $files,
    'metadata' => $aiResponse['metadata'], // subject, entity_types, intent, response.digest
]);

// Before a turn: the context the pipeline (and QU) receives
$context = $manager->buildPromptContext($conversation);            // ai.chat.max_history_messages
$context = $manager->buildPromptContext($conversation, maxHistory: 5);
```

**`recordResponse()` rules**

| Snapshot key | Written |
|--------------|---------|
| `focused_entity` | Every turn (see focus rules above) |
| `mentioned_entities` | Every turn; previous + this turn's entity types, de-duplicated, **most recent 10** (`MAX_MENTIONED_ENTITIES`) |
| `last_relationships`, `last_answer_summary` (500 chars), `updated_at` | Every turn |
| `last_cypher_query`, `last_cypher_parameters`, `last_result_count`, `last_result_sample`, `focused_entity_filter`, `last_entity_types` | **Only when the turn produced rows** (preserve-on-empty) |
| `last_subject` | Only when `metadata.subject` is non-empty |
| `last_referenced_files` | Only when the turn referenced files |
| `last_query_type` | From `query_type`, else `metadata.intent` (QU intent) |

**Preserve-on-empty.** A turn that showed no rows (a documents answer, a 0-row query, a caught error) must not erase what the user was last *shown*: "export that" after a documents turn still exports the previous roster. `last_result_sample` is the first 10 rows of an ordinary turn (export recovers the full set by re-executing `last_cypher_query`); a **digest** turn records no Cypher and keeps its display-capped rows in full.

**PII in the snapshot (data retention).** `last_result_sample` holds real row values, and `last_cypher_parameters` holds **raw PII values**: the `$pii_…` query parameters (name → real email, phone and so on) that replaced the PII tokens the model copied into its query. That is the same class of data as `last_result_sample`. Both live in the conversation's `context_snapshot` and are never sent to an LLM: prompts only ever see tokens such as `[email#1]` and parameter names. They are retained exactly as long as the conversation, and deleting the conversation deletes them. On read, only keys matching `^pii_[a-f0-9]{12}$` are honoured. Hosts with a stricter retention policy for personal data should prune conversations accordingly.

**`buildPromptContext()`** returns `focused_entity`, `focused_entity_filter`, `last_result_sample`, `last_result_count`, `last_cypher_query`, `last_cypher_parameters`, `last_entity_types`, `last_subject`, `last_query_type`, `last_relationships`, `mentioned_entities`, `last_answer_summary`, `last_referenced_files` and `recent_exchanges` (assistant answers truncated to `ai.chat.history_answer_chars`, default 800).

### EntityExtractor

**Location:** `src/Services/Context/EntityExtractor.php`

Structural only: labels and relationship types of an **executed** Cypher query. It does not analyse natural-language questions.

```php
$result = app(\Condoedge\Ai\Services\Context\EntityExtractor::class)
    ->extractFromCypher('MATCH (c:Customer)-[:PLACED]->(o:Order) RETURN c');
// ['entities' => ['Customer', 'Order'], 'relationships' => ['PLACED']]
```

### FilenameExtractor

**Location:** `src/Services/Context/FilenameExtractor.php`

Extracts explicit filenames (`report.pdf`, `"my document.pdf"`, `docs/readme.md`) so file search can match by filename alongside semantic search.

```php
$filenames = (new \Condoedge\Ai\Services\Context\FilenameExtractor())->extract('Show me the report.pdf file');
// ['report.pdf']
```

### ConversationContextSection (query generation prompt)

**Location:** `src/Services/PromptSections/ConversationContextSection.php` — priority 55.

Included when the context has a `focused_entity`, `recent_exchanges`, `last_cypher_query`, `last_result_sample` or `last_referenced_files`. It renders the current focus (and active filter), the previous query and its result count, a result sample, recent exchanges, the entities discussed and previously referenced files.

### ResponseConversationContextSection (response prompt)

**Location:** `src/Services/ResponseSections/ResponseConversationContextSection.php` — priority 45.

Included when the context has `last_referenced_files`, `last_result_sample` or `focused_entity`; lets the answer refer to "the file" / the previous results.

---

## Data Storage

### AiConversation

`context_snapshot` (JSON) holds the keys listed under `recordResponse()`. Accessors:

```php
$conversation->getFocusedEntity();      // ?string
$conversation->getMentionedEntities();  // string[] (most recent 10)
$conversation->getLastResultSample();   // array
$conversation->context_snapshot['last_cypher_query'] ?? null;

$conversation->updateContextSnapshot(['last_subject' => 'team members']); // merges
```

### AiMessage

| Field | Description |
|-------|-------------|
| `role` / `content` | `user` or `assistant`, message text |
| `context_used` | (user messages) the exact `conversation_context` the turn received |
| `cypher_query`, `response_data` | (assistant messages) the executed query and its rows |
| `metadata` | `referenced_files`, `suggestions`, `error`, `regenerated`, `invocations`, `export_document_markdown`, `trace` |

`AiConversation::addMessage($role, $content, $data)` accepts `metadata`, `referenced_files`, `suggestions`, `error`, `regenerated`, `response_data`, `context_used`, `cypher_query`, `execution_time_ms`, `confidence_score`.

---

## Usage

The single way to run a chat turn programmatically is `AskQuestionUseCase`. Pass the **same** conversation for follow-ups; nothing else is needed:

```php
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;

$conversation = AiConversation::create(['user_id' => auth()->id()]);
$useCase = app(AskQuestionUseCase::class);

foreach (['Show me all customers in California', 'How many of those placed orders last month?'] as $question) {
    $result = $useCase->execute(new AskQuestionInput(
        question: $question,
        conversation: $conversation,
        style: 'friendly',
        user: auth()->user(),
    ));
}

echo $result->answer; // also ->data, ->suggestions, ->sources, ->cypher, ->metadata, ->failed()
```

**AskQuestionInput:** `question`, `conversation`, `style`, `user` (required unless `allowAnonymous: true`), `allowAnonymous`, `pageContext` (optional `PageContext`), `observer` (optional `PipelineObserverInterface`, observation only), `pipelineOptions` (allowlisted: `store_examples`).

**ChatTurnResult:** `answer`, `data`, `suggestions`, `sources`, `cypher`, `assistantMessage`, `error`, `degraded`, `metadata`.

---

## Configuration

```php
// config/ai.php
'chat' => [
    'max_history_messages' => 10,   // recent exchanges in the conversation context
    'history_answer_chars' => 800,  // assistant answer truncation in recent_exchanges
],
'semantic_context' => [
    'qu_entity_score' => 0.8,
    'conversation_focus_score' => 0.55,
    'anchor_entity_score' => 0.8,
],
```

Both prompt sections are registered by default in `ai.query_generator_sections` / `ai.response_generator_sections`; to change a priority, extend the section and override `$priority`.

---

## Testing

| Component | Tests |
|-----------|-------|
| ConversationContextManager (focus, preserve-on-empty, cap) | `tests/Unit/Services/Context/ConversationContextManagerTest.php`, `AugmentationAnchorSnapshotTest.php` |
| EntityExtractor | `tests/Unit/Services/Context/EntityExtractorTest.php` |
| FilenameExtractor | `tests/Unit/Services/Context/FilenameExtractorTest.php` |
| Focus / anchor in selection | `tests/Unit/Services/ContextRetrieverAnchorSeedTest.php` |
| QU conversation block | `tests/Unit/Services/QueryUnderstandingConversationBlockTest.php` |
| Prompt sections | `tests/Unit/Services/PromptSections/ConversationContextSectionTest.php`, `tests/Unit/Services/ResponseSections/ResponseConversationContextSectionTest.php` |
| Chat turn | `tests/Unit/Services/Chat/UseCases/AskQuestionUseCaseTest.php`, `AskQuestionUseCaseTraceTest.php` |
| Integration (sqlite) | `tests/Integration/ConversationContextIntegrationTest.php` |

---

## Troubleshooting

### A follow-up is not treated as one

1. Check the snapshot actually holds the previous result: `$conversation->context_snapshot['last_cypher_query']` / `last_result_sample`. A turn that returned no rows does not overwrite them, but a turn that never recorded any leaves nothing to refer to.
2. Check what QU judged: the turn trace (assistant message `metadata.trace`, `understanding` stage) records `follow_up_reference`, `entity_types` and `export_format`. The follow-up judgment is QU's alone.
3. If QU failed (provider error), its judgments are neutral; the response metadata then carries `export_intent_unavailable: 'qu_failed'`.

### Context lost between requests

Make sure the same `AiConversation` is passed on every turn and the migrations ran. `updateContextSnapshot()` merges keys; it never replaces the snapshot.

### Prompt does not include the context

Verify `ConversationContextSection` is in `ai.query_generator_sections` and that the context has at least one of `focused_entity`, `recent_exchanges`, `last_cypher_query`, `last_result_sample`, `last_referenced_files`.

---

## Related Documentation

- [Chat Components](/docs/{{version}}/chat/chat-ui) - Chat UI components
- [Chat Pipeline](/docs/{{version}}/chat/module-pipeline) - Chat processing pipeline
- [Custom Prompt Sections](/docs/{{version}}/extending/prompt-sections) - Creating custom sections
- [Entity Configuration](/docs/{{version}}/configuration/entities) - Configuring entities
