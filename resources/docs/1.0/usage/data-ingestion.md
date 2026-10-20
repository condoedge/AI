# Data Ingestion API

Complete guide to ingesting entities into the graph (Neo4j), covering auto-sync, bulk ingestion, and manual operations.

---

## Overview

The AI system synchronizes your entities to the graph store:
- **Neo4j** - Graph database holding one node per record plus its relationships

**Two ingestion modes:**

1. **Auto-Sync (Recommended)** - Automatic synchronization via model events
2. **Manual Operations** - Explicit control when auto-sync is disabled

---

## What Is Stored Where

Records (Eloquent models implementing `Nodeable`) are written to the graph (Neo4j) only: `AI::ingest()`, `AI::sync()`, `AI::remove()`, `AI::ingestBatch()`, auto-sync and `php artisan ai:ingest` write the node and its relationships. Records are not embedded and there is no per-entity vector collection. Qdrant is still required: it holds the files and the semantic indexes, in exactly six collections:

| Collection | Holds | Written by | Read by |
|---|---|---|---|
| `file_chunks` | chunks of files users upload | file processing (`ai:process-files`, upload hooks) | file search / file context |
| `documentation_chunks` | chunks of the host's own documents, each with its document-map gist (`map` payload) | `ai:ingest --docs`, `ai:documents:map` | file search / file context, the document map |
| `context_index` | entity descriptions, aliases, example questions, relationships, properties, scopes | `ai:index-context` | choosing which entities and context a question needs |
| `scope_examples` | scope concepts and example phrasings | `ai:index-context` / `ai:index-scopes` | matching a question to a configured scope |
| `questions` | proven question→Cypher pairs | the pipeline, after a successful answer | few-shot examples for query generation |
| `semantic_entities` | entity names, aliases, descriptions | `ai:index-semantic` | fallback entity-label matching |

The document map has its own page: [Document Map](/docs/{{version}}/advanced/document-map).

> {info} `php artisan ai:ingest --fresh` clears the **graph** only and re-ingests it. The six Qdrant collections above are not touched: each has its own writer (file processing, `ai:index-context`, `ai:index-semantic`, `ai:index-scopes`).

---

## Auto-Sync (Built-in)

**The recommended approach.** When you use `HasNodeableConfig` trait, entities automatically sync to the graph (Neo4j) on model events.

### How It Works

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;  // ← This enables auto-sync

    protected $fillable = ['name', 'email', 'company'];
}
```

**Automatic synchronization:**

```php
// Create - automatically ingested
$customer = Customer::create([
    'name' => 'Acme Corp',
    'email' => 'contact@acme.com',
]);
// ✓ Node created in Neo4j
// ✓ Relationships created automatically

// Update - automatically synced
$customer->name = 'Acme Corporation';
$customer->save();
// ✓ Node updated in Neo4j

// Delete - automatically removed
$customer->delete();
// ✓ Node deleted from Neo4j
```

### Configuration

Auto-sync is enabled by default. Configure in `config/ai.php`:

```php
'auto_sync' => [
    'enabled' => env('AI_AUTO_SYNC_ENABLED', true),  // Global toggle
    'queue' => env('AI_AUTO_SYNC_QUEUE', false),      // Queue operations
    'queue_connection' => null,                       // Queue connection
],
```

### Per-Model Control

Override auto-sync for specific models:

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::for(static::class)
            ->label('Customer')
            ->properties('id', 'name', 'email')
            ->autoSync(false);  // Disable for this model
    }
}
```

### Temporarily Disable Auto-Sync

```php
// Disable for specific operation
Customer::withoutEvents(function () {
    Customer::create(['name' => 'Test']);  // Not synced
});

// Disable globally in config
config(['ai.auto_sync.enabled' => false]);
```

---

## Bulk Ingestion Command

For initial setup or migrating existing data, use the bulk ingestion command:

