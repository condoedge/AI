# Interfaces Reference

Reference for all public interfaces in the AI system.

---

## Entity Interfaces

### Nodeable

Interface for entities that can be stored in the graph (Neo4j). Records are written to the graph only: there is no vector configuration and no per-entity vector collection.

```php
<?php

namespace Condoedge\Ai\Domain\Contracts;

use Condoedge\Ai\Domain\ValueObjects\GraphConfig;

interface Nodeable
{
    /**
     * Get the Neo4j graph configuration for this entity.
     */
    public function getGraphConfig(): GraphConfig;

    /**
     * Get the unique identifier for this entity.
     */
    public function getId(): string|int;

    /**
     * Get all properties as an associative array.
     */
    public function toArray();
}
```

**Usage:**

```php
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    // The trait provides default implementations
    // Override methods as needed
}
```

---

## Provider Interfaces

### LlmProviderInterface

Interface for language model providers.

```php
<?php

namespace Condoedge\Ai\Contracts;

interface LlmProviderInterface
{
    /**
     * Generate a chat completion.
     *
     * @param array $messages Messages array with 'role' and 'content'
     * @param array $options Additional options
     * @return string Generated response
     */
    public function chat(array $messages, array $options = []): string;

    /**
     * Generate a completion for a single prompt.
     *
     * @param string $prompt The prompt text
     * @param array $options Additional options
     * @return string Generated response
     */
    public function complete(string $prompt, array $options = []): string;

    /**
     * Get the provider name.
     */
    public function getName(): string;

    /**
     * Check if the provider is available.
     */
    public function isAvailable(): bool;
}
```

**Implementations:**
- `OpenAiProvider`
- `AnthropicProvider`

### EmbeddingProviderInterface

Interface for embedding providers.

```php
<?php

namespace Condoedge\Ai\Contracts;

interface EmbeddingProviderInterface
{
    /**
     * Generate embeddings for text.
     *
     * @param string|array $text Single text or array of texts
     * @return array Single vector or array of vectors
     */
    public function embed(string|array $text): array;

    /**
     * Get the embedding dimensions.
     */
    public function getDimensions(): int;

    /**
     * Get the provider name.
     */
    public function getName(): string;

    /**
     * Check if the provider is available.
     */
    public function isAvailable(): bool;
}
```

**Implementations:**
- `OpenAiEmbeddingProvider`

---

## Prompt Interfaces

### PromptSectionInterface

Interface for prompt builder sections.

```php
<?php

namespace Condoedge\Ai\Contracts;

interface PromptSectionInterface
{
    /**
     * Get the section name.
     */
    public function getName(): string;

    /**
     * Get the section priority (higher = earlier).
     */
    public function getPriority(): int;

    /**
     * Format the section content.
     *
     * @param array $context Available context data
     * @param array $options Additional options
     * @return string Formatted content
     */
    public function format(array $context, array $options = []): string;

    /**
     * Check if section should be included.
     */
    public function shouldInclude(array $context, array $options = []): bool;
}
```

**Implementations:**
- `SystemSection`
- `SchemaCardsSection`
- `ScopesSection`
- `ExamplesSection`
- `GuidelinesSection`

### ResponseSectionInterface

Interface for response prompt sections.

```php
<?php

namespace Condoedge\Ai\Contracts;

interface ResponseSectionInterface
{
    /**
     * Get the section name.
     */
    public function getName(): string;

    /**
     * Get the section priority.
     */
    public function getPriority(): int;

    /**
     * Format the section for response generation.
     *
     * @param array $context Query results and context
     * @param array $options Style and format options
     * @return string Formatted content
     */
    public function format(array $context, array $options = []): string;

    /**
     * Check if section should be included.
     */
    public function shouldInclude(array $context, array $options = []): bool;
}
```

**Implementations:**
- `GuidelinesSection`
- `ResultsSection`

---

## File Processing Interfaces

### FileExtractorInterface

Interface for file content extractors.

```php
<?php

namespace Condoedge\Ai\Contracts;

interface FileExtractorInterface
{
    /**
     * Extract text content from a file.
     *
     * @param string $path File path
     * @return string Extracted text
     */
    public function extract(string $path): string;

    /**
     * Get supported file extensions.
     *
     * @return array Extensions like ['pdf', 'PDF']
     */
    public function getSupportedExtensions(): array;

    /**
     * Check if extractor can handle a file.
     */
    public function supports(string $path): bool;

    /**
     * Get extractor name.
     */
    public function getName(): string;
}
```

**Implementations:**
- `PdfExtractor`
- `DocxExtractor`
- `TextExtractor`

### ChunkableExtractorInterface

Interface for extractors that support chunking.

```php
<?php

namespace Condoedge\Ai\Contracts;

interface ChunkableExtractorInterface
{
    /**
     * Extract and return chunks directly.
     *
     * @param string $path File path
     * @param array $options Chunking options
     * @return array Array of chunks with metadata
     */
    public function extractChunks(string $path, array $options = []): array;
}
```

---

## Storage Interfaces

### GraphStoreInterface

Interface for graph database operations.

```php
<?php

namespace Condoedge\Ai\Contracts;

interface GraphStoreInterface
{
    /**
     * Store a node.
     */
    public function storeNode(string $label, array $properties): void;

    /**
     * Update a node.
     */
    public function updateNode(string $label, mixed $id, array $properties): void;

    /**
     * Delete a node.
     */
    public function deleteNode(string $label, mixed $id): void;

    /**
     * Create a relationship.
     */
    public function createRelationship(
        string $fromLabel,
        mixed $fromId,
        string $type,
        string $toLabel,
        mixed $toId,
        array $properties = []
    ): void;

    /**
     * Execute a query.
     */
    public function query(string $cypher, array $params = []): array;

    /**
     * Get the schema.
     */
    public function getSchema(): array;
}
```

