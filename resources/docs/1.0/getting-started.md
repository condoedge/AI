# Getting Started

This guide walks you through installing and configuring the AI Text-to-Query System in your Laravel application.

---

## Installation

### Step 1: Install via Composer

```bash
composer require ai-system/text-to-query
```

**Requirements:**
- PHP 8.1 or higher
- Laravel 9.x or 10.x
- Composer

---

### Step 2: Register Service Provider

Add the service provider to your `config/app.php`:

```php
'providers' => [
    // ...
    AiSystem\AiServiceProvider::class,
],
```

> **Note:** If you're using Laravel 11+ with auto-discovery, this step is automatic.

---

### Step 3: Publish Configuration Files

Publish the AI system configuration:

```bash
php artisan vendor:publish --tag=ai-config
```

This creates `config/ai.php` with all system settings.

**Optional:** Publish entity configuration template:

```bash
php artisan vendor:publish --tag=ai-entities
```

This creates `config/entities.php` for defining your entity mappings.

---

## Environment Configuration

### Step 4: Set Up Environment Variables

Add these variables to your `.env` file:

#### Neo4j Configuration

```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-secure-password
NEO4J_DATABASE=neo4j
NEO4J_ENABLED=true
```

#### Qdrant Configuration

```env
QDRANT_HOST=localhost
QDRANT_PORT=6333
QDRANT_API_KEY=
QDRANT_TIMEOUT=30
QDRANT_ENABLED=true
```

> **Note:** `QDRANT_API_KEY` is optional for local instances, required for cloud.

#### OpenAI Configuration (Optional)

```env
AI_LLM_PROVIDER=openai
AI_EMBEDDING_PROVIDER=openai

OPENAI_API_KEY=sk-your-openai-api-key-here
OPENAI_MODEL=gpt-4o
OPENAI_TEMPERATURE=0.3
OPENAI_MAX_TOKENS=2000
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
```

#### Anthropic Configuration (Optional)

```env
AI_LLM_PROVIDER=anthropic
AI_EMBEDDING_PROVIDER=openai

ANTHROPIC_API_KEY=sk-ant-your-anthropic-key-here
ANTHROPIC_MODEL=claude-3-5-sonnet-20241022
ANTHROPIC_TEMPERATURE=0.3
ANTHROPIC_MAX_TOKENS=2000
```

> **Tip:** You can use OpenAI for embeddings and Anthropic for LLM, or vice versa!

#### Query & RAG Settings

```env
AI_MAX_RESULTS=100
AI_QUERY_TIMEOUT=30
AI_CACHE_TTL=3600

AI_VECTOR_SEARCH_LIMIT=5
AI_SIMILARITY_THRESHOLD=0.7
AI_INCLUDE_SCHEMA=true
AI_INCLUDE_EXAMPLES=true
```

#### Documentation Settings

```env
AI_DOCS_ENABLED=true
AI_DOCS_PREFIX=ai-docs
```

---

## Infrastructure Setup

### Step 5: Install Neo4j

**Using Docker (Recommended):**

```bash
docker run -d \
  --name neo4j \
  -p 7474:7474 -p 7687:7687 \
  -e NEO4J_AUTH=neo4j/your-secure-password \
  neo4j:latest
```

**Access Neo4j Browser:** http://localhost:7474

