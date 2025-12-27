# Quick Start Guide

> Get up and running with the AI package in 5 minutes.

---

## Installation

```bash
composer require condoedge/ai
php artisan vendor:publish --tag=ai-config
```

## Configuration

```bash
# .env
AI_PROVIDER=openai
OPENAI_API_KEY=your-key

NEO4J_HOST=localhost
NEO4J_PORT=7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=password

QDRANT_HOST=localhost
QDRANT_PORT=6333
```

---

## Step 1: Make Models AI-Enabled

### Minimum (Zero-Config)

```php
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'bio', 'email'];
}
```

### Recommended

```php
class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'bio', 'email', 'ssn'];

    // What to embed for semantic search
    protected array $embedFields = ['name', 'bio'];

    // Sensitive columns (require special permission)
    protected array $sensibleColumns = ['ssn'];

    // Scopes the AI can use
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
```

---

## Step 2: Ingest Data

```bash
# Run all in sequence (first time)
php artisan ai:discover       # Analyze models
php artisan ai:ingest         # Create Neo4j nodes
php artisan ai:sync-rels      # Create relationships
php artisan ai:index-scopes   # Index Eloquent scopes
php artisan ai:index-semantic # Generate embeddings
```

---

## Step 3: Query

```php
// Simple query
$response = app('ai')->ask('Show me all active people', $user);

// With options
$response = app('ai')->ask('Find volunteers', $user, [
    'entities' => ['Person'],
    'limit' => 10,
]);
```

---

## Model Properties Reference

| Property | Type | Description |
|----------|------|-------------|
| `$embedFields` | `array` | Fields for semantic search embedding |
| `$graphLabel` | `string` | Neo4j node label (defaults to class name) |
| `$sensibleColumns` | `array` | Columns requiring permission |
| `$nodeableAliases` | `array` | Alternative names ("person", "people") |
| `$graphRelationships` | `array` | Explicit relationship definitions |

---

## Access Control

The system automatically controls what users can see:

| User Type | Can See |
|-----------|---------|
| Guest | Total counts only |
| Team Member | Team counts |
| Has READ permission | Filtered counts, details |
| Has sensibleColumns permission | Sensitive data (SSN, etc.) |

---

## Commands Reference

| Command | Purpose |
|---------|---------|
| `ai:discover` | Analyze models, generate config cache |
| `ai:ingest` | Create Neo4j nodes |
| `ai:sync-rels` | Create Neo4j relationships |
| `ai:index-scopes` | Convert scopes to Cypher patterns |
| `ai:index-semantic` | Generate Qdrant embeddings |

---

## Next Steps

- Read [Architecture Documentation](./architecture.md) for deep dive
- See `config/ai.php` for all options
- Check `config/entities.php` for entity overrides