**Implementations:**
- `Neo4jStore`

### VectorStoreInterface

Interface for vector database operations.

```php
<?php

namespace Condoedge\Ai\Contracts;

interface VectorStoreInterface
{
    /**
     * Store a vector.
     */
    public function store(
        string $collection,
        mixed $id,
        array $vector,
        array $metadata = []
    ): void;

    /**
     * Update a vector.
     */
    public function update(
        string $collection,
        mixed $id,
        array $vector,
        array $metadata = []
    ): void;

    /**
     * Delete a vector.
     */
    public function delete(string $collection, mixed $id): void;

    /**
     * Search for similar vectors.
     */
    public function search(
        string $collection,
        array $vector,
        int $limit = 10,
        float $threshold = 0.7,
        array $filters = []
    ): array;

    /**
     * Create a collection.
     */
    public function createCollection(string $name, int $dimensions): void;

    /**
     * Check if collection exists.
     */
    public function collectionExists(string $name): bool;
}
```

**Implementations:**
- `QdrantStore`

---

## Configuration Interfaces

### EntityConfigInterface

Interface for entity configuration providers.

```php
<?php

namespace Condoedge\Ai\Contracts;

interface EntityConfigInterface
{
    /**
     * Get configuration for an entity class.
     */
    public function getConfig(string $entityClass): array;

    /**
     * Get all entity configurations.
     */
    public function getAllConfigs(): array;

    /**
     * Check if entity is configured.
     */
    public function hasConfig(string $entityClass): bool;
}
```

---

## Semantic Matching Services

These are concrete service classes (no shared interface). Resolve them from the
container with `app(...)` and pass `config('ai.entities')` as the entity configs.

### ScopeSemanticMatcher

Matches a question against the scopes defined on your entities.

```php
<?php

namespace Condoedge\Ai\Services;

class ScopeSemanticMatcher
{
    /**
     * Find scopes that semantically match the user's question.
     *
     * @param string $question     User's question
     * @param array  $entityConfigs Entity configurations (e.g. config('ai.entities'))
     * @param float  $threshold     Minimum similarity score (0-1)
     * @param int    $topK          Maximum number of matches to return
     * @return array Matched scopes keyed by scope, each with 'match_score',
     *               'entity', 'scope', 'match_type' and 'matched_example'
     */
    public function findMatchingScopes(
        string $question,
        array $entityConfigs,
        float $threshold = 0.7,
        int $topK = 5
    ): array;
}
```

### SemanticContextSelector

Selects the relevant entities, relationships and scopes for a question.

```php
<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\DTOs\SemanticSelectionResult;

class SemanticContextSelector
{
    /**
     * Select relevant context components for a question.
     *
     * @param string $question      User's question
     * @param array  $entityConfigs Entity configurations (e.g. config('ai.entities'))
     * @param array  $options       ['threshold' => float, 'top_k' => int]
     */
    public function selectRelevantContext(
        string $question,
        array $entityConfigs,
        array $options = []
    ): SemanticSelectionResult;
}
```

---

## Service Classes

### Key Services

| Service | Purpose |
|---------|---------|
| `ContextRetriever` | Retrieves context for queries |
| `SemanticPromptBuilder` | Builds prompts for LLM |
| `ResponseGenerator` | Generates natural language responses |
| `QueryGenerator` | Generates Cypher queries |
| `EntityIngester` | Ingests entities to storage |
| `SemanticContextSelector` | Selects relevant context |
| `FileProcessingService` | Processes file content |

### Using Services Directly

```php
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Services\ResponseGenerator;

// Context retrieval
$retriever = app(ContextRetriever::class);
$context = $retriever->getMinimalContext("Show customers");

// Query generation
$generator = app(QueryGenerator::class);
$cypher = $generator->generate("Active customers", $context);

// Response generation
$responder = app(ResponseGenerator::class);
$response = $responder->generate($results, $context, ['style' => 'friendly']);
```

---

## Value Objects

### NodeableConfig

Fluent builder for entity configuration.

```php
<?php

namespace Condoedge\Ai\Domain\ValueObjects;

class NodeableConfig
{
    public static function for(string $class): self;

    // Graph configuration
    public function label(string $label): self;
    public function properties(string ...$properties): self;
    public function relationship(
        string $relation,
        string $targetLabel,
        string $type,
        string $foreignKey
    ): self;

    // Metadata
    public function aliases(string ...$aliases): self;
    public function description(string $description): self;
    public function scope(string $name, array $config): self;

    // Behavior
    public function autoSync(bool $enabled): self;
    public function queueSync(bool $enabled): self;

    // Convert to array
    public function toArray(): array;
}
```

**Usage:**

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::for(static::class)
        ->label('Customer')
        ->properties('id', 'name', 'email')
        ->aliases('customer', 'client');
}
```

---

## Related Documentation

- [Custom LLM Providers](/docs/{{version}}/extending/llm-providers) - Provider implementation
- [Custom Embedding Providers](/docs/{{version}}/extending/embedding-providers) - Embedding providers
- [Custom Prompt Sections](/docs/{{version}}/extending/prompt-sections) - Prompt customization
- [Custom File Extractors](/docs/{{version}}/extending/file-extractors) - File extractors
