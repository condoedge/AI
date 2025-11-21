# Quick Start Guide

Get up and running with the AI Text-to-Query System in 5 minutes! This guide shows you the simplest possible usage.

---

## Prerequisites

Before starting, ensure you have:

- ✅ Installed the package ([Installation Guide](/docs/{{version}}/foundations/installing))
- ✅ Neo4j and Qdrant running
- ✅ Environment variables configured
- ✅ OpenAI or Anthropic API key (optional for basic ingestion)

---

## Step 1: Define Your Entity (2 minutes)

Create a model that implements `Nodeable`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Team extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'description', 'size'];

    public function getId(): string|int
    {
        return $this->id;
    }
}
```

---

## Step 2: Configure Entity Mapping (2 minutes)

Add configuration to `config/entities.php`:

```php
<?php

return [
    'Team' => [
        'graph' => [
            'label' => 'Team',
            'properties' => ['id', 'name', 'size'],
            'relationships' => []
        ],
        'vector' => [
            'collection' => 'teams',
            'embed_fields' => ['name', 'description'],
            'metadata' => ['id', 'name', 'size']
        ]
    ]
];
```

**What this does:**
- **Graph:** Stores Team nodes in Neo4j with id, name, and size properties
- **Vector:** Creates embeddings from name and description for semantic search
- **Metadata:** Stores id, name, size in Qdrant for filtering

---

## Step 3: Ingest Your First Entity (1 minute)

```php
use Condoedge\Ai\Facades\AI;
use App\Models\Team;

// Create a team
$team = Team::create([
    'name' => 'Engineering Team',
    'description' => 'Builds awesome software products',
    'size' => 10
]);

// Ingest into AI system
$status = AI::ingest($team);

// Check the result
print_r($status);
```

**Output:**
```php
[
    'graph_stored' => true,
    'vector_stored' => true,
    'relationships_created' => 0,
    'errors' => []
]
```

**What happened:**
1. Team node created in Neo4j with properties
2. Embedding generated from "Engineering Team Builds awesome software products"
3. Vector stored in Qdrant with metadata
4. Status report returned

---

## Common Use Cases

### Use Case 1: Ingest Multiple Entities (Batch)

Batch ingestion is more efficient when processing many entities:

```php
use Condoedge\Ai\Facades\AI;

$teams = Team::all(); // Get all teams

$result = AI::ingestBatch($teams->toArray());

print_r($result);
// [
//     'total' => 10,
//     'succeeded' => 10,
//     'partially_succeeded' => 0,
//     'failed' => 0,
//     'errors' => []
// ]
```

---

### Use Case 2: Search for Similar Entities

Find semantically similar teams using vector search:

```php
use Condoedge\Ai\Facades\AI;

$similar = AI::searchSimilar(
    "Teams working on software development",
    ['collection' => 'teams', 'limit' => 5]
);

foreach ($similar as $result) {
    echo "Team: {$result['metadata']['name']}\n";
    echo "Score: {$result['score']}\n\n";
}
```

**Output:**
```
Team: Engineering Team
Score: 0.89

Team: Development Squad
Score: 0.85

Team: Tech Builders
Score: 0.78
```

---

### Use Case 3: Update an Entity (Sync)

When an entity changes, sync it to both stores:

```php
use Condoedge\Ai\Facades\AI;

$team = Team::find(1);
$team->size = 15;
$team->description = 'Elite software development team';
$team->save();

// Sync changes to AI system
$status = AI::sync($team);

print_r($status);
// [
//     'action' => 'updated',
//     'graph_synced' => true,
//     'vector_synced' => true,
//     'errors' => []
// ]
```

---

### Use Case 4: Remove an Entity

Delete entity from both Neo4j and Qdrant:

```php
use Condoedge\Ai\Facades\AI;

$team = Team::find(1);

// Remove from AI system
$success = AI::remove($team);

if ($success) {
    // Now safe to delete from database
    $team->delete();
}
```

---

### Use Case 5: Get Context for a Question (RAG)

Retrieve rich context for LLM query generation:

```php
use Condoedge\Ai\Facades\AI;

$question = "Show me all engineering teams";

$context = AI::retrieveContext($question, [
    'collection' => 'teams',
    'limit' => 5,
    'includeSchema' => true,
    'includeExamples' => true
]);

print_r($context);
```

**Output:**
```php
[
    'similar_queries' => [
        [
            'question' => 'List all development teams',
            'query' => 'MATCH (t:Team) WHERE t.name CONTAINS "dev" RETURN t',
            'score' => 0.87
        ]
    ],
    'graph_schema' => [
        'labels' => ['Team', 'Person'],
        'relationships' => ['MEMBER_OF'],
        'properties' => ['id', 'name', 'size']
    ],
    'relevant_entities' => [
        'Team' => [
            ['id' => 1, 'name' => 'Engineering Team', 'size' => 10]
        ]
    ],
    'errors' => []
]
```

---

### Use Case 6: Chat with LLM

Simple chat interface using OpenAI or Anthropic:

```php
use Condoedge\Ai\Facades\AI;