```bash
# Ingest all Nodeable entities
php artisan ai:ingest

# Ingest specific model
php artisan ai:ingest --model="App\Models\Customer"

# Custom batch size (default: 100)
php artisan ai:ingest --chunk=500

# Preview without ingesting
php artisan ai:ingest --dry-run
```

**What it does:**
1. Finds all Nodeable models
2. Processes existing entities in batches
3. Writes each node and its relationships to Neo4j (no embedding, no Qdrant write)
4. Shows progress bar with success/failure counts

**Example output:**
```
🚀 Bulk Entity Ingestion

Found 2 Nodeable model(s)

Processing: App\Models\Customer
  Found 500 entities
  [████████████████] 500/500 (100%) Ingested: 500, Failed: 0
  ✓ Ingested: 500

Processing: App\Models\Order
  Found 1200 entities
  [████████████████] 1200/1200 (100%) Ingested: 1198, Failed: 2
  ⚠ Ingested: 1198, Failed: 2


✓ Successfully ingested: 1698
✗ Failed: 2

Ingestion complete!
```

**When to use:**
- ✅ Initial setup (you have existing data)
- ✅ Rebuilding the graph from scratch
- ✅ Migrating to AI package
- ❌ NOT for ongoing syncing (use auto-sync instead)

**Important:** After bulk ingestion, some relationships may be skipped if target nodes don't exist yet (e.g., Users ingested before Persons). Run `php artisan ai:sync-relationships` to reconcile missing relationships.

---

## Relationship Synchronization

### The Problem

When entities reference other entities through foreign keys, relationships can only be created in Neo4j when **both nodes exist**. If you ingest entities in the wrong order, relationships will be skipped:

```php
// Scenario: Users have a person_id foreign key referencing Persons

// 1. Users ingested first
User::create(['name' => 'john_doe', 'person_id' => 123]);
// ✓ User node created in Neo4j
// ✗ Relationship to Person:123 SKIPPED (Person doesn't exist yet)

// 2. Persons ingested later
Person::create(['id' => 123, 'name' => 'John Doe']);
// ✓ Person node created in Neo4j
// ✗ Relationship from User to Person STILL MISSING
```

### The Solution: Sync Relationships Command

After bulk ingestion, reconcile missing relationships:

```bash
# Sync all missing relationships
php artisan ai:sync-relationships

# Sync specific model
php artisan ai:sync-relationships --model="App\Models\User"

# Custom batch size
php artisan ai:sync-relationships --chunk=500

# Preview without syncing
php artisan ai:sync-relationships --dry-run
```

**What it does:**
1. Finds all Nodeable entities in your database
2. Checks configured relationships against Neo4j
3. Verifies both source and target nodes exist
4. Creates missing relationships (skips existing ones to avoid duplicates)
5. Provides detailed summary

**Example output:**
```
🔗 Relationship Synchronization

Found 3 Nodeable model(s)

Processing: App\Models\User
  Found 250 entities
  [████████████████] 250/250 (100%) Created: 45
  ✓ Created: 45, Skipped: 205

Processing: App\Models\Order
  Found 800 entities
  [████████████████] 800/800 (100%) Created: 12
  ✓ Created: 12, Skipped: 788

✓ Synchronization Complete
  Created: 57
  Skipped (already exist): 993
```

### Recommended Workflow

**For bulk ingestion with relationships:**

```bash
# 1. Generate configurations
php artisan ai:discover

# 2. Bulk ingest entities (order doesn't matter!)
php artisan ai:ingest

# Output shows skipped relationships:
#   Relationships created with some skipped
#     created: 450
#     skipped: 50  ← Target nodes don't exist yet
#     message: Run "php artisan ai:sync-relationships"

# 3. Sync missing relationships
php artisan ai:sync-relationships

# Output:
#   Created: 50
#   Skipped: 0 (no duplicates!)
```

### How It Works

