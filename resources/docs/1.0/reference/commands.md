# Artisan Commands

Complete reference for all AI system Artisan commands.

---

## Discovery & Setup Commands

### ai:discover

Discover Nodeable entities and generate `config/entities.php` configuration. This is the only way to generate entity configuration - there is no runtime auto-discovery.

```bash
php artisan ai:discover [options]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--model=` | Specific model class to discover | All models |
| `--force` | Overwrite existing configuration | false |
| `--dry-run` | Show what would be generated without writing | false |

**Examples:**

```bash
# Discover all Nodeable models
php artisan ai:discover

# Preview what would be generated
php artisan ai:discover --dry-run

# Discover specific model
php artisan ai:discover --model="App\Models\Customer"

# Force overwrite existing config
php artisan ai:discover --force
```

**Output file:** `config/entities.php`

---

### ai:setup

Run the full AI pipeline setup in one command. This orchestrates validation, ingestion, relationship sync, and all indexing steps in the correct order.

```bash
php artisan ai:setup [options]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--discover` | Also run `ai:discover` before ingestion (overwrites existing config) | false |
| `--fresh` | Clear all stores before ingestion | false |
| `--skip-ingest` | Skip data ingestion (useful for re-indexing only) | false |
| `--dry-run` | Show what would be executed without running | false |

**Steps executed (in order):**

1. `ai:validate-config` — checks configuration and connections
2. `ai:ingest` — loads entities into the graph (Neo4j)
3. `ai:sync-relationships` — creates relationship edges
4. `ai:index-context` — builds semantic context index
5. `ai:index-semantic` — indexes entities for detection
6. `ai:index-scopes` — indexes scope examples

If any step fails, the command stops and shows how to resume.

**Examples:**

```bash
# Full setup
php artisan ai:setup

# Preview steps without executing
php artisan ai:setup --dry-run

# Fresh ingestion (clear stores first)
php artisan ai:setup --fresh

# Re-index only (skip data ingestion)
php artisan ai:setup --skip-ingest

# Include discovery step
php artisan ai:setup --discover
```

---

### ai:diagnose

Diagnose AI package configuration and connectivity. Checks configuration files, Neo4j connectivity, Qdrant connectivity, LLM API, and embedding provider.

```bash
php artisan ai:diagnose
```

This command has no options. It performs the following checks:

- Configuration files (entities.php, ai.php)
- Neo4j connection and schema
- Qdrant connection and collections
- LLM API key configuration
- Embedding provider configuration

**Example output:**

```
AI Package Diagnostic Report
==================================================

Configuration:
  + entities.php exists (5 entities configured)
  + ai.php configured (default LLM: openai)

Neo4j:
  + URI: bolt://localhost:7687
  + Connected (5 labels found)

Qdrant:
  + Host: localhost:6333
  + Connected (3 collections found)

LLM API:
  + Provider: openai
  + API key configured (sk-proj-...)

Embedding Provider:
  + Provider: openai, Model: text-embedding-3-small, Dimensions: 1536
  + API key configured (sk-proj-...)

==================================================
Summary: 8 passed, 0 failed, 0 warnings

All checks passed! AI package is ready to use.
```

---

### ai:config:validate

Validate entity configuration structure. Helps identify configuration issues before running the AI system.

```bash
php artisan ai:config:validate
```

This command has no options. It validates:

- Required `graph` configuration with label
- Properties
- Class existence

There is no `vector` section to validate: an entity config has `graph`, `security`, `metadata` and optional `auto_sync`.

**Example:**

```bash
php artisan ai:config:validate
```

---

## Data Ingestion Commands

### ai:ingest

