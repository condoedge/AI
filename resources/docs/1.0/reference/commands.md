# Artisan Commands

Complete reference for all AI system Artisan commands.

---

## Discovery Commands

### ai:discover

Generate entity configurations from Nodeable models.

```bash
php artisan ai:discover [options]
```

| Option | Description |
|--------|-------------|
| `--dry-run` | Preview without writing files |
| `--model=` | Discover specific model only |
| `--force` | Overwrite existing configuration |
| `-v` | Verbose output |

**Examples:**

```bash
# Discover all models
php artisan ai:discover

# Preview what would be generated
php artisan ai:discover --dry-run

# Discover specific model
php artisan ai:discover --model="App\Models\Customer"

# Force overwrite
php artisan ai:discover --force

# Verbose output
php artisan ai:discover -v
```

**Output file:** `config/entities.php`

---

## Ingestion Commands

### ai:ingest

Bulk ingest entities into Neo4j and Qdrant.

```bash
php artisan ai:ingest [options]
```

| Option | Description |
|--------|-------------|
| `--model=` | Ingest specific model only |
| `--chunk=` | Batch size (default: 100) |
| `--dry-run` | Preview without ingesting |
| `--force` | Force re-ingest existing data |
| `-v` | Verbose output |

**Examples:**

```bash
# Ingest all entities
php artisan ai:ingest

# Ingest specific model
php artisan ai:ingest --model="App\Models\Customer"

# Custom batch size
php artisan ai:ingest --chunk=500

# Preview
php artisan ai:ingest --dry-run

# Force re-ingest
php artisan ai:ingest --force
```

### ai:ingest-eager

Ingest entities with eager-loaded relationships.

```bash
php artisan ai:ingest-eager [options]
```

| Option | Description |
|--------|-------------|
| `--model=` | Ingest specific model |
| `--relations=` | Comma-separated relations to load |
| `--chunk=` | Batch size |

**Examples:**

```bash
# Ingest customers with orders
php artisan ai:ingest-eager --model="App\Models\Customer" --relations="orders,profile"
```

### ai:sync-relationships

Reconcile missing relationships in Neo4j.

```bash
php artisan ai:sync-relationships [options]
```

| Option | Description |
|--------|-------------|
| `--model=` | Sync specific model only |
| `--dry-run` | Preview without syncing |

**Examples:**

```bash
# Sync all relationships
php artisan ai:sync-relationships

# Sync specific model
php artisan ai:sync-relationships --model="App\Models\Customer"
```

---

## Indexing Commands

### ai:index-semantic

Build semantic matching indexes.

```bash
php artisan ai:index-semantic [options]
```

| Option | Description |
|--------|-------------|
| `--rebuild` | Rebuild from scratch |
| `--entity=` | Index specific entity |
| `--list` | List indexed items |
| `--stats` | Show index statistics |

**Examples:**

```bash
# Index all entities
php artisan ai:index-semantic

# Rebuild from scratch
php artisan ai:index-semantic --rebuild

# Index specific entity
php artisan ai:index-semantic --entity="App\Models\Customer"

# View statistics
php artisan ai:index-semantic --stats
```

### ai:index-context

Build context selection indexes.

```bash
php artisan ai:index-context [options]
```

| Option | Description |
|--------|-------------|
| `--rebuild` | Rebuild from scratch |
| `--dry-run` | Preview without indexing |
| `--stats` | Show index statistics |

**Examples:**

```bash
# Index context
php artisan ai:index-context

# Rebuild
php artisan ai:index-context --rebuild

# Statistics
php artisan ai:index-context --stats
```

### ai:index-scopes

Index scopes for semantic matching.

```bash
php artisan ai:index-scopes [options]
```

| Option | Description |
|--------|-------------|
| `--rebuild` | Rebuild from scratch |
| `--entity=` | Index specific entity |

---

## Maintenance Commands

### ai:clear

Clear AI system data.

```bash
php artisan ai:clear [options]
```

| Option | Description |
|--------|-------------|
| `--neo4j` | Clear Neo4j data only |
| `--qdrant` | Clear Qdrant data only |
| `--cache` | Clear cache only |
| `--all` | Clear everything |
| `--force` | Skip confirmation |