**Manual Installation:**
- Download from [neo4j.com/download](https://neo4j.com/download/)
- Follow platform-specific installation guide

---

### Step 6: Install Qdrant

**Using Docker (Recommended):**

```bash
docker run -d \
  --name qdrant \
  -p 6333:6333 \
  -p 6334:6334 \
  qdrant/qdrant:latest
```

**Access Qdrant Dashboard:** http://localhost:6333/dashboard

**Using Docker Compose:**

Create `docker-compose.yml`:

```yaml
version: '3.8'

services:
  neo4j:
    image: neo4j:latest
    ports:
      - "7474:7474"
      - "7687:7687"
    environment:
      - NEO4J_AUTH=neo4j/your-secure-password
    volumes:
      - neo4j_data:/data

  qdrant:
    image: qdrant/qdrant:latest
    ports:
      - "6333:6333"
      - "6334:6334"
    volumes:
      - qdrant_data:/qdrant/storage

volumes:
  neo4j_data:
  qdrant_data:
```

Start services:

```bash
docker-compose up -d
```

---

## Verify Installation

### Step 7: Test Connections

Create a simple test script `test-ai-setup.php`:

```php
<?php

use AiSystem\Facades\AI;
use AiSystem\VectorStore\QdrantStore;
use AiSystem\GraphStore\Neo4jStore;

// Test Neo4j Connection
try {
    $neo4j = new Neo4jStore([
        'uri' => 'bolt://localhost:7687',
        'username' => 'neo4j',
        'password' => 'your-secure-password',
        'database' => 'neo4j'
    ]);

    $schema = $neo4j->getSchema();
    echo "✓ Neo4j connected successfully\n";
    echo "  Labels: " . count($schema['labels']) . "\n";
} catch (Exception $e) {
    echo "✗ Neo4j connection failed: " . $e->getMessage() . "\n";
}

// Test Qdrant Connection
try {
    $qdrant = new QdrantStore([
        'host' => 'localhost',
        'port' => 6333,
        'api_key' => null,
        'timeout' => 30
    ]);

    $collections = $qdrant->listCollections();
    echo "✓ Qdrant connected successfully\n";
    echo "  Collections: " . count($collections) . "\n";
} catch (Exception $e) {
    echo "✗ Qdrant connection failed: " . $e->getMessage() . "\n";
}

// Test OpenAI (if configured)
if (config('ai.llm.openai.api_key')) {
    try {
        $response = AI::chat("Say 'Hello from AI System' if you can read this.");
        echo "✓ OpenAI connected successfully\n";
        echo "  Response: {$response}\n";
    } catch (Exception $e) {
        echo "✗ OpenAI connection failed: " . $e->getMessage() . "\n";
    }
}

echo "\n✓ All systems ready!\n";
```

Run the test:

```bash
php artisan tinker
include 'test-ai-setup.php'
```

---

## Next Steps

### Create Your First Entity

Define an entity that implements `Nodeable`:

```php
use AiSystem\Domain\Contracts\Nodeable;
use AiSystem\Domain\Traits\HasNodeableConfig;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'description'];

    public function getId(): string|int
    {
        return $this->id;
    }
}
```

Add configuration in `config/entities.php`:

```php
return [
    'Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => ['id', 'name', 'email'],
            'relationships' => []
        ],
        'vector' => [
            'collection' => 'customers',
            'embed_fields' => ['name', 'description'],
            'metadata' => ['id', 'email']
        ]
    ]
];
```

### Ingest Your First Entity

```php
use AiSystem\Facades\AI;

$customer = Customer::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'description' => 'Premium customer from New York'
]);

$status = AI::ingest($customer);

// Check status
var_dump($status);
// [
//     'graph_stored' => true,
//     'vector_stored' => true,
//     'relationships_created' => 0,
//     'errors' => []
// ]
```

---

## Common Installation Issues

### Neo4j Connection Refused

**Problem:** `Connection refused on bolt://localhost:7687`

**Solutions:**
1. Check Neo4j is running: `docker ps`
2. Verify port 7687 is exposed
3. Check firewall settings
4. Try HTTP URL: `http://localhost:7474`

---

### Qdrant Not Found

**Problem:** `Could not connect to Qdrant at localhost:6333`

**Solutions:**
1. Check Qdrant is running: `docker ps`
2. Verify port 6333 is exposed
3. Check URL format (no trailing slash)
4. Review Qdrant logs: `docker logs qdrant`

---

### OpenAI API Key Invalid

**Problem:** `Invalid API key`

**Solutions:**
1. Verify key starts with `sk-`
2. Check for extra spaces in `.env`
3. Clear config cache: `php artisan config:clear`
4. Verify API key in OpenAI dashboard

---

### Composer Installation Failed

**Problem:** `Package not found`

**Solutions:**
1. Check package name spelling
2. Verify Composer version: `composer --version`
3. Update Composer: `composer self-update`
4. Clear Composer cache: `composer clear-cache`

---

## Configuration Checklist

Before moving forward, ensure:

- ✅ Composer package installed
- ✅ Service provider registered
- ✅ Configuration files published
- ✅ Environment variables set
- ✅ Neo4j running and accessible
- ✅ Qdrant running and accessible
- ✅ AI provider API key configured (optional)
- ✅ Test script passes all checks

---

## What's Next?

Now that you're set up, proceed to:

1. **[Quick Start Guide](/docs/{{version}}/quick-start)** - First integration in 5 minutes
2. **[Simple Usage](/docs/{{version}}/simple-usage)** - Learn the AI wrapper API
3. **[Architecture Overview](/docs/{{version}}/architecture)** - Understand the system design

---

**Stuck?** Check the [Troubleshooting Guide](/docs/{{version}}/troubleshooting) for detailed solutions!