// Simple question
$response = AI::chat("What is the capital of France?");
echo $response; // "The capital of France is Paris."

// With conversation history
$conversation = [
    ['role' => 'system', 'content' => 'You are a helpful assistant'],
    ['role' => 'user', 'content' => 'What is 2+2?'],
    ['role' => 'assistant', 'content' => '4'],
    ['role' => 'user', 'content' => 'What about 3+3?']
];

$response = AI::chat($conversation);
echo $response; // "6"
```

---

### Use Case 7: Generate Embeddings

Create vector embeddings for custom text:

```php
use Condoedge\Ai\Facades\AI;

$text = "Artificial Intelligence and Machine Learning";
$vector = AI::embed($text);

echo "Dimensions: " . count($vector) . "\n"; // 1536
echo "First values: " . implode(', ', array_slice($vector, 0, 5)) . "\n";
// First values: 0.023, -0.015, 0.042, -0.008, 0.031
```

---

### Use Case 8: Get Graph Schema

Discover what's in your Neo4j database:

```php
use Condoedge\Ai\Facades\AI;

$schema = AI::getSchema();

print_r($schema);
// [
//     'labels' => ['Team', 'Person', 'Project'],
//     'relationships' => ['MEMBER_OF', 'WORKS_ON'],
//     'properties' => ['id', 'name', 'email', 'size']
// ]
```

---

## Complete Example: Question Answering System

Here's a complete example combining multiple features:

```php
use Condoedge\Ai\Facades\AI;

// 1. User asks a question
$question = "Show me teams with more than 5 members";

// 2. Retrieve context using RAG
$context = AI::retrieveContext($question, [
    'collection' => 'teams',
    'limit' => 3,
    'includeSchema' => true,
    'includeExamples' => true
]);

// 3. Build prompt for LLM
$systemPrompt = "You are a Cypher query expert. Generate valid Neo4j queries.";

$userPrompt = sprintf(
    "Question: %s\n\nGraph Schema:\n%s\n\nSimilar Queries:\n%s\n\nGenerate a Cypher query.",
    $question,
    json_encode($context['graph_schema'], JSON_PRETTY_PRINT),
    json_encode($context['similar_queries'], JSON_PRETTY_PRINT)
);

// 4. Generate Cypher query
$cypherQuery = AI::complete($userPrompt, $systemPrompt);

echo "Generated Query:\n{$cypherQuery}\n";

// Output:
// MATCH (t:Team) WHERE t.size > 5 RETURN t
```

---

## Automatic Model Sync with Observers

For production use, automatically sync entities when they change:

```php
<?php

namespace App\Observers;

use Condoedge\Ai\Facades\AI;
use App\Models\Team;

class TeamObserver
{
    public function created(Team $team)
    {
        AI::ingest($team);
    }

    public function updated(Team $team)
    {
        AI::sync($team);
    }

    public function deleted(Team $team)
    {
        AI::remove($team);
    }
}
```

Register in `AppServiceProvider`:

```php
use App\Models\Team;
use App\Observers\TeamObserver;

public function boot()
{
    Team::observe(TeamObserver::class);
}
```

Now all Team changes automatically sync to the AI system!

---

## Testing Your Integration

Create a simple test:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Team;
use Condoedge\Ai\Facades\AI;

class TeamAiIntegrationTest extends TestCase
{
    public function test_team_ingestion()
    {
        $team = Team::create([
            'name' => 'Test Team',
            'description' => 'Testing AI integration',
            'size' => 5
        ]);

        $status = AI::ingest($team);

        $this->assertTrue($status['graph_stored']);
        $this->assertTrue($status['vector_stored']);
        $this->assertEmpty($status['errors']);

        // Cleanup
        AI::remove($team);
        $team->delete();
    }
}
```

Run tests:

```bash
php artisan test --filter=TeamAiIntegrationTest
```

---

## Next Steps

You've completed the quick start! Here's what to explore next:

### Learn More About APIs
- **[Simple Usage Guide](/docs/{{version}}/usage/simple-usage)** - All AI wrapper methods
- **[Data Ingestion API](/docs/{{version}}/usage/data-ingestion)** - Detailed ingestion guide
- **[Context Retrieval](/docs/{{version}}/usage/context-retrieval)** - Deep dive into RAG

### Advanced Topics
- **[Advanced Usage](/docs/{{version}}/usage/advanced-usage)** - Direct service usage
- **[Laravel Integration](/docs/{{version}}/usage/laravel-integration)** - Controllers, commands, queues
- **[Real-World Examples](/docs/{{version}}/usage/examples)** - Complete implementations

### Configuration
- **[Configuration Reference](/docs/{{version}}/foundations/configuration)** - All settings explained
- **[Architecture Overview](/docs/{{version}}/internals/architecture)** - System design deep dive

---

**Questions?** Check the [Troubleshooting Guide](/docs/{{version}}/foundations/troubleshooting) or explore [Real-World Examples](/docs/{{version}}/usage/examples)!