**During Initial Ingestion:**
- Creates relationships only when target node exists
- Logs detailed information about skipped relationships
- Continues processing (doesn't fail entire ingestion)

**During Relationship Sync:**
- Checks if source node exists (skips if not)
- Checks if target node exists (skips if not)
- Checks if relationship already exists (avoids duplicates)
- Creates missing relationships with proper properties
- Fully idempotent (safe to run multiple times)

**Key Features:**
- ✅ **Order-independent** - Ingest entities in any order
- ✅ **No duplicates** - Checks before creating relationships
- ✅ **Idempotent** - Safe to run multiple times
- ✅ **Efficient** - Batch processing with progress tracking
- ✅ **Detailed logging** - Debug/info/error logs for troubleshooting

### Programmatic Usage

You can also sync relationships programmatically:

```php
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use App\Models\User;

$ingestionService = app(DataIngestionServiceInterface::class);

// Get all users
$users = User::all();

// Sync their relationships
$result = $ingestionService->syncRelationships($users->all());

// Check results
echo "Created: {$result['relationships_created']}\n";
echo "Skipped: {$result['relationships_skipped']}\n";
echo "Failed: {$result['relationships_failed']}\n";

if (!empty($result['errors'])) {
    foreach ($result['errors'] as $entityId => $errors) {
        echo "Entity {$entityId}: " . implode(', ', $errors) . "\n";
    }
}
```

---

## Manual Operations

When auto-sync is disabled, use manual operations:

### Single Entity Ingestion

```php
use Condoedge\Ai\Facades\AI;

$customer = Customer::find(1);
$status = AI::ingest($customer);
```

**Status Response:**
```php
[
    'graph_stored' => true,          // Stored in Neo4j
    'relationships_created' => 2,    // Relationships created
    'errors' => []                   // Always empty: a failed graph write throws
]
```

### Batch Ingestion

More efficient for multiple entities:

```php
$customers = Customer::limit(100)->get();
$result = AI::ingestBatch($customers->toArray());
```

**Summary Response:**
```php
[
    'total' => 100,
    'succeeded' => 99,
    'partially_succeeded' => 0,  // Always 0 (records go to the graph only)
    'failed' => 1,
    'errors' => [
        45 => ['Graph: Connection timeout']
    ]
]
```

### Sync Operation

Update if exists, create if not:

```php
$customer->name = 'Updated Name';
$customer->save();

$status = AI::sync($customer);
```

**Response:**
```php
[
    'action' => 'updated',  // or 'created'
    'graph_synced' => true,
    'errors' => []
]
```

### Remove Operation

Delete the node (with its relationships) from the graph:

```php
$success = AI::remove($customer);

if ($success) {
    $customer->delete();  // Safe to delete from database
}
```

---

## Collection Management

Ingesting a record creates no Qdrant collection: records are written to the graph only. The Qdrant collections listed in [What Is Stored Where](#what-is-stored-where) belong to the files and the semantic indexes.

### Automatic Creation

Each collection is created automatically, with the embedding dimensions, the first time something is written to it:

```bash
php artisan ai:index-context     # context_index, scope_examples
php artisan ai:index-semantic    # semantic_entities
php artisan ai:process-files     # file_chunks
php artisan ai:ingest --docs     # documentation_chunks
```

### Manual Collection Creation

```php
use Condoedge\Ai\Contracts\VectorStoreInterface;

$vectorStore = app(VectorStoreInterface::class);

$vectorStore->createCollection(
    name: 'custom_collection',
    vectorSize: 1536,  // Must match embedding dimensions
    distance: 'Cosine'  // or 'Dot', 'Euclid'
);
```

### Check Collection Exists

```php
if ($vectorStore->collectionExists('file_chunks')) {
    echo "Collection exists!";
}
```

---

## Entity Configuration

Entities must define graph configuration (`security`, `metadata` and `auto_sync` sections are optional; there is no `vector` section):

### Using config/entities.php

```php
// config/entities.php (generated by php artisan ai:discover)
return [
    'App\Models\Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => ['id', 'name', 'email', 'status'],
            'relationships' => [
                ['type' => 'PLACED_ORDER', 'target_label' => 'Order']
            ]
        ]
    ]
];
```

### Using nodeableConfig() Method

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::for(static::class)
            ->label('Customer')
            ->properties('id', 'name', 'email', 'status')
            ->relationship('orders', 'Order', 'PLACED_ORDER');
    }
}
```

---

## Error Handling

### Graph Failures

Records are written to one store (the graph), so there is no partial success. If the graph write fails, `ingest()` throws a `DataConsistencyException`:

```php
try {
    $status = AI::ingest($customer);
} catch (\Condoedge\Ai\Exceptions\DataConsistencyException $e) {
    // The graph write failed; nothing was stored, so nothing is rolled back
    Log::error('Data consistency error', [
        'entity_id' => $e->getContext()['entity_id'],
        'graph_success' => $e->getContext()['graph_success'],
        'rolled_back' => $e->getContext()['rolled_back'],
    ]);
}
```

`remove()` throws the same exception when the delete fails. `sync()` does not throw: it returns `'action' => 'unknown'`, `'graph_synced' => false` and the message in `errors`. Auto-sync catches the exception and logs it unless `ai.auto_sync.fail_silently` is `false`.

### Batch Error Handling

```php
$result = AI::ingestBatch($customers->toArray());