**Examples:**

```bash
# Clear everything
php artisan ai:clear --all

# Clear Neo4j only
php artisan ai:clear --neo4j

# Clear without confirmation
php artisan ai:clear --all --force
```

### ai:status

Check AI system status.

```bash
php artisan ai:status
```

**Output:**

```
AI System Status
================

Neo4j:     ✓ Connected (bolt://localhost:7687)
Qdrant:    ✓ Connected (localhost:6333)
OpenAI:    ✓ Configured

Entities:  5 configured
Indexed:   5/5 entities
           23 scopes
           156 context items

Collections:
  - customers: 1,250 points
  - orders: 5,432 points
  - semantic_entities: 45 points
  - context_index: 156 points
```

### ai:test

Test AI system functionality.

```bash
php artisan ai:test [question]
```

**Examples:**

```bash
# Interactive test
php artisan ai:test

# Test specific question
php artisan ai:test "How many active customers?"
```

---

## File Processing Commands

### ai:process-files

Process and index file content.

```bash
php artisan ai:process-files [options]
```

| Option | Description |
|--------|-------------|
| `--path=` | Directory to process |
| `--model=` | Process files for model |
| `--reprocess` | Reprocess existing files |
| `--dry-run` | Preview without processing |

**Examples:**

```bash
# Process all files
php artisan ai:process-files

# Process specific directory
php artisan ai:process-files --path=storage/documents

# Reprocess existing
php artisan ai:process-files --reprocess
```

---

## Query Commands

### ai:query

Execute natural language queries.

```bash
php artisan ai:query [question] [options]
```

| Option | Description |
|--------|-------------|
| `--style=` | Response style (minimal, concise, friendly, detailed, technical) |
| `--format=` | Output format (text, json, markdown) |
| `--debug` | Show query details |

**Examples:**

```bash
# Ask a question
php artisan ai:query "How many customers?"

# Specify style
php artisan ai:query "Show top 10 customers" --style=detailed

# Debug mode
php artisan ai:query "Recent orders" --debug
```

---

## Configuration Commands

### ai:config

Display current configuration.

```bash
php artisan ai:config [section]
```

**Examples:**

```bash
# Show all config
php artisan ai:config

# Show specific section
php artisan ai:config llm
php artisan ai:config entities
```

### ai:publish

Publish package assets.

```bash
php artisan ai:publish [options]
```

| Option | Description |
|--------|-------------|
| `--config` | Publish configuration |
| `--entities` | Publish entities template |
| `--views` | Publish views |
| `--all` | Publish everything |

---

## Common Workflows

### Initial Setup

```bash
# 1. Publish configuration
php artisan vendor:publish --tag=ai-config

# 2. Discover entities
php artisan ai:discover

# 3. Ingest existing data
php artisan ai:ingest

# 4. Build indexes
php artisan ai:index-semantic
php artisan ai:index-context

# 5. Verify
php artisan ai:status
php artisan ai:test "How many records?"
```

### After Model Changes

```bash
# Re-discover configuration
php artisan ai:discover

# Rebuild indexes
php artisan ai:index-semantic --rebuild
php artisan ai:index-context --rebuild
```

### Reset Everything

```bash
# Clear all data
php artisan ai:clear --all --force

# Re-ingest
php artisan ai:ingest

# Rebuild indexes
php artisan ai:index-semantic --rebuild
php artisan ai:index-context --rebuild
```

### Deployment Pipeline

```bash
# In deployment script
php artisan ai:discover --force
php artisan ai:index-semantic --rebuild
php artisan ai:index-context --rebuild
php artisan ai:status
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

    // Monitor status
    $schedule->command('ai:status')
        ->hourly()
        ->appendOutputTo(storage_path('logs/ai-status.log'));
}
```

---

## Related Documentation

- [Quick Start](/docs/{{version}}/usage/quick-start) - Getting started
- [Data Ingestion](/docs/{{version}}/usage/data-ingestion) - Ingestion details
- [Configuration](/docs/{{version}}/foundations/configuration) - Full config guide
