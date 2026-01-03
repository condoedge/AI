# Module 20: CONSOLE_COMMANDS - Documentation Updates

> **Status:** COMPLETE

## Current Documentation Status

The console commands are well-documented in `resources/docs/1.0/reference/commands.md`. However, some clarifications and improvements would be beneficial.

## Recommended Documentation Updates

### 1. Clarify Indexing Command Relationships

**File:** `resources/docs/1.0/reference/commands.md`

Add a section explaining the relationship between the three indexing commands:

```markdown
## Understanding Indexing Commands

The AI package provides three indexing commands that serve related but distinct purposes:

### ai:index-semantic (Recommended)
The most comprehensive indexing command. Creates vector embeddings for:
- Entity names, aliases, and descriptions
- Scope names, descriptions, and concepts
- Query template descriptions

Use this command for initial setup and when you want full semantic matching capabilities.

### ai:index-scopes
A focused command that only indexes scope examples and concepts. Use when:
- You've only updated scope configurations
- You want faster indexing without rebuilding everything

### ai:index-context
Indexes entity context including relationships and properties. Use when:
- You want to reduce token usage through semantic context selection
- You've updated entity or relationship descriptions
```

### 2. Add Command Dependency Diagram

**File:** `resources/docs/1.0/reference/commands.md`

```markdown
## Command Workflow

```
ai:discover
    |
    v
ai:config:validate
    |
    v
ai:ingest -----> ai:sync-relationships (if needed)
    |
    v
ai:index-semantic (or individual indexing commands)
    |
    v
ai:process-files (optional, for file search)
    |
    v
ai:diagnose (verify setup)
```
```

### 3. Document --docs Flag for ai:ingest

**File:** `resources/docs/1.0/reference/commands.md`

The `--docs` flag for `ai:ingest` indexes physical documentation files and should be documented more prominently:

```markdown
## Ingesting Documentation Files

```bash
# Index physical documentation files from configured paths
php artisan ai:ingest --docs

# Preview what files would be indexed
php artisan ai:ingest --docs --dry-run

# Force re-index all documentation files
php artisan ai:ingest --docs --fresh
```

Configure documentation paths in `config/ai.php`:
```php
'file_context' => [
    'physical_paths' => [
        base_path('docs'),
        base_path('README.md'),
    ],
    'physical_collection' => 'documentation_chunks',
],
```
```

### 4. Add Troubleshooting Section

**File:** `resources/docs/1.0/reference/commands.md`

```markdown
## Troubleshooting

### Common Issues

**"No entity configurations found"**
Run `php artisan ai:discover` first to generate `config/entities.php`.

**Neo4j connection failed**
- Verify Neo4j is running
- Check `AI_NEO4J_URI`, `AI_NEO4J_USER`, `AI_NEO4J_PASSWORD` in `.env`
- Run `php artisan ai:diagnose` for detailed diagnostics

**Qdrant connection failed**
- Verify Qdrant is running
- Check `AI_QDRANT_HOST` and `AI_QDRANT_PORT` in `.env`

**Relationship sync shows many failures**
- Ensure target entities are ingested before running `ai:sync-relationships`
- Check that model relationships are properly defined
```

### 5. Document Database Compatibility Note

**File:** `resources/docs/1.0/reference/commands.md`

```markdown
## Database Compatibility

The `ai:process-files` command's dry-run mode uses MySQL-specific SQL functions.
For PostgreSQL or SQLite, the dry-run grouping by file type may not work as expected,
but actual file processing will function correctly across all databases.
```

## Files That Should Reference Commands

Ensure these files link to the commands documentation:

1. `README.md` - Quick start section
2. `resources/docs/1.0/foundations/installing.md` - Post-installation steps
3. `resources/docs/1.0/usage/quick-start.md` - Getting started workflow

## Missing API Documentation

Consider adding PHPDoc improvements to:
- `IndexScopesCommand.php` - Add `@see IndexSemanticCommand` reference
- `IndexContextCommand.php` - Add `@see IndexSemanticCommand` reference
- `ProcessFilesCommand.php` - Document MySQL limitation in dry-run mode