Bulk ingest all Nodeable entities into the graph (Neo4j): each node and its relationships. Records are not embedded and get no Qdrant collection; only `--docs` writes to Qdrant (`documentation_chunks`). See [What Is Stored Where](/docs/{{version}}/usage/data-ingestion#what-is-stored-where).

> **Note:** This command automatically uses `BatchDataIngestionService` which groups entities by label and writes them via Cypher `UNWIND` operations, reducing thousands of individual queries to a handful of batched writes.

```bash
php artisan ai:ingest [options]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--model=` | Specific model class to ingest (e.g., `App\Models\Customer`) | All models |
| `--fresh` | Clear the graph before ingesting (vector collections are not touched) | false |
| `--chunk=` | Batch size for processing | 100 |
| `--dry-run` | Show what would be ingested without actually ingesting | false |
| `--docs` | Index physical documentation files from configured paths | false |

> {info} `--fresh` clears the **graph** only. The Qdrant collections (file chunks and their document maps, context / scope / question indexes) are not touched; each has its own writer.

**Examples:**

```bash
# Ingest all entities
php artisan ai:ingest

# Ingest specific model
php artisan ai:ingest --model="App\Models\Customer"

# Custom batch size
php artisan ai:ingest --chunk=500

# Preview what would be ingested
php artisan ai:ingest --dry-run

# Fresh ingest (clear stores first)
php artisan ai:ingest --fresh

# Index physical documentation files
php artisan ai:ingest --docs
```

---

### ai:documents:map

Build or complete the [document map](/docs/{{version}}/advanced/document-map) of ingested documents: a verified plain-language gist per chunk and, for a document divided into parallel parts, which parts govern this application's users. It writes only the `map` key of the chunks' vector payload.

```bash
php artisan ai:documents:map [options]
```

| Option | Description |
|--------|-------------|
| `--backfill` | Every file with something left to do |
| `--file=` | One file id |
| `--collection=` | Chunk collection (default: the physical docs collection; the uploads collection too when `ai.file_context.document_map.uploads` is on) |
| `--retry-failed` | Attempt again the chunks recorded as failed |
| `--rescope` | Judge the parts of divided documents again (no gist is rebuilt) |
| `--force` | Rebuild fresh entries too |
| `--dry-run` | List files and their state, no model call |

**Examples:**

```bash
# List what a backfill would build
php artisan ai:documents:map --backfill --dry-run

# Build what is missing (a chunk is attempted once per text)
php artisan ai:documents:map --backfill

# Rebuild one document
php artisan ai:documents:map --file=physical:docs/guide.md --force
```

With a non-`sync` queue the map is also built after each ingest of a physical document (`BuildDocumentMapJob`, in 40-second slices).

---

### ai:sync-relationships

Synchronize missing relationships in Neo4j. Reconciles relationships that couldn't be created during bulk ingestion because target nodes didn't exist yet.

> **Note:** This command automatically uses batch `UNWIND` operations, grouping relationships by `(fromLabel, toLabel, type)` and writing each group in a single Cypher query for significantly faster execution.

```bash
php artisan ai:sync-relationships [options]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--model=` | Specific model class to sync (e.g., `App\Models\User`) | All models |
| `--chunk=` | Batch size for processing | 100 |
| `--dry-run` | Show what would be synced without actually syncing | false |

**Examples:**

```bash
# Sync all relationships
php artisan ai:sync-relationships

# Sync specific model
php artisan ai:sync-relationships --model="App\Models\User"

# Preview without syncing
php artisan ai:sync-relationships --dry-run

# Custom batch size
php artisan ai:sync-relationships --chunk=200
```

---

### ai:process-files

Batch process files for semantic search. Extracts text content, chunks it, generates embeddings, and stores in Qdrant.

```bash
php artisan ai:process-files [options]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--model=` | File model class to process | `Condoedge\File\Models\File` |
| `--force` | Reprocess all files, even if already processed | false |
| `--chunk=` | Batch size for processing | 50 |
| `--types=` | Comma-separated list of file extensions to process (e.g., `pdf,docx,txt`) | All supported |
| `--dry-run` | Show what would be processed without actually processing | false |

**Examples:**

```bash
# Process all unprocessed files
php artisan ai:process-files

# Process specific file model
php artisan ai:process-files --model="App\Models\Document"

# Reprocess all files
php artisan ai:process-files --force

# Process only specific file types
php artisan ai:process-files --types=pdf,docx,txt

# Preview without processing
php artisan ai:process-files --dry-run

# Custom batch size
php artisan ai:process-files --chunk=25
```

---

## Indexing Commands

### ai:index-semantic

Build the vector store index for semantic entity matching. Embeds entity names, aliases and descriptions into the `semantic_entities` collection to enable fuzzy/semantic matching of entity labels.

```bash
php artisan ai:index-semantic [options]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--rebuild` | Rebuild the index (deletes existing) | false |
| `--entities` | Index entities (the only index; kept for compatibility) | false |
| `--check` | Check index status | false |

**Examples:**

```bash
# Index entities
php artisan ai:index-semantic

# Rebuild the index from scratch
php artisan ai:index-semantic --rebuild

# Same as the default (entities are the only index)
php artisan ai:index-semantic --entities

# Check index status
php artisan ai:index-semantic --check
```

**Collection created:**

- `semantic_entities`: Entity names, aliases, descriptions

Scopes are indexed by `ai:index-context` / `ai:index-scopes` (`scope_examples`), not by this command.

---

### ai:index-scopes

Index scope examples and concepts for semantic matching. Creates vector embeddings for all scope examples, enabling semantic matching when users ask questions.

```bash
php artisan ai:index-scopes [options]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--force` | Force re-indexing even if collection exists | false |

**Examples:**

```bash
# Index all scopes
php artisan ai:index-scopes

# Force re-indexing
php artisan ai:index-scopes --force
```

---

### ai:index-context

Index all context for semantic matching. Creates vector embeddings for entities, relationships, properties, and scopes to enable semantic context selection and reduce token usage.

```bash
php artisan ai:index-context [options]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--scopes-only` | Only index scopes | false |
| `--all` | Index all context (entities, relationships, scopes) | true |
| `--force` | Force re-indexing even if already indexed | false |

**Examples:**

```bash
# Index all context
php artisan ai:index-context

# Index scopes only
php artisan ai:index-context --scopes-only

# Force re-indexing
php artisan ai:index-context --force
```

---

## Common Workflows

### Initial Setup

```bash
# 1. Publish configuration
php artisan vendor:publish --tag=ai-config

# 2. Diagnose configuration
php artisan ai:diagnose

# 3. Discover entities
php artisan ai:discover

# 4. Run full pipeline (validate, ingest, sync, index)
php artisan ai:setup
```

Or run each step manually:

```bash
php artisan ai:config:validate
php artisan ai:ingest
php artisan ai:sync-relationships
php artisan ai:index-context
php artisan ai:index-semantic
php artisan ai:index-scopes
```

### After Model Changes

```bash
# Re-discover configuration
php artisan ai:discover --force

# Re-run setup (skip ingestion if data hasn't changed)
php artisan ai:setup --skip-ingest

# Or rebuild everything
php artisan ai:setup --fresh
```

### Fresh Re-ingestion

```bash
# Full fresh re-ingestion with ai:setup
php artisan ai:setup --fresh

# Or run manually:
php artisan ai:ingest --fresh
php artisan ai:sync-relationships
php artisan ai:index-semantic --rebuild
php artisan ai:index-context --force
```

### Deployment Pipeline

```bash
# In deployment script
php artisan ai:setup --discover --fresh
php artisan ai:diagnose
```

---

## Scheduling

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Daily index rebuild
    $schedule->command('ai:index-semantic --rebuild')
        ->daily()
        ->at('02:00');

    // Weekly relationship sync
    $schedule->command('ai:sync-relationships')
        ->weekly();
}
```

---

## Related Documentation

- [Quick Start](/docs/{{version}}/usage/quick-start) - Getting started
- [Data Ingestion](/docs/{{version}}/usage/data-ingestion) - Ingestion details
- [Configuration](/docs/{{version}}/foundations/configuration) - Full config guide
