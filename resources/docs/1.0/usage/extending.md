# Extending the Package

This guide shows how to customize the AI system when defaults don't fit your needs. Common extension points include entity configuration, prompt building, and provider swapping.

## Quick Reference

| Extension Point | Use Case |
|----------------|----------|
| [Override Auto-Discovery](#1-override-auto-discovery) | Custom labels, relationships, scopes |
| [Prompt Builders](#2-extend-prompt-builders) | Custom context, rules, sections |
| [Swap Providers](#3-swap-providers) | Use different LLM/vector/graph stores |
| [Custom Chat Service](#custom-chat-service) | Customize how chat processes questions |

## 1. Override Auto-Discovery

Use the `nodeableConfig()` hook on your model when you need explicit control over labels, relationships, or scopes.

```php
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->label('Client')
            ->addRelationship('HAS_PLAN', 'Plan', 'plan_id')
            ->addScope('premium', [
                'concept' => 'High-value subscription',
                'cypher_pattern' => 'n.status = "active" AND n.monthly_spend > 500',
            ]);
    }
}
```

## 2. Extend Prompt Builders

Both the Query Generator and Response Generator use extensible section-based pipelines. You can add, remove, or reorder sections to customize how prompts are built.

### SemanticPromptBuilder (Query Generation)

The `SemanticPromptBuilder` constructs prompts for Cypher query generation using prioritized sections:

| Section | Priority | Purpose |
|---------|----------|---------|
| `project_context` | 10 | Project name, description, domain, business rules |
| `generic_context` | 15 | Current date/time |
| `schema` | 20 | Schema cards: one per selected label (typed properties, enum legends, live directed edges, fk hints) |
| `similar_queries` | 50 | RAG: similar past queries for few-shot learning |
| `detected_scopes` | 65 | Business concepts detected in question |
| `query_rules` | 75 | Query generation rules |
| `question` | 80 | User's question |
| `task_instructions` | 90 | Final task instructions |

#### Application-Wide Customization (config)

Project context and the section pipeline are configured in your published `config/ai.php`, so every builder instance picks them up:

```php
// config/ai.php
'project' => [
    'name' => 'My CRM',
    'description' => 'Customer relationship management system',
    'domain' => 'Sales',
    'business_rules' => [
        'All dates are stored as ISO strings (YYYY-MM-DD)',
        'Active customers have status = "active"',
        'Amounts are stored in cents (divide by 100 for dollars)',
    ],
],

// Add your own section classes to the pipeline (class-strings, or closures
// that receive the builder and return a section instance)
'query_generator_sections' => [
    // ... default sections ...
    \App\Ai\PromptSections\DomainTermsSection::class,
],
```

#### Instance-Level Extensions

```php
$builder = app(SemanticPromptBuilder::class);

// Add a custom section
$builder->addModule(new DomainTermsSection());

// Remove a section you don't need
$builder->removeModule('similar_queries');

// Replace a section with your own (remove, then add)
$builder->removeModule('project_context');
$builder->addModule(new MyProjectContextSection());

// Add custom query rules
$builder->addQueryRule('PERFORMANCE', 'Always use indexed properties in WHERE');
$builder->addQueryRule('BUSINESS', 'Never return deleted records (deleted_at IS NULL)');
```

#### Creating Custom Sections

Implement `PromptSectionInterface` or extend `BasePromptSection`:

```php
use Condoedge\Ai\Contracts\PromptSectionInterface;
use Condoedge\Ai\Services\PromptSections\BasePromptSection;

class DomainTermsSection extends BasePromptSection
{
    protected string $name = 'domain_terms';
    protected int $priority = 25; // After schema (20), before relationships (30)

    public function format(string $question, array $context, array $options = []): string
    {
        return $this->header('DOMAIN TERMINOLOGY') .
               "When the user says:\n" .
               "- 'Client' or 'Customer' → use label :Customer\n" .
               "- 'Active' → means status = 'active' OR enabled = true\n" .
               "- 'Revenue' → use total_amount property (stored in cents)\n\n";
    }

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        // Only include when question mentions ambiguous terms
        $terms = ['client', 'active', 'revenue'];
        $lowerQuestion = strtolower($question);

        foreach ($terms as $term) {
            if (str_contains($lowerQuestion, $term)) {
                return true;
            }
        }
        return false;
    }
}
```

### ResponseGenerator (Response Generation)

The `ResponseGenerator` uses the same extensible pattern:

| Section | Priority | Purpose |
|---------|----------|---------|
| `system` | 10 | System prompt (LLM role) |
| `project_context` | 20 | Project context for explanations |
| `question` | 30 | Original user question |
| `query` | 40 | Executed Cypher query |
| `data` | 50 | Query results |
| `statistics` | 60 | Execution statistics |
| `guidelines` | 70 | Response guidelines (style, format) |
| `task` | 80 | Final task instruction |

#### Extending ResponseGenerator

```php
use Condoedge\Ai\Services\ResponseGenerator;

// Application-wide: add/remove section classes in config/ai.php
// 'response_generator_sections' => [ ... ],

// Instance-level
$generator = app(ResponseGenerator::class);
$generator->setSystemPrompt(
    "You are a helpful data analyst for Acme Corp. " .
    "Always be friendly and explain results in business terms.\n\n"
);
$generator->addGuideline('Always mention the date range when relevant');
$generator->addGuideline('Convert cents to dollars in explanations');
$generator->setMaxDataItems(15);
$generator->removeModule('entity_actions'); // Drop the entity-action link hints
```

## 3. Swap Providers

### Vector Store

1. Implement `VectorStoreInterface`.
2. Bind it in a service provider:
   ```php
   $this->app->singleton(VectorStoreInterface::class, PineconeStore::class);
   ```
3. Update `config/ai.php` (`'vector' => ['default' => 'pinecone']`).

### LLM / Embedding Providers

- Implement `LlmProviderInterface` or `EmbeddingProviderInterface`.
- Register the binding and add configuration arrays under the respective sections in `config/ai.php`.
- Flip `.env` keys (`AI_LLM_PROVIDER=custom`).

## 4. Hook Into Laravel Pipelines

- **Queues:** Set `AI_AUTO_SYNC_QUEUE=true` and specify `AI_AUTO_SYNC_QUEUE_CONNECTION/NAME`.
- **Events:** Listen to `AI::ingested` or wrap ingestion inside domain events to trigger downstream jobs.
- **Commands:** Build custom artisan commands on top of `AiManager` for batch ingestion or re-embedding workflows.

## 5. Observe and Instrument

- Wrap ingestion calls with Prometheus counters or OpenTelemetry spans.
- Log `AI::retrieveContext()` payloads when debugging question quality (be mindful of PII).
- Mirror key docs into `/ai-docs` so operators understand what changed.

## 6. Testing Customizations

- Mock the `AI` facade or injected interfaces to isolate your code paths.
- Use `tests/Fixtures` as inspiration for creating fake Nodeable models.
- Run the [Testing Playbook](/docs/{{version}}/usage/testing) after swapping providers or overriding configs.

## Custom Chat Service

Chat turns are processed by `AskQuestionUseCase` (via `ChatPresenter`). `AiChatServiceInterface` is only the availability seam the Chat UI uses for its online badge, so it has a single method, `isAvailable()`:

### 1. Implement the Interface

```php
namespace App\Services;

use Condoedge\Ai\Services\Chat\AiChatServiceInterface;

class CustomChatService implements AiChatServiceInterface
{
    public function isAvailable(): bool
    {
        return true; // e.g. check API keys / store connectivity
    }
}
```

### 2. Register the Service

Point the config at your class:

```php
// config/ai.php
'chat' => [
    'service' => \App\Services\CustomChatService::class,
    // ...
],
```

Or bind the interface in a service provider:

```php
// app/Providers/AppServiceProvider.php
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use App\Services\CustomChatService;

public function register()
{
    $this->app->bind(AiChatServiceInterface::class, CustomChatService::class);
}
```

See: [Chat UI Components](/docs/{{version}}/chat/chat-ui) for full documentation.

---

Ready to touch the internals? Continue with the [Core Components Guide](/docs/{{version}}/internals/components).
