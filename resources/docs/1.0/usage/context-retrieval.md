# Context Retrieval (RAG) API

Retrieval-Augmented Generation (RAG) provides rich context to LLMs by combining vector similarity search with graph schema discovery.

---

## Overview

RAG retrieves context from multiple sources:
1. **Similar Questions** - Vector search finds semantically related past queries
2. **Graph Schema** - Neo4j schema provides structural understanding
3. **Example Entities** - Sample data gives concrete context

---

## Complete Context Retrieval

```php
use Condoedge\Ai\Facades\AI;

$context = AI::retrieveContext("Show teams with most members", [
    'collection' => 'questions',
    'limit' => 5,
    'includeSchema' => true,
    'includeExamples' => true,
    'examplesPerLabel' => 2,
    'scoreThreshold' => 0.7
]);
```

**Response:**
```php
[
    'similar_queries' => [
        [
            'question' => 'List all teams',
            'query' => 'MATCH (t:Team) RETURN t',
            'score' => 0.89,
            'metadata' => [...]
        ]
    ],
    'graph_schema' => [
        'labels' => ['Team', 'Person'],
        'relationships' => ['MEMBER_OF'],
        'properties' => ['id', 'name', 'size']
    ],
    'relevant_entities' => [
        'Team' => [
            ['id' => 1, 'name' => 'Alpha', 'size' => 10]
        ]
    ],
    'errors' => []
]
```

---

## Options Reference

```php
$options = [
    'collection' => 'questions',      // Vector collection name
    'limit' => 5,                     // Max similar queries
    'includeSchema' => true,          // Include graph schema
    'includeExamples' => true,        // Include sample entities
    'examplesPerLabel' => 2,          // Examples per label
    'scoreThreshold' => 0.7           // Min similarity (0.0-1.0)
];
```

---

## Vector Similarity Search

Find semantically similar items:

```php
$similar = AI::searchSimilar("software development", [
    'collection' => 'teams',
    'limit' => 5,
    'scoreThreshold' => 0.7
]);
```

**Response:**
```php
[
    [
        'question' => 'Engineering Team description',
        'score' => 0.89,
        'metadata' => ['id' => 1, 'name' => 'Engineering Team']
    ]
]
```

---

## Graph Schema Discovery

Get database structure:

```php
$schema = AI::getSchema();
```

**Response:**
```php
[
    'labels' => ['Team', 'Person', 'Project'],
    'relationships' => ['MEMBER_OF', 'WORKS_ON'],
    'properties' => ['id', 'name', 'email', 'created_at']
]
```

---

## Using Context for Query Generation

```php
// 1. Get context
$context = AI::retrieveContext($question);

// 2. Build prompt
$systemPrompt = "You are a Cypher query expert.";
$userPrompt = sprintf(
    "Question: %s\nSchema: %s\nExamples: %s\nGenerate Cypher query.",
    $question,
    json_encode($context['graph_schema']),
    json_encode($context['similar_queries'])
);

// 3. Generate query
$cypherQuery = AI::complete($userPrompt, $systemPrompt);
```

---

## Advanced: Direct Service Usage

```php
use Condoedge\Ai\Services\ContextRetriever;

$retriever = app(ContextRetriever::class);

$context = $retriever->retrieveContext($question, $options);
$similar = $retriever->searchSimilar($question, 'collection', 5);
$schema = $retriever->getGraphSchema();
$examples = $retriever->getExampleEntities('Team', 3);
```

---

## Error Handling

RAG implements graceful degradation:

```php
$context = AI::retrieveContext($question);

if (!empty($context['errors'])) {
    // Some sources failed, but others succeeded
    Log::warning('Partial RAG failure', $context['errors']);
}

// Use what succeeded
if (!empty($context['similar_queries'])) {
    // Vector search succeeded
}

if (!empty($context['graph_schema'])) {
    // Schema retrieval succeeded
}
```

---

See also: [Simple Usage](/docs/{{version}}/usage/simple-usage) | [Embeddings](/docs/{{version}}/usage/embeddings) | [LLM](/docs/{{version}}/usage/llm)
