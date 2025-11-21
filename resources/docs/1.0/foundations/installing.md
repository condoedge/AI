# Installing & Verifying the Stack

Use this walkthrough to install the package, wire up infrastructure, and confirm every integration (Neo4j, Qdrant, OpenAI/Anthropic, and LaRecipe-powered docs) is healthy.

> **You should have already read:** [Requirements & Compatibility](/docs/{{version}}/foundations/requirements)

---

## 1. Install the Package

```bash
composer require condoedge/ai
```

Laravel 10+ auto-discovers the service provider. For earlier versions, add it to `config/app.php`:

```php
'providers' => [
    // ...
    Condoedge\Ai\AiServiceProvider::class,
],
```

---

## 2. Publish Configuration & Docs Assets

```bash
php artisan vendor:publish --tag=ai-config      # config/ai.php
php artisan vendor:publish --tag=ai-entities    # config/entities.php (optional template)
php artisan vendor:publish --tag=ai-docs        # resources/docs seeded for binarytorch/larecipe
```

The documentation tag gives `binarytorch/larecipe` a home under `resources/docs/1.0`, which this project uses for the in-app docs portal (`/{AI_DOCS_PREFIX}`, defaults to `/ai-docs`).

---

## 3. Configure Your Environment

Create or update `.env` with the minimum viable settings:

```env
# Neo4j
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=change-me
NEO4J_DATABASE=neo4j

# Qdrant
QDRANT_HOST=localhost
QDRANT_PORT=6333
QDRANT_API_KEY=

# AI Providers
AI_LLM_PROVIDER=openai      # or anthropic
AI_EMBEDDING_PROVIDER=openai
OPENAI_API_KEY=sk-your-key
ANTHROPIC_API_KEY=

# Docs Portal
AI_DOCS_ENABLED=true
AI_DOCS_PREFIX=ai-docs
```

Additional tuning knobs live inside `config/ai.php`. Cross-reference the [Configuration Reference](/docs/{{version}}/foundations/configuration) once you have the basics wired.

---

## 4. Provision Neo4j & Qdrant (Docker Friendly)

```yaml
# docker-compose.ai.yml
version: '3.8'
services:
  neo4j:
    image: neo4j:5.15
    ports:
      - "7474:7474"
      - "7687:7687"
    environment:
      - NEO4J_AUTH=neo4j/change-me
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

Spin them up:

```bash
docker compose -f docker-compose.ai.yml up -d
```

- Browse Neo4j: <http://localhost:7474>
- Browse Qdrant: <http://localhost:6333/dashboard>

---

## 5. Smoke Test Every Integration

Create `scripts/test-ai-setup.php`:

```php
<?php

use Condoedge\Ai\VectorStore\QdrantStore;
use Condoedge\Ai\GraphStore\Neo4jStore;
use Condoedge\Ai\Facades\AI;

$neo4j = new Neo4jStore(config('ai.graph.neo4j'));
$schema = $neo4j->getSchema();
echo "Neo4j OK: labels=" . count($schema['labels']) . PHP_EOL;

$qdrant = new QdrantStore(config('ai.vector.qdrant'));
$collections = $qdrant->listCollections();
echo "Qdrant OK: collections=" . count($collections) . PHP_EOL;

if (config('ai.llm.default') === 'openai' && config('ai.llm.openai.api_key')) {
    $reply = AI::chat("Say 'Hello from AI Package'");
    echo "LLM OK: {$reply}" . PHP_EOL;
}

echo "Setup complete" . PHP_EOL;
```

Run inside `php artisan tinker` or directly via `php scripts/test-ai-setup.php`.

---

## 6. Enable In-App Docs (Optional But Recommended)

- Ensure `AI_DOCS_ENABLED=true`.
- Hit `http://your-app.test/ai-docs`.
- LaRecipe will render everything inside `resources/docs/1.0`. The three main groups are Foundations, Usage, and Internals.

Protect the docs behind auth by extending the middleware array in `config/ai.php`:

```php
'documentation' => [
    'route_prefix' => env('AI_DOCS_PREFIX', 'ai-docs'),
    'middleware' => ['web', 'auth'],
    // ...
],
```

---

## 7. First Entity Test

```php
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;
    protected $fillable = ['name', 'email', 'status'];
}

$customer = Customer::create([
    'name' => 'Jane Doe',
    'email' => 'jane@example.com',
    'status' => 'active',
]);

$status = AI::ingest($customer);
// Expect graph & vector success flags in the response array
```

If you see errors, jump ahead to [Troubleshooting](/docs/{{version}}/foundations/troubleshooting).

---

## 8. Next Steps

- Tune every knob in the [Configuration Reference](/docs/{{version}}/foundations/configuration).
- Learn how to work with the APIs in [Usage & Extension](/docs/{{version}}/usage).
- Share the `/ai-docs` route with teammates so they can self-serve onboarding content powered by `binarytorch/larecipe`.