if ($result['failed'] > 0) {
    foreach ($result['errors'] as $entityId => $errors) {
        Log::warning("Entity {$entityId} failed", ['errors' => $errors]);
    }
}
```

---

## Performance Optimization

### Queuing Auto-Sync

For high-throughput applications, queue sync operations:

```php
// config/ai.php
'auto_sync' => [
    'enabled' => true,
    'queue' => true,  // Queue sync operations
    'queue_connection' => 'redis',
],
```

Now model events dispatch queued jobs:

```php
Customer::create(['name' => 'Acme']);
// → Dispatches job to queue
// → Returns immediately
// → Job processes in background
```

### Batch Processing

Use chunking for large datasets:

```php
Customer::chunk(100, function ($customers) {
    AI::ingestBatch($customers->toArray());
});
```

### Disable Sync During Migrations

```php
// database/seeders/CustomerSeeder.php
public function run()
{
    // Disable auto-sync for seeding
    config(['ai.auto_sync.enabled' => false]);

    Customer::factory()->count(10000)->create();

    // Bulk ingest after seeding
    Artisan::call('ai:ingest', ['--model' => 'App\\Models\\Customer']);
}
```

### Batch Performance

When running `ai:ingest` and `ai:sync-relationships`, the system automatically uses `BatchDataIngestionService` which optimizes Neo4j writes using Cypher `UNWIND` operations.

#### How it works

**Node creation:** Entities are grouped by label. Instead of one MERGE query per entity, a single `UNWIND $batch AS row MERGE (n:Label {id: row.id}) SET n = row` processes the entire batch.

**Relationship creation:** Relationships are grouped by `(fromLabel, toLabel, type)`. One `UNWIND` query per group creates all relationships at once. No existence checks needed — `MATCH` skips missing nodes, `MERGE` is idempotent.

#### Performance

| Entities | Before | After |
|----------|--------|-------|
| 1,000 | ~20,000 queries (~20 min) | ~40 queries (~30 sec) |
| 10,000 | ~200,000 queries (~5 hrs) | ~400 queries (~5 min) |
| 100,000 | ~2M queries (~50 hrs) | ~4,000 queries (~45 min) |

#### Single entity operations

Auto-sync (model observers) continues using the standard `DataIngestionService` for per-entity operations. This is optimal for real-time sync where you're updating one entity at a time.

---

## Direct Service Usage

For maximum control, inject `DataIngestionServiceInterface`:

```php
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;

class CustomerService
{
    public function __construct(
        private DataIngestionServiceInterface $ingestion
    ) {}

