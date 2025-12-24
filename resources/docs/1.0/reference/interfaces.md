# Interfaces Reference

Reference for all public interfaces in the AI system.

---

## Entity Interfaces

### Nodeable

Interface for entities that can be stored in the AI system.

```php
<?php

namespace Condoedge\Ai\Domain\Contracts;

interface Nodeable
{
    /**
     * Get the Neo4j node label.
     */
    public function getNodeLabel(): string;

    /**
     * Get properties to store in Neo4j.
     */
    public function getNodeProperties(): array;

    /**
     * Get the unique identifier for the node.
     */
    public function getNodeId(): mixed;

    /**
     * Get text content for embedding.
     */
    public function getEmbedContent(): string;

    /**
     * Get metadata for vector storage.
     */
    public function getVectorMetadata(): array;

    /**
     * Get the Qdrant collection name.
     */
    public function getCollectionName(): string;
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
- `SchemaSection`
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

## Matching Interfaces

### MatcherInterface

Interface for semantic matchers.

```php
<?php

namespace Condoedge\Ai\Contracts;

interface MatcherInterface
{
    /**
     * Find matches for a query.
     *
     * @param string $query Search query
     * @param array $candidates Candidate items
     * @param float $threshold Minimum similarity
     * @return array Matches with scores
     */
    public function match(string $query, array $candidates, float $threshold = 0.7): array;
}
```

**Implementations:**
- `SemanticMatcher`
- `ScopeSemanticMatcher`

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

    // Vector configuration
    public function collection(string $name): self;
    public function embedFields(string ...$fields): self;
    public function metadata(array $fields): self;

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
        ->collection('customers')
        ->embedFields('name', 'email')
        ->aliases('customer', 'client');
}
```

---

## Related Documentation

- [Custom LLM Providers](/docs/{{version}}/extending/llm-providers) - Provider implementation
- [Custom Embedding Providers](/docs/{{version}}/extending/embedding-providers) - Embedding providers
- [Custom Prompt Sections](/docs/{{version}}/extending/prompt-sections) - Prompt customization
- [Custom File Extractors](/docs/{{version}}/extending/file-extractors) - File extractors
