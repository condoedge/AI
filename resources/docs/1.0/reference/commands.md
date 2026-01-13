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
- Required `vector` configuration with collection name
- Properties and embed fields
- Class existence

**Example:**

```bash
php artisan ai:config:validate
```

---

## Data Ingestion Commands

### ai:ingest

Bulk ingest all Nodeable entities into Neo4j and Qdrant.

```bash
php artisan ai:ingest [options]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--model=` | Specific model class to ingest (e.g., `App\Models\Customer`) | All models |
| `--fresh` | Clear all data from stores before ingesting | false |
| `--chunk=` | Batch size for processing | 100 |
| `--dry-run` | Show what would be ingested without actually ingesting | false |
| `--docs` | Index physical documentation files from configured paths | false |

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

### ai:sync-relationships

Synchronize missing relationships in Neo4j. Reconciles relationships that couldn't be created during bulk ingestion because target nodes didn't exist yet.

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

Build vector store indexes for semantic matching. Creates collections for entities, scopes, and templates with embeddings to enable fuzzy/semantic search.

```bash
php artisan ai:index-semantic [options]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--rebuild` | Rebuild all indexes (deletes existing) | false |
| `--entities` | Index entities only | false |
| `--scopes` | Index scopes only | false |
| `--templates` | Index templates only | false |
| `--check` | Check index status | false |

**Examples:**

```bash
# Index all (entities, scopes, templates)
php artisan ai:index-semantic

# Rebuild all indexes from scratch
php artisan ai:index-semantic --rebuild

# Index entities only
php artisan ai:index-semantic --entities

# Index scopes only
php artisan ai:index-semantic --scopes

# Check index status
php artisan ai:index-semantic --check
```

**Collections created:**

- `semantic_entities`: Entity names, aliases, descriptions
- `semantic_scopes`: Scope names, descriptions, concepts
- `semantic_templates`: Query template descriptions

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

# 4. Validate configuration
php artisan ai:config:validate

# 5. Ingest existing data
php artisan ai:ingest

# 6. Sync relationships (if needed)
php artisan ai:sync-relationships

# 7. Build semantic indexes
php artisan ai:index-semantic
php artisan ai:index-context
```

### After Model Changes

```bash
# Re-discover configuration
php artisan ai:discover --force

# Validate the new configuration
php artisan ai:config:validate

# Rebuild indexes
php artisan ai:index-semantic --rebuild
php artisan ai:index-context --force
```

### Fresh Re-ingestion

```bash
# Re-ingest with fresh stores
php artisan ai:ingest --fresh

# Sync relationships
php artisan ai:sync-relationships

# Rebuild indexes
php artisan ai:index-semantic --rebuild
php artisan ai:index-context --force
```

### Deployment Pipeline

```bash
# In deployment script
php artisan ai:discover --force
php artisan ai:config:validate
php artisan ai:index-semantic --rebuild
php artisan ai:index-context --force
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
