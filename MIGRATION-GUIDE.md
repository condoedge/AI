# Migration Guide: AI Wrapper → AI Facade

## Overview

The AI wrapper (`AiSystem\Wrappers\AI`) has been refactored to follow Laravel best practices. The old wrapper used anti-patterns (Service Locator, Static Singleton) that made testing difficult and bypassed Laravel's container.

**Good news:** The migration is simple! The API remains the same, you just need to change the import statement.

---

## Quick Migration

### Before (Deprecated):
```php
use AiSystem\Wrappers\AI;

AI::ingest($customer);
$context = AI::retrieveContext("Show all teams");
```

### After (Recommended):
```php
use AiSystem\Facades\AI;

AI::ingest($customer);
$context = AI::retrieveContext("Show all teams");
```

**That's it!** Just change the `use` statement from `Wrappers\AI` to `Facades\AI`.

---

## Why Migrate?

### Problems with Old Wrapper:

1. **Service Locator Anti-Pattern**
   - Manually instantiated dependencies
   - Bypassed Laravel's container
   - Hard to test (couldn't inject mocks)

2. **Static Singleton**
   - Created global mutable state
   - Made unit testing difficult
   - Violated Single Responsibility Principle

3. **Duplicated Logic**
   - Service instantiation duplicated in wrapper and provider
   - Inconsistent with container-registered services
   - Harder to maintain

### Benefits of New Facade:

1. **Testable**
   ```php
   AI::shouldReceive('ingest')
       ->once()
       ->with($customer)
       ->andReturn(['graph_stored' => true]);
   ```

2. **Follows Laravel Conventions**
   - Proper facade pattern
   - Leverages service container
   - No duplicated logic

3. **Proper Dependency Injection**
   - AiManager uses constructor injection
   - All dependencies injected from container
   - Follows SOLID principles

4. **Better for Testing**
   - Can mock facade
   - Can inject AiManager
   - Can inject individual services

---

## Migration Scenarios

### Scenario 1: Static Method Calls (90% of use cases)

**Before:**
```php
use AiSystem\Wrappers\AI;

class CustomerService
{
    public function process(Customer $customer)
    {
        AI::ingest($customer);
        $context = AI::retrieveContext("related customers");
    }
}
```

**After:**
```php
use AiSystem\Facades\AI;

class CustomerService
{
    public function process(Customer $customer)
    {
        AI::ingest($customer);
        $context = AI::retrieveContext("related customers");
    }
}
```

**Change:** Just the `use` statement.

---

### Scenario 2: Instance Usage (Rare)

**Before:**
```php
use AiSystem\Wrappers\AI;

$ai = new AI([
    'embedding_provider' => 'anthropic',
    'llm_provider' => 'openai'
]);

$ai->ingestEntity($customer);
```

**After (Option A - Facade):**
```php
use AiSystem\Facades\AI;

// Just use facade (config from ai.php)
AI::ingest($customer);
```

**After (Option B - Dependency Injection):**
```php
use AiSystem\Services\AiManager;

// Inject AiManager
public function __construct(private AiManager $ai) {}

public function process(Customer $customer)
{
    $this->ai->ingest($customer);
}
```

**After (Option C - Resolve from Container):**
```php
use AiSystem\Services\AiManager;

$ai = app(AiManager::class);
$ai->ingest($customer);
```

---

### Scenario 3: Testing

**Before (Difficult):**
```php
use AiSystem\Wrappers\AI;

// Hard to mock static methods
// Had to use AI::reset() between tests
// Couldn't easily inject mocks

class CustomerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        AI::reset(); // Had to reset singleton
        parent::tearDown();
    }

    public function test_process_customer()
    {
        // Difficult to mock AI methods
    }
}
```

**After (Easy):**
```php
use AiSystem\Facades\AI;

class CustomerServiceTest extends TestCase
{
    public function test_process_customer()
    {
        // Easy to mock facade
        AI::shouldReceive('ingest')
            ->once()
            ->with(Mockery::type(Customer::class))
            ->andReturn([
                'graph_stored' => true,
                'vector_stored' => true,
                'relationships_created' => 2,
                'errors' => []
            ]);

        $service = new CustomerService();
        $service->process(new Customer());
    }
}
```

**Or inject AiManager for better testability:**
```php
use AiSystem\Services\AiManager;

class CustomerServiceTest extends TestCase
{
    public function test_process_customer()
    {
        // Mock AiManager
        $mockAi = Mockery::mock(AiManager::class);
        $mockAi->shouldReceive('ingest')
            ->once()
            ->andReturn(['graph_stored' => true]);

        // Inject mock
        $service = new CustomerService($mockAi);
        $service->process(new Customer());
    }
}
```

---

## Complete API Reference

All methods remain the same, just accessed through new facade:

### Data Ingestion
```php
AI::ingest(Nodeable $entity): array
AI::ingestBatch(array $entities): array
AI::sync(Nodeable $entity): array
AI::remove(Nodeable $entity): bool
```

### Context Retrieval (RAG)
```php
AI::retrieveContext(string $question, array $options = []): array
AI::searchSimilar(string $question, array $options = []): array
AI::getSchema(): array
AI::getExampleEntities(array $labels, int $limit = 3): array
```

### Embeddings
```php
AI::embed(string $text): array
AI::embedBatch(array $texts): array
AI::getEmbeddingDimensions(): int
AI::getEmbeddingModel(): string
```

### LLM
```php
AI::chat(string|array $input, array $options = []): string
AI::chatJson(string|array $input, array $options = []): object|array
AI::complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
AI::stream(array $messages, callable $callback, array $options = []): void
AI::getLlmModel(): string
AI::getLlmProvider(): string
AI::getLlmMaxTokens(): int
AI::countTokens(string $text): int
```

---

## Advanced Usage Options

### Option 1: Facade (Recommended)
**Best for:** Simple, clean code that's still testable

```php
use AiSystem\Facades\AI;

AI::ingest($customer);
```

**Pros:**
- ✅ Clean, simple syntax
- ✅ Testable with `AI::shouldReceive()`
- ✅ Follows Laravel conventions

**Cons:**
- ⚠️ Static calls (some developers prefer DI)

---

### Option 2: Dependency Injection with AiManager
**Best for:** Maximum testability and SOLID compliance

```php
use AiSystem\Services\AiManager;

class CustomerService
{
    public function __construct(private AiManager $ai) {}

    public function process(Customer $customer)
    {
        $this->ai->ingest($customer);
    }
}
```

**Pros:**
- ✅ Dependencies explicit in constructor
- ✅ Easy to inject mocks in tests
- ✅ Follows pure dependency injection

**Cons:**
- ⚠️ Slightly more verbose

---

### Option 3: Direct Service Injection
**Best for:** When you only need specific services

```php
use AiSystem\Contracts\DataIngestionServiceInterface;
use AiSystem\Contracts\ContextRetrieverInterface;

class CustomerService
{
    public function __construct(
        private DataIngestionServiceInterface $ingestion,
        private ContextRetrieverInterface $context
    ) {}

    public function process(Customer $customer)
    {
        $this->ingestion->ingest($customer);
        $context = $this->context->retrieveContext("...");
    }
}
```

**Pros:**
- ✅ Maximum control
- ✅ Only inject what you need
- ✅ Very explicit dependencies

**Cons:**
- ⚠️ More verbose
- ⚠️ Need to inject multiple services

---

## Timeline

- **Now:** Old wrapper marked as `@deprecated`
- **Recommended:** Migrate at your convenience
- **Future:** Old wrapper will be removed in v2.0

---

## Need Help?

If you encounter issues during migration:

1. Check the [CODE-REVIEW-DI-ANALYSIS.md](CODE-REVIEW-DI-ANALYSIS.md) for detailed explanation
2. Review [USAGE-EXAMPLES.md](USAGE-EXAMPLES.md) for comprehensive examples
3. Look at tests in `tests/Unit/Services/` for working examples

---

## Summary

**Simple Migration:** Change `use AiSystem\Wrappers\AI;` to `use AiSystem\Facades\AI;`

**Why:**
- ✅ Better testability
- ✅ Follows Laravel best practices
- ✅ Proper dependency injection
- ✅ No anti-patterns

**Result:** Same simple API, but now with Laravel best practices! 🚀