    public function importCustomers(array $data): array
    {
        $results = ['succeeded' => 0, 'failed' => 0];

        foreach ($data as $customerData) {
            try {
                $customer = Customer::create($customerData);
                $this->ingestion->ingest($customer);
                $results['succeeded']++;
            } catch (\Exception $e) {
                $results['failed']++;
                Log::error('Import failed', ['data' => $customerData, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }
}
```

---

## Testing

Mock ingestion in tests:

```php
use Condoedge\Ai\Facades\AI;
use Illuminate\Support\Facades\Facade;

class CustomerTest extends TestCase
{
    public function test_customer_creation()
    {
        // Mock AI facade
        AI::shouldReceive('ingest')
            ->once()
            ->andReturn([
                'graph_stored' => true,
                'relationships_created' => 0,
                'errors' => []
            ]);

        $customer = Customer::create(['name' => 'Test']);

        $this->assertDatabaseHas('customers', ['name' => 'Test']);
    }
}
```

Or disable auto-sync in tests:

```php
protected function setUp(): void
{
    parent::setUp();

    // Disable auto-sync for all tests
    config(['ai.auto_sync.enabled' => false]);
}
```

---

## File Processing

### Overview

The AI system can process files (PDFs, DOCX, TXT, etc.) for semantic search:
- **Neo4j** - File metadata and relationships (via auto-sync)
- **Qdrant** - File content chunks with embeddings (via FileProcessor)

Files are automatically processed when created/updated if `FileProcessingPlugin` is enabled.

### Automatic File Processing

When a File model uses the plugin, files are automatically processed on create/update:

```php
use Condoedge\File\Models\File;

// Create file - automatically processed
$file = File::create([
    'name' => 'proposal.pdf',
    'path' => '/storage/files/proposal.pdf',
    'size' => 524288,
]);

// ✓ File metadata stored in Neo4j
// ✓ Content extracted and chunked
// ✓ Chunks embedded and stored in Qdrant
// ✓ Ready for semantic search
```

### Batch File Processing Command

For initial setup or reprocessing many existing files:

```bash
# Process all unprocessed files
php artisan ai:process-files

# Process specific model
php artisan ai:process-files --model="App\Models\Document"

# Reprocess all files (even if already processed)
php artisan ai:process-files --force

# Process only specific file types
php artisan ai:process-files --types=pdf,docx,txt

# Custom batch size
php artisan ai:process-files --chunk=50

# Preview without processing
php artisan ai:process-files --dry-run
```

**What it does:**
1. Finds all files in your database
2. Extracts text content from supported file types
3. Chunks content into smaller pieces (configurable size)
4. Generates embeddings for each chunk
5. Stores chunks in Qdrant with file metadata
6. Updates file's `processed_at` timestamp

**Example output:**
```
📄 Batch File Processing

Supported file types: pdf, docx, txt, md, log

Found 350 file(s) to process

  [████████████████] 350/350 (100%) Processed: 342

✓ Processing Complete
  Processed: 342
  Skipped: 5 (unsupported types)
  Failed: 3

Check logs for details: storage/logs/laravel.log
```

### Supported File Types

**Default supported types:**
- `pdf` - PDF documents
- `docx` - Microsoft Word documents
- `txt` - Plain text files
- `md`, `markdown` - Markdown files
- `log` - Log files

**Configure in `config/ai.php`:**

```php
'file_processing' => [
    'enabled' => env('AI_FILE_PROCESSING_ENABLED', true),
    'collection' => env('AI_FILE_COLLECTION', 'file_chunks'),

    // Queue processing for large files
    'queue' => env('AI_FILE_QUEUE', false),
    'queue_threshold_bytes' => env('AI_FILE_QUEUE_THRESHOLD', 5 * 1024 * 1024), // 5MB
],
```

### File Processing Workflow

**For new applications:**
```bash
# Files auto-process on upload
# No manual intervention needed
```

**For existing file libraries:**
```bash
# 1. Enable file processing
AI_FILE_PROCESSING_ENABLED=true

# 2. Batch process existing files
php artisan ai:process-files

# 3. New files auto-process on upload
```

**Reprocessing after config changes:**
```bash
# Reprocess all files with new settings
php artisan ai:process-files --force
```

### Programmatic File Processing

Process files programmatically:

```php
use Condoedge\Ai\Contracts\FileProcessorInterface;

$processor = app(FileProcessorInterface::class);

// Process single file
$file = File::find(1);
$result = $processor->processFile($file);

if ($result->succeeded()) {
    echo "Chunks created: {$result->chunksCreated}\n";
    echo "Total size: {$result->totalSize} bytes\n";
} else {
    echo "Error: {$result->error}\n";
}

// Reprocess file (removes old chunks)
$result = $processor->reprocessFile($file);

// Remove file chunks
$processor->removeFile($file);

// Check if processed
if ($processor->isProcessed($file)) {
    $stats = $processor->getFileStats($file);
    echo "Chunks: {$stats['chunk_count']}\n";
}

// Check file type support
if ($processor->supportsFileType('pdf')) {
    echo "PDFs are supported\n";
}
```

### Semantic File Search

Once files are processed, search them semantically:

```php
use Condoedge\Ai\Facades\FileSearch;

// Search file contents (the file chunk collections in Qdrant)
$results = FileSearch::searchByContent("machine learning algorithms", [
    'limit' => 10,
    'min_score' => 0.7
]);

foreach ($results as $result) {
    echo "File ID: {$result['file_id']}\n";
    echo "Matching chunks: {$result['chunk_count']}\n";
    echo "Score: {$result['score']}\n";
    echo "\n";
}
```

### Performance Considerations

**Queue large file processing:**

```env
# Queue files larger than 5MB
AI_FILE_QUEUE=true
AI_FILE_QUEUE_THRESHOLD=5242880
AI_FILE_QUEUE_CONNECTION=redis
```

**Adjust chunk size for your content:**

Chunk size is not a config/env setting; pass it as a processing option (`chunk_size`, `overlap`) when processing files in code:

```php
use Condoedge\Ai\Contracts\FileProcessorInterface;

$processor = app(FileProcessorInterface::class);

// Larger chunks for technical documents
$processor->processFile($file, ['chunk_size' => 1500, 'overlap' => 300]);

// Smaller chunks for conversational content
$processor->processFile($file, ['chunk_size' => 500, 'overlap' => 100]);
```

**Disable for specific file types:**

Filter during batch processing:

```bash
# Only process PDFs and DOCX
php artisan ai:process-files --types=pdf,docx
```

---

## Query Storage for RAG

When you ask natural language questions, the system can learn from past queries to provide better context for future questions. This is done by storing question-query pairs in Qdrant.

### Why Store Queries?

The RAG (Retrieval-Augmented Generation) system retrieves similar past questions to help the LLM generate better queries. This is called **few-shot learning**.

**Without stored queries:**
```
User: "Show all customers"
System: No similar examples → LLM guesses based only on schema
```

**With stored queries:**
```
User: "Show all customers"
System: Found similar: "List all teams" → "MATCH (t:Team) RETURN t"
LLM: Uses this example to generate better query
```

### Automatic Storage (Recommended)

After successfully answering a question, you can automatically store it:

```php
// Answer a question
$result = AI::answerQuestion("Show premium customers with orders > 100");

// If query worked well, store it for future learning
if ($result['stats']['success']) {
    AI::storeQuery(
        question: "Show premium customers with orders > 100",
        cypherQuery: $result['cypher'],
        metadata: ['confidence' => 0.95, 'user_verified' => true]
    );
}
```

### Programmatic Usage

```php
use Condoedge\Ai\Facades\AI;

// Store a single query
$result = AI::storeQuery(
    question: "Show all customers",
    cypherQuery: "MATCH (c:Customer) RETURN c LIMIT 10",
    metadata: ['confidence' => 0.9],
    collection: 'questions' // Optional, defaults to 'questions'
);

if ($result['success']) {
    echo "Stored with ID: " . $result['point_id'];
} else {
    echo "Errors: " . implode(', ', $result['errors']);
}
```

### Building a Learning System

Create a feedback loop where successful queries are automatically stored:

```php
class QueryController extends Controller
{
    public function ask(Request $request)
    {
        $question = $request->input('question');

        // Generate and execute query
        $result = AI::answerQuestion($question);

        // If user verifies the answer is correct, store it
        if ($request->input('is_correct')) {
            AI::storeQuery(
                question: $question,
                cypherQuery: $result['cypher'],
                metadata: [
                    'confidence' => $result['metadata']['query']['confidence'] ?? 0.8,
                    'user_verified' => true,
                    'user_id' => auth()->id(),
                    'verified_at' => now()->toIso8601String(),
                ]
            );
        }

        return response()->json($result);
    }
}
```

### Query Collection Structure

Stored queries have this structure in Qdrant:

```php
[
    'id' => 'unique-hash',  // MD5 of question + query
    'vector' => [...],       // Embedding of the question
    'payload' => [
        'question' => 'Show all customers',
        'cypher_query' => 'MATCH (c:Customer) RETURN c',
        'created_at' => '2024-01-15T10:30:00Z',
        // Your custom metadata
        'confidence' => 0.95,
        'category' => 'basic',
    ]
]
```

### Best Practices

**1. Store verified queries only**
```php
// ✓ Good - user confirmed answer is correct
if ($userConfirmed) {
    AI::storeQuery($question, $cypher);
}

// ✗ Bad - storing every query, even failures
AI::storeQuery($question, $cypher);  // Might be wrong!
```

**2. Include confidence scores**
```php
AI::storeQuery(
    question: $question,
    cypherQuery: $cypher,
    metadata: ['confidence' => 0.95]  // ← Helps ranking
);
```

**3. Categorize for better retrieval**
```php
AI::storeQuery(
    question: $question,
    cypherQuery: $cypher,
    metadata: [
        'category' => 'analytics',  // or 'basic', 'advanced'
        'entity_types' => ['Customer', 'Order'],
        'complexity' => 'medium',
    ]
);
```

**4. Seed common queries on deployment**
```bash
# Store essential queries in queries.json
# Run on first deployment
php artisan ai:store-query --import=queries.json
```

### Retrieving Similar Queries

The stored queries are automatically used during RAG retrieval:

```php
// This automatically searches stored queries
$context = AI::retrieveContext("Show premium customers");

// Returns:
[
    'similar_queries' => [
        [
            'question' => 'Show all customers',
            'query' => 'MATCH (c:Customer) RETURN c',
            'score' => 0.89,  // Similarity score
        ],
        [
            'question' => 'List premium users',
            'query' => 'MATCH (u:User {tier: "premium"}) RETURN u',
            'score' => 0.85,
        ],
    ],
    // ... other context
]
```

---

## Summary: Ingestion Workflow

**Development/Testing:**
```bash
# 1. Make model Nodeable
class Customer extends Model implements Nodeable { use HasNodeableConfig; }

# 2. Generate config
php artisan ai:discover

# 3. Auto-sync handles new entities
$customer = Customer::create([...]); // Auto-ingested!
```

**Production Setup (existing data):**
```bash
# 1. Configure entities
php artisan ai:discover

# 2. Bulk ingest existing data (one-time)
php artisan ai:ingest

# 3. Auto-sync handles new/updated entities
# (No manual intervention needed)
```

**Manual Operations (auto-sync disabled):**
```php
// Single
AI::ingest($customer);

// Batch
AI::ingestBatch($customers->toArray());

// Update
AI::sync($customer);

// Delete
AI::remove($customer);
```

---

## Next Steps

- **[Configuration Reference](/docs/{{version}}/foundations/configuration)** - Configure auto-sync, queues, and stores
- **[Advanced Usage](/docs/{{version}}/usage/advanced-usage)** - NodeableConfig API and direct service usage
- **[Testing Guide](/docs/{{version}}/usage/testing)** - Test strategies and mocking
