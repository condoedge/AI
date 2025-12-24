# Extending the Package

This guide shows how to customize the AI system when defaults don't fit your needs. Common extension points include entity configuration, query patterns, prompt building, and provider swapping.

## Quick Reference

| Extension Point | Use Case |
|----------------|----------|
| [Override Auto-Discovery](#1-override-auto-discovery) | Custom labels, relationships, scopes |
| [Custom Query Patterns](#2-add-custom-query-patterns) | Domain-specific query templates |
| [Prompt Builders](#3-extend-prompt-builders) | Custom context, rules, sections |
| [Swap Providers](#4-swap-providers) | Use different LLM/vector/graph stores |
| [Custom Chat Service](#custom-chat-service) | Customize how chat processes questions |

## 1. Override Auto-Discovery

Use the `nodeableConfig()` hook on your model when you need explicit control over labels, relationships, or embed fields.

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
            ->collection('clients')
            ->embedFields(['name', 'bio', 'notes'])
            ->addRelationship('HAS_PLAN', 'Plan', 'plan_id')
            ->addScope('premium', [
                'concept' => 'High-value subscription',
                'cypher_pattern' => 'n.status = "active" AND n.monthly_spend > 500',
            ]);
    }
}
```

## 2. Add Custom Query Patterns

Define reusable prompts + Cypher templates inside `config/ai-patterns.php`. Patterns serve multiple purposes:

- **Few-shot learning**: LLM sees patterns in the prompt and learns correct Cypher syntax
- **High-confidence shortcuts**: Close pattern matches can skip full LLM processing
- **Business logic templates**: Encode common query structures for your domain

### Built-in Pattern Types

| Pattern | Purpose | Example |
|---------|---------|---------|
| `property_filter` | Simple filtering | "Find active customers" |
| `property_range` | Numeric ranges | "Orders between $100-$500" |
| `relationship_traversal` | Graph path queries | "People who volunteer" |
| `temporal_filter` | Date-based queries | "Recent customers (last 30 days)" |
| `entity_with_relationship` | Existence checks | "Customers with orders" |
| `entity_without_relationship` | Absence checks | "People without teams" |
| `multi_hop_traversal` | Complex paths | "Managers of marketing teams" |
| `composed` | Combined patterns | "Active volunteers on Marketing" |

### Adding Custom Patterns

```php
// config/ai-patterns.php
return [
    'goal_vs_actual' => [
        'description' => 'Compare a target metric against actuals',
        'semantic_template' => 'Compare {label} {dimension} goals vs actuals',
        'parameters' => [
            'label' => 'Entity label (e.g., Team, Project)',
            'dimension' => 'Metric to compare (e.g., revenue, headcount)',
            'value' => 'Specific value to filter (optional)',
        ],
        'examples' => [
            [
                'description' => 'Compare team revenue goals',
                'params' => [
                    'label' => 'Team',
                    'dimension' => 'revenue',
                ],
            ],
        ],
    ],

    'recent_activity' => [
        'description' => 'Find entities with recent activity',
        'semantic_template' => 'Find {entity} with activity in last {days} days',
        'parameters' => [
            'entity' => 'Entity label',
            'activity_relationship' => 'Relationship type indicating activity',
            'days' => 'Number of days to look back',
        ],
        'examples' => [
            [
                'description' => 'Find recently active customers',
                'params' => [
                    'entity' => 'Customer',
                    'activity_relationship' => 'PLACED_ORDER',
                    'days' => 30,
                ],
            ],
        ],
    ],
];
```

Patterns with high-confidence matches skip the LLM entirely, reducing latency and token spend.

## 3. Extend Prompt Builders

Both the Query Generator and Response Generator use extensible section-based pipelines. You can add, remove, replace, or extend sections to customize how prompts are built.

### SemanticPromptBuilder (Query Generation)

The `SemanticPromptBuilder` constructs prompts for Cypher query generation using prioritized sections:

| Section | Priority | Purpose |
|---------|----------|---------|
| `project_context` | 10 | Project name, description, domain, business rules |
| `generic_context` | 15 | Current date/time |
| `schema` | 20 | Graph schema (labels, relationships, properties) |
| `relationships` | 30 | Entity relationships with exact directions |
| `example_entities` | 40 | Sample data showing actual types/formats |
| `similar_queries` | 50 | RAG: similar past queries for few-shot learning |
| `detected_entities` | 60 | Entities detected in user's question |
| `detected_scopes` | 65 | Business concepts detected in question |
| `pattern_library` | 70 | Available query patterns |
| `query_rules` | 75 | Query generation rules |
| `question` | 80 | User's question |
| `task_instructions` | 90 | Final task instructions |

#### Global Extensions (ServiceProvider)

Apply extensions to all new builder instances in your `AppServiceProvider`:

```php
use Condoedge\Ai\Services\SemanticPromptBuilder;

public function boot()
{
    // Set project context globally
    SemanticPromptBuilder::extendBuild(function($builder) {
        $builder->setProjectContext([
            'name' => 'My CRM',
            'description' => 'Customer relationship management system',
            'domain' => 'Sales',
            'business_rules' => [
                'All dates are stored as ISO strings (YYYY-MM-DD)',
                'Active customers have status = "active"',
                'Amounts are stored in cents (divide by 100 for dollars)',
            ],
        ]);

        // Add custom query rules
        $builder->addQueryRule('PERFORMANCE', 'Always use indexed properties in WHERE');
        $builder->addQueryRule('BUSINESS', 'Never return deleted records (deleted_at IS NULL)');
    });
}
```

#### Instance-Level Extensions

```php
$builder = app(SemanticPromptBuilder::class);

// Add a custom section
$builder->addSection(new DomainTermsSection());

// Remove a section you don't need
$builder->removeSection('similar_queries');

// Replace a section with your own
$builder->replaceSection('project_context', new MyProjectContextSection());

// Extend with callbacks (insert content after a section)
$builder->extendAfter('schema', function($question, $context, $options) {
    return "\n=== IMPORTANT NOTES ===\n\n" .
           "- All monetary values are in cents\n" .
           "- Dates use ISO format: 'YYYY-MM-DD'\n\n";
});

// Extend before a section
$builder->extendBefore('question', function($question, $context, $options) {
    return "=== HINTS ===\n\nLook for date patterns in the question.\n\n";
});
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

// Global extension
ResponseGenerator::extendBuild(function($generator) {
    $generator->setSystemPrompt(
        "You are a helpful data analyst for Acme Corp. " .
        "Always be friendly and explain results in business terms.\n\n"
    );

    $generator->addGuideline('Always mention the date range when relevant');
    $generator->addGuideline('Convert cents to dollars in explanations');
    $generator->setMaxDataItems(15);
});

// Instance-level
$generator = app(ResponseGenerator::class);
$generator->removeSection('statistics'); // Hide execution stats from users
$generator->extendAfter('data', function($context, $options) {
    $count = count($context['data'] ?? []);
    return "\n(Showing {$count} of potentially more results)\n\n";
});
```

## 4. Swap Providers

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

## 5. Hook Into Laravel Pipelines

- **Queues:** Set `AI_AUTO_SYNC_QUEUE=true` and specify `AI_AUTO_SYNC_QUEUE_CONNECTION/NAME`.
- **Events:** Listen to `AI::ingested` or wrap ingestion inside domain events to trigger downstream jobs.
- **Commands:** Build custom artisan commands on top of `AiManager` for batch ingestion or re-embedding workflows.

## 6. Observe and Instrument

- Wrap ingestion calls with Prometheus counters or OpenTelemetry spans.
- Log `AI::retrieveContext()` payloads when debugging question quality (be mindful of PII).
- Mirror key docs into `/ai-docs` so operators understand what changed.

## 7. Testing Customizations

- Mock the `AI` facade or injected interfaces to isolate your code paths.
- Use `tests/Fixtures` as inspiration for creating fake Nodeable models.
- Run the [Testing Playbook](/docs/{{version}}/usage/testing) after swapping providers or overriding configs.

## Custom Chat Service

The Chat UI uses `AiChatServiceInterface` for processing questions. Create a custom implementation for specialized behavior:

### 1. Implement the Interface

```php
namespace App\Services;

use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Services\Chat\AiChatMessage;
use Condoedge\Ai\Services\Chat\AiChatResponseData;

class CustomChatService implements AiChatServiceInterface
{
    public function ask(string $question, array $options = []): AiChatMessage
    {
        // Your custom AI logic
        $answer = $this->processQuestion($question, $options);

        // Return with optional rich data
        $responseData = AiChatResponseData::text()
            ->withSuggestions(['Follow-up 1', 'Follow-up 2']);

        return AiChatMessage::assistant($answer, $responseData);
    }

    public function askWithHistory(
        string $question,
        array $history,
        array $options = []
    ): AiChatMessage {
        // Use conversation history for context
        return $this->ask($question, array_merge($options, [
            'history' => $history
        ]));
    }

    public function getSuggestions(string $question, string $response): array
    {
        return ['Tell me more', 'Show details'];
    }

    public function getExampleQuestions(): array
    {
        return ['What can you help with?', 'Show me a summary'];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    protected function processQuestion(string $question, array $options): string
    {
        // Your custom logic here
        // Could call a different AI, use RAG, query databases, etc.
        return "Response to: {$question}";
    }
}
```

### 2. Register in Service Provider

```php
// app/Providers/AppServiceProvider.php
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use App\Services\CustomChatService;

public function register()
{
    $this->app->bind(AiChatServiceInterface::class, CustomChatService::class);
}
```

### 3. Use Rich Response Types

The chat supports structured response formats:

```php
use Condoedge\Ai\Services\Chat\AiChatResponseData;

// Table response
$data = AiChatResponseData::table(
    headers: ['Name', 'Email', 'Orders'],
    rows: [['John', 'john@example.com', 15]]
);

// Metric response
$data = AiChatResponseData::metric(
    label: 'Total Revenue',
    value: '$125,430',
    trend: '+12%'
);

// List response
$data = AiChatResponseData::list([
    ['title' => 'Order #1234', 'description' => 'Pending - $150'],
]);

// With suggestions and actions
$data = AiChatResponseData::text()
    ->withSuggestions(['Show more', 'Export'])
    ->withActions([
        ['label' => 'View Details', 'url' => '/details/123'],
    ]);

return AiChatMessage::assistant($answer, $data);
```

See: [Chat UI Components](/docs/{{version}}/chat/chat-ui) for full documentation.

---

Ready to touch the internals? Continue with the [Core Components Guide](/docs/{{version}}/internals/components).
