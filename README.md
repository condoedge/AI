# AI Package - Text-to-Cypher with RAG

> A Laravel package providing intelligent text-to-Cypher query generation with RAG (Retrieval-Augmented Generation), dual-storage coordination (Neo4j + Qdrant), and auto-discovery from Eloquent models.

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-%5E9.0-red)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Key Features](#key-features)
- [Security Architecture](#security-architecture)
- [Quick Start](#quick-start)
- [Project Structure](#project-structure)
- [Testing](#testing)
- [Documentation](#documentation)
- [Development](#development)
- [Technical Decisions](#technical-decisions)

## Overview

### What This Package Does

The AI package transforms natural language questions into executable Neo4j Cypher queries using RAG-powered LLMs. It automatically discovers entity configurations from your existing Eloquent models, eliminating manual setup while maintaining dual-storage synchronization between Neo4j (graph relationships) and Qdrant (vector embeddings).

**Core Value Proposition:**
- Zero configuration for most use cases (convention over configuration)
- Automatic discovery from Eloquent models - no duplication
- Dual-storage coordination with consistency guarantees
- Production-ready security (injection protection, retry logic, circuit breakers)
- RAG-powered intelligent query generation

**Key Technologies:**
- **Neo4j**: Graph database for relationship storage and pattern matching
- **Qdrant**: Vector database for semantic similarity search
- **Laravel**: PHP framework integration with Eloquent ORM
- **OpenAI/Anthropic**: LLM providers for query generation and embeddings

### Example

```php
// 1. Make your model Nodeable (zero config needed)
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'status'];

    public function scopeActive($query) {
        return $query->where('status', 'active');
    }
}

// 2. Data auto-syncs on create/update/delete
$customer = Customer::create(['name' => 'John Doe', 'email' => 'john@example.com']);
// Automatically stored in Neo4j + Qdrant

// 3. Ask questions in natural language
$response = AI::chat("How many active customers do we have?");
// Generated Cypher: MATCH (n:Customer) WHERE n.status = 'active' RETURN count(n)
// Response: "You have 1,250 active customers in the system."
```

## Architecture

### High-Level Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                         User Question                                │
│                "Show active customers in USA"                        │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     ChatOrchestrator                                 │
│  ┌─────────────────────────────────────────────────────────┐        │
│  │ 1. Context Retrieval (RAG)                              │        │
│  │    - Vector search for similar past queries             │        │
│  │    - Fetch graph schema from Neo4j                      │        │
│  │    - Retrieve example entities                          │        │
│  └─────────────────────────────────────────────────────────┘        │
│  ┌─────────────────────────────────────────────────────────┐        │
│  │ 2. Query Generation                                     │        │
│  │    - Build LLM prompt with context                      │        │
│  │    - Generate Cypher query                              │        │
│  │    - Validate (injection check, complexity, safety)     │        │
│  └─────────────────────────────────────────────────────────┘        │
│  ┌─────────────────────────────────────────────────────────┐        │
│  │ 3. Query Execution                                      │        │
│  │    - Execute against Neo4j with timeout                 │        │
│  │    - Format results                                     │        │
│  └─────────────────────────────────────────────────────────┘        │
│  ┌─────────────────────────────────────────────────────────┐        │
│  │ 4. Response Generation                                  │        │
│  │    - Transform to natural language                      │        │
│  │    - Extract insights (trends, outliers, patterns)      │        │
│  │    - Suggest visualizations                             │        │
│  └─────────────────────────────────────────────────────────┘        │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│        Natural Language Answer + Insights + Suggestions             │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                      Data Ingestion Flow                             │
└─────────────────────────────────────────────────────────────────────┘

Eloquent Model Event (create/update/delete)
           │
           ▼
   HasNodeableConfig Trait
   (Auto-sync listener)
           │
           ▼
   EntityAutoDiscovery
   - Reflect on model
   - Extract properties from $fillable, $casts
   - Discover relationships (belongsTo)
   - Detect text fields for embedding
   - Convert scopes to Cypher patterns
           │
           ▼
   DataIngestionService
   ┌──────────────────────────────────┐
   │ Compensating Transaction Pattern │
   └──────────────────────────────────┘
           │
           ├─────────────────────┬──────────────────────┐
           ▼                     ▼                      ▼
    Generate Embedding    Neo4j Store           Qdrant Store
    (OpenAI/Anthropic)   (Graph + Relations)   (Vector + Metadata)
           │                     │                      │
           └─────────────────────┴──────────────────────┘
                                 │
                     ┌───────────┴──────────┐
                     │  Success?            │
                     │  - Both stores OK    │
                     │  - Rollback on fail  │
                     └──────────────────────┘
```

### Core Components

#### 1. Auto-Discovery System
- **EntityAutoDiscovery**: Introspects Eloquent models using PHP Reflection
- **CypherScopeAdapter**: Converts Eloquent scopes to Cypher patterns
- **SchemaInspector**: Extracts database schema hints
- **ConfigCache**: Caches expensive discovery operations

#### 2. Dual-Storage Coordination
- **DataIngestionService**: Orchestrates writes to both stores with compensating transactions
- **Neo4j (GraphStore)**: Node storage, relationships, pattern matching
- **Qdrant (VectorStore)**: Vector embeddings, semantic search, metadata filtering
- **Auto-Sync**: Automatic synchronization via Laravel model events

#### 3. RAG System
- **ContextRetriever**: Fetches similar queries + schema + examples
- **PatternLibrary**: Pre-defined query patterns for common questions
- **QueryGenerator**: LLM-powered Cypher generation with validation
- **QueryExecutor**: Safe query execution with timeouts and limits
- **ResponseGenerator**: Natural language explanations with insights

#### 4. Security Layer
- **CypherSanitizer**: Injection prevention for labels, types, property keys
- **RetryPolicy**: Exponential backoff with jitter
- **CircuitBreaker**: Fail-fast pattern for cascading failure prevention
- **SensitiveDataSanitizer**: API key and credential redaction in logs

## Key Features

### Auto-Discovery from Eloquent Models

Eliminates duplication by extracting entity configuration directly from your models:

- **Properties**: Auto-detected from `$fillable`, `$casts`, `$dates`
- **Relationships**: Auto-discovered from `belongsTo()` methods
- **Scopes**: Auto-converted from `scopeX()` methods to Cypher patterns
- **Embed Fields**: Text fields automatically identified for vector embeddings
- **Aliases**: Generated from table names for semantic matching

**Three-tier fallback**: Explicit config > Legacy config file > Auto-discovery

### Dual-Storage Coordination

Synchronized writes to Neo4j (graph) and Qdrant (vector) with consistency guarantees:

- **Compensating Transactions**: Automatic rollback on partial failure
- **No Orphaned Data**: Either both stores succeed or both roll back
- **Independent Resilience**: One store failing doesn't break the other
- **Batch Operations**: Efficient bulk ingestion

### RAG-Powered Query Generation

Intelligent Cypher generation using retrieval-augmented generation:

- **Context-Aware**: Similar past queries inform new query generation
- **Schema-Aware**: Graph structure guides query construction
- **Example-Based**: Sample data provides reference patterns
- **Pattern Matching**: Pre-defined templates for common queries
- **Validation**: Syntax, safety, and complexity checks

### Auto-Sync Capabilities

Zero-boilerplate synchronization via Laravel events:

- **Event-Driven**: Automatic sync on create, update, delete
- **Async Support**: Optional queue processing
- **Configurable**: Per-model, per-operation granularity
- **Error Handling**: Silent failure with logging or exception throwing

## Security Architecture

The package implements **defense-in-depth security** with multiple layers of protection. All security features are enabled by default with no configuration required.

### 1. Injection Protection

**Cypher Injection Prevention** (`CypherSanitizer`):
- Validates all labels, relationship types, and property keys against strict patterns
- Regex: `[a-zA-Z_][a-zA-Z0-9_]*` (alphanumeric + underscore, must start with letter)
- Blocks reserved Cypher keywords (MATCH, DELETE, DROP, CREATE, etc.)
- Maximum length validation (255 characters)
- Backtick escaping as additional defense layer

**SQL Injection Prevention** (`SchemaInspector`):
- Table/index name validation in SQLite PRAGMA queries
- Prevents malicious identifiers in auto-discovery schema introspection
- Parameter binding for MySQL/PostgreSQL

**Example Protection:**
```php
// Automatic injection protection
CypherSanitizer::validateLabel("User}); DELETE (n) //");
// Throws: CypherInjectionException

CypherSanitizer::validateLabel("User_Profile");
// Returns: "User_Profile" ✓
```

### 2. Data Consistency Guarantees

**Compensating Transactions** (`DataIngestionService`):
- Two-phase commit pattern for dual-store operations
- Automatic rollback on vector store failure
- Automatic restoration on deletion failure
- Critical error logging when compensation fails

**Transaction Flow:**
```
1. Write to Neo4j → Success
2. Write to Qdrant → Failure
3. Rollback Neo4j → Success
4. Throw DataConsistencyException
```

### 3. Resilience & Fault Tolerance

**Circuit Breaker Pattern** (`CircuitBreaker`):
- States: CLOSED → OPEN → HALF_OPEN → CLOSED
- Prevents cascading failures
- Configurable failure threshold (default: 5 failures)
- Configurable recovery timeout (default: 30 seconds)
- Fail-fast when circuit open

**Retry Policy** (`RetryPolicy`):
- Exponential backoff with jitter
- Prevents thundering herd problem
- Configurable max attempts (default: 3-5 depending on operation)
- Separate policies for API calls, database operations, network requests

**Example:**
```php
// Automatic retry and circuit breaking
$neo4j = new Neo4jStore(); // Includes retry + circuit breaker
$result = $neo4j->createNode('User', $properties);
// Retries on transient failures, fails fast if circuit open
```

### 4. Sensitive Data Protection

**Log Sanitization** (`SensitiveDataSanitizer`):
- Automatic redaction of API keys, passwords, tokens, secrets
- Pattern detection for multiple formats:
  - OpenAI keys: `sk-...`
  - Anthropic keys: `sk-ant-...`
  - AWS credentials
  - Bearer tokens
  - Database passwords
- Stack trace sanitization
- Absolute path removal

**Protected Patterns:**
```php
// Automatic sanitization
Log::error('API failed', SensitiveDataSanitizer::forLogging([
    'api_key' => 'sk-abc123...', // Logged as: ***REDACTED***
    'error' => $exception->getMessage(),
]));
```

### 5. Recursion & Resource Protection

**Auto-Discovery Guards**:
- Maximum stack depth: 5 levels
- Circular reference detection
- Automatic cycle breaking
- Deep merge protection: 10 level limit

**Resource Limits**:
- Query timeout: 30 seconds (configurable)
- Result limit: 100 rows (configurable)
- Max query complexity scoring
- Identifier length limits

### Security Testing

The package includes comprehensive security test coverage:

- **Injection Testing**: Adversarial inputs, malicious patterns, edge cases
- **Data Consistency Testing**: Partial failure scenarios, rollback verification
- **Resilience Testing**: Retry logic, circuit breaker state transitions, timeout handling
- **Sanitization Testing**: API key patterns, stack traces, nested objects

All security tests are passing. See `tests/Unit/StressTests/` for details.

## Quick Start

### Prerequisites

- PHP 8.1+
- Laravel 9.x+
- Neo4j 4.4+
- Qdrant 1.0+
- OpenAI or Anthropic API key

### Installation

```bash
# Install package
composer require condoedge/ai

# Publish config (optional)
php artisan vendor:publish --tag=ai-config
```

### Configuration

Add to `.env`:

```bash
# Neo4j
NEO4J_HOST=http://localhost:7474
NEO4J_USER=neo4j
NEO4J_PASSWORD=your-password

# Qdrant
QDRANT_HOST=http://localhost:6333

# LLM Provider (OpenAI or Anthropic)
OPENAI_API_KEY=sk-your-key
AI_LLM_PROVIDER=openai
AI_EMBEDDING_PROVIDER=openai
```

### Basic Usage

```php
// 1. Make models Nodeable
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'status'];

    public function orders() {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query) {
        return $query->where('status', 'active');
    }
}

// 2. Data auto-syncs
$customer = Customer::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'status' => 'active'
]);
// Automatically stored in Neo4j + Qdrant

// 3. Ask questions
use Condoedge\Ai\Facades\AI;

$response = AI::chat("How many active customers do we have?");
// Generates Cypher, executes, returns natural language answer
```

### Manual Configuration Override

```php
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->embedFields(['name', 'bio'])        // Override embed fields
            ->addAlias('client')                  // Add custom alias
            ->addRelationship('HAS_ORDER', 'Order', 'customer_id')
            ->disableVectorStore();               // Graph-only entity
    }
}
```

## Project Structure

```
ai/
├── config/
│   ├── ai.php              # Main package configuration
│   └── ai-patterns.php     # Query pattern definitions
├── docs/
│   ├── ARCHITECTURE.md     # Detailed technical architecture
│   └── GETTING-STARTED.md  # User guide and tutorials
├── examples/
│   └── *.php               # Working examples and demos
├── src/
│   ├── Contracts/          # Service interfaces
│   │   ├── DataIngestionServiceInterface.php
│   │   ├── GraphStoreInterface.php
│   │   ├── VectorStoreInterface.php
│   │   ├── LlmProviderInterface.php
│   │   └── ...
│   ├── Domain/             # Domain models and value objects
│   │   ├── Contracts/
│   │   │   └── Nodeable.php           # Entity interface
│   │   ├── Traits/
│   │   │   └── HasNodeableConfig.php  # Auto-sync + discovery
│   │   └── ValueObjects/
│   │       ├── GraphConfig.php
│   │       ├── VectorConfig.php
│   │       └── NodeableConfig.php
│   ├── Services/           # Core services
│   │   ├── Discovery/
│   │   │   ├── EntityAutoDiscovery.php
│   │   │   ├── CypherScopeAdapter.php
│   │   │   ├── SchemaInspector.php
│   │   │   └── ...
│   │   ├── Resilience/
│   │   │   ├── RetryPolicy.php
│   │   │   └── CircuitBreaker.php
│   │   ├── Security/
│   │   │   └── SensitiveDataSanitizer.php
│   │   ├── DataIngestionService.php
│   │   ├── ContextRetriever.php
│   │   ├── QueryGenerator.php
│   │   ├── QueryExecutor.php
│   │   └── ResponseGenerator.php
│   ├── GraphStore/         # Neo4j implementation
│   │   ├── Neo4jStore.php
│   │   └── CypherSanitizer.php
│   ├── VectorStore/        # Qdrant implementation
│   │   └── QdrantStore.php
│   ├── LlmProviders/       # LLM integrations
│   │   ├── OpenAiLlmProvider.php
│   │   └── AnthropicLlmProvider.php
│   ├── EmbeddingProviders/
│   │   ├── OpenAiEmbeddingProvider.php
│   │   └── AnthropicEmbeddingProvider.php
│   ├── Jobs/               # Queue jobs
│   │   ├── IngestEntityJob.php
│   │   ├── SyncEntityJob.php
│   │   └── RemoveEntityJob.php
│   ├── Exceptions/         # Custom exceptions
│   │   ├── CypherInjectionException.php
│   │   ├── DataConsistencyException.php
│   │   ├── CircuitBreakerOpenException.php
│   │   └── ...
│   └── Facades/
│       └── AI.php          # Main facade
├── tests/
│   ├── Unit/               # Unit tests
│   │   ├── Domain/
│   │   ├── Services/
│   │   └── StressTests/    # Security & resilience tests
│   ├── Integration/        # Integration tests
│   │   ├── EntityAutoDiscoveryTest.php
│   │   ├── DualStorageCoordinationTest.php
│   │   └── ...
│   └── Fixtures/           # Test models
└── composer.json
```

### Key Files & Purposes

**Core Services:**
- `DataIngestionService.php`: Dual-store coordination with compensating transactions
- `EntityAutoDiscovery.php`: Model introspection and config extraction
- `CypherScopeAdapter.php`: Eloquent scope to Cypher conversion
- `ContextRetriever.php`: RAG context fetching (similar queries + schema)
- `QueryGenerator.php`: LLM-powered Cypher generation with validation
- `QueryExecutor.php`: Safe query execution with timeouts
- `ResponseGenerator.php`: Natural language response generation

**Security:**
- `CypherSanitizer.php`: Injection prevention for Cypher identifiers
- `SensitiveDataSanitizer.php`: API key/credential redaction in logs
- `RetryPolicy.php`: Exponential backoff retry logic
- `CircuitBreaker.php`: Circuit breaker pattern for resilience

**Configuration:**
- `HasNodeableConfig.php`: Trait providing auto-sync and discovery
- `NodeableConfig.php`: Fluent builder for entity configuration
- `GraphConfig.php`, `VectorConfig.php`: Store-specific configurations

## Testing

### Running Tests

```bash
# All tests
composer test

# Unit tests only
composer test-unit

# Integration tests only
composer test-integration

# With coverage report
composer test-coverage
```

### Test Organization

```
tests/
├── Unit/
│   ├── Domain/              # Domain model tests
│   ├── Services/
│   │   ├── Discovery/       # Auto-discovery tests
│   │   ├── Resilience/      # Retry + circuit breaker tests
│   │   └── Security/        # Sanitization tests
│   └── StressTests/
│       ├── AdversarialSecurityTest.php      # Injection tests
│       ├── DualStorageFailureTest.php       # Consistency tests
│       └── ...
├── Integration/
│   ├── EntityAutoDiscoveryTest.php          # End-to-end discovery
│   ├── DualStorageCoordinationTest.php      # Store coordination
│   └── RealBusinessScenarioTest.php         # Business logic tests
└── Fixtures/
    └── Test*.php            # Test models
```

### Test Coverage

- Unit tests: 150+ tests
- Integration tests: 20+ tests
- Security tests: 54 tests (all passing)
  - 30 Cypher injection scenarios
  - 5 data consistency scenarios
  - 19 resilience scenarios (retry + circuit breaker)

## Documentation

- **[ARCHITECTURE.md](docs/ARCHITECTURE.md)**: Detailed technical architecture, design patterns, implementation details
- **[GETTING-STARTED.md](docs/GETTING-STARTED.md)**: User guide, configuration reference, troubleshooting
- **Examples**: Working code samples in `examples/` directory
- **Tests**: Test suite demonstrates usage patterns

## Development

### Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Make your changes
4. Run tests: `composer test`
5. Commit: `git commit -m "Add amazing feature"`
6. Push: `git push origin feature/amazing-feature`
7. Open a pull request

### Code Standards

- **PSR-12**: PHP coding standard
- **PHP 8.1+**: Type hints, readonly properties, intersection types
- **Interface-based**: Depend on interfaces, not implementations
- **Test Coverage**: All new features must have tests
- **Documentation**: Update docs for API changes

### Running Development Environment

```bash
# Install dependencies
composer install

# Run tests
composer test

# Generate coverage report
composer test-coverage
```

## Technical Decisions

### Why Neo4j + Qdrant?

**Neo4j (Graph Database):**
- Native graph storage optimized for relationship traversal
- Cypher query language provides expressive pattern matching
- Efficient multi-hop queries for complex relationships
- ACID transactions for data consistency

**Qdrant (Vector Database):**
- High-performance vector similarity search
- Metadata filtering alongside vector search
- Scales to millions of vectors
- Easy deployment (Docker, cloud)

**Why Dual-Storage?**
- Neo4j excels at relationship queries ("who is connected to whom")
- Qdrant excels at semantic search ("find similar entities")
- Together: Powerful hybrid queries combining structure and semantics

### Why Compensating Transactions vs 2PC?

**Two-Phase Commit (2PC)** requires:
- Coordinator service
- Prepare phase locks
- Complex failure recovery
- Increased latency

**Compensating Transactions** provide:
- Simpler implementation (rollback on failure)
- No distributed coordinator needed
- Lower latency (no prepare phase)
- Adequate consistency for this use case

**Trade-off**: Brief window of inconsistency on failure (acceptable for AI indexing, not financial transactions)

### Why Interface-Based Design?

**Benefits:**
- **Testability**: Easy to mock dependencies in unit tests
- **Flexibility**: Swap implementations (e.g., switch from OpenAI to Anthropic)
- **Loose Coupling**: Components depend on contracts, not concrete classes
- **Laravel Integration**: Works naturally with service container binding

**Example:**
```php
// Bind interface to implementation
$this->app->singleton(GraphStoreInterface::class, Neo4jStore::class);

// Swap to different implementation
$this->app->singleton(GraphStoreInterface::class, ArangoDbStore::class);
```

### Why Auto-Discovery vs Manual Config?

**Manual Configuration Issues:**
- Duplication between model definition and config file
- Config drift when models change
- Maintenance burden
- Error-prone

**Auto-Discovery Benefits:**
- Single source of truth (Eloquent model)
- Zero duplication
- Automatic updates when models change
- Laravel philosophy: Convention over configuration

**Escape Hatch**: Override via `nodeableConfig()` method when needed

## License

MIT License - see [LICENSE](LICENSE) file for details

## Support

- **Issues**: GitHub Issues for bug reports and feature requests
- **Documentation**: See `docs/` directory for detailed guides
- **Examples**: Working code samples in `examples/` directory

---

**Built with Laravel** | **Powered by RAG** | **Secured by Design**
