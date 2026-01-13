# Custom Prompt Sections

Customize the prompts sent to the LLM for query and response generation.

---

## Overview

The prompt builder uses modular sections to construct prompts. The system has two distinct pipelines:

1. **Query Generator Pipeline** (17 sections) - Builds prompts for generating Cypher queries from natural language
2. **Response Generator Pipeline** (12 sections) - Builds prompts for generating natural language responses from query results

Each section handles a specific part of the prompt and can be:
- Added dynamically via `SemanticPromptBuilder::addSection()` or `ResponseGenerator::addSection()`
- Extended via `extendBuild()`
- Removed or replaced at runtime
- Conditionally included based on context

---

## Section Interfaces

### PromptSectionInterface

All query prompt sections implement `PromptSectionInterface`:

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
     * Get the section priority (lower = earlier in prompt).
     */
    public function getPriority(): int;

    /**
     * Format the section content.
     *
     * @param string $question The user's natural language question
     * @param array $context Full context array with schema, entities, etc.
     * @param array $options Additional options (allowWrite, etc.)
     * @return string The formatted section content
     */
    public function format(string $question, array $context, array $options = []): string;

    /**
     * Check if section should be included.
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool;
}
```

### ResponseSectionInterface

All response sections implement `ResponseSectionInterface`:

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
     * Get the section priority (lower = earlier in prompt).
     */
    public function getPriority(): int;

    /**
     * Format the section content.
     *
     * @param array $context Context array with question, data, stats, etc.
     * @param array $options Additional options (style, format, etc.)
     * @return string The formatted section content
     */
    public function format(array $context, array $options = []): string;

    /**
     * Check if section should be included.
     */
    public function shouldInclude(array $context, array $options = []): bool;
}
```

---

## Creating a Custom Section

### Step 1: Create Section Class

```php
<?php

namespace App\Services\Ai\PromptSections;

use Condoedge\Ai\Services\PromptSections\BasePromptSection;

class BusinessRulesSection extends BasePromptSection
{
    protected string $name = 'business_rules';
    protected int $priority = 65; // After schema, before guidelines

    public function format(string $question, array $context, array $options = []): string
    {
        $rules = config('ai.project.business_rules', []);

        if (empty($rules)) {
            return '';
        }

        $output = "## Business Rules\n\n";
        $output .= "Important business logic to consider:\n\n";

        foreach ($rules as $rule) {
            $output .= "- {$rule}\n";
        }

        return $output . "\n";
    }

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        return !empty(config('ai.project.business_rules'));
    }
}
```

### Step 2: Register Section

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use App\Services\Ai\PromptSections\BusinessRulesSection;

class AiExtensionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Add custom section to prompt builder
        $this->app->resolving(SemanticPromptBuilder::class, function ($builder) {
            $builder->addSection(new BusinessRulesSection());
        });
    }
}
```

### Step 3: Configure Rules

```php
// config/ai.php
'project' => [
    'business_rules' => [
        'Active customers have status = "active"',
        'Premium tier includes gold and platinum customers',
        'Orders over $1000 require manager approval',
        'Deleted records are soft-deleted (deleted_at is set)',
    ],
],
```

---

## Configuration

Sections are configured in `config/ai.php`:

### Query Generator Sections

```php
'query_generator_sections' => [
    \Condoedge\Ai\Services\PromptSections\ProjectContextSection::class,
    \Condoedge\Ai\Services\PromptSections\GenericContextSection::class,
    \Condoedge\Ai\Services\PromptSections\CurrentUserContextSection::class,
    \Condoedge\Ai\Services\PromptSections\SchemaSection::class,
    \Condoedge\Ai\Services\PromptSections\RelationshipsSection::class,
    \Condoedge\Ai\Services\PromptSections\ExampleEntitiesSection::class,
    \Condoedge\Ai\Services\PromptSections\FileContextSection::class,
    \Condoedge\Ai\Services\PromptSections\SimilarQueriesSection::class,
    \Condoedge\Ai\Services\PromptSections\ConversationContextSection::class,
    \Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection::class,
    \Condoedge\Ai\Services\PromptSections\DetectedEntitiesSection::class,
    \Condoedge\Ai\Services\PromptSections\DetectedScopesSection::class,
    fn(SemanticPromptBuilder $promptBuilder) => new \Condoedge\Ai\Services\PromptSections\PatternLibrarySection($promptBuilder->getPatternLibrary()),
    \Condoedge\Ai\Services\PromptSections\QueryRulesSection::class,
    \Condoedge\Ai\Services\PromptSections\QuestionSection::class,
    \Condoedge\Ai\Services\PromptSections\TaskInstructionsSection::class,
],
```

### Response Generator Sections

```php
'response_generator_sections' => [
    \Condoedge\Ai\Services\ResponseSections\SystemPromptSection::class,
    \Condoedge\Ai\Services\ResponseSections\PrivacyAndSecurityGuidelinesSection::class,
    \Condoedge\Ai\Services\ResponseSections\ResponseProjectContextSection::class,
    \Condoedge\Ai\Services\ResponseSections\OriginalQuestionSection::class,
    \Condoedge\Ai\Services\ResponseSections\QueryInfoSection::class,
    \Condoedge\Ai\Services\ResponseSections\FileContextSection::class,
    \Condoedge\Ai\Services\ResponseSections\ResponseConversationContextSection::class,
    \Condoedge\Ai\Services\ResponseSections\ResultsDataSection::class,
    \Condoedge\Ai\Services\ResponseSections\ResponseEntityActionsSection::class,
    \Condoedge\Ai\Services\ResponseSections\StatisticsSection::class,
    \Condoedge\Ai\Services\ResponseSections\GuidelinesSection::class,
    \Condoedge\Ai\Services\ResponseSections\ResponseTaskSection::class,
]
```

You can override these arrays to add, remove, or reorder sections. Sections with factory functions (closures) receive the builder instance for dependency injection.

---

## Built-in Query Generator Sections

The query generator pipeline builds prompts for converting natural language questions into Cypher queries:

| Priority | Section | Name | Purpose |
|----------|---------|------|---------|
| 10 | ProjectContextSection | `project_context` | Adds project-level context (name, description, domain, business rules) to help the LLM understand the business domain |
| 15 | GenericContextSection | `generic_context` | Adds current date/time context. Only included when question references time-related keywords |
| 17 | CurrentUserContextSection | `current_user_context` | Adds authenticated user and team context (name, email, ID, team info) |
| 20 | SchemaSection | `schema` | Provides Neo4j graph schema (labels, relationships, properties) |
| 30 | RelationshipsSection | `relationships` | Shows entity relationships with EXACT directions. Critical for correct query generation |
| 40 | ExampleEntitiesSection | `example_entities` | Shows actual data from Neo4j to help LLM understand data types, date formats, and property naming |
| 45 | FileContextSection | `file_context` | Provides file context from vector search with citation instructions ([1], [2] markers) |
| 50 | SimilarQueriesSection | `similar_queries` | Shows similar past queries for few-shot learning |
| 55 | ConversationContextSection | `conversation_context` | Adds conversation history for follow-up questions and pronoun resolution |
| 58 | EntityActionAwarenessSection | `entity_action_awareness` | Informs LLM that terms like "profile link" are UI actions, not database fields |
| 60 | DetectedEntitiesSection | `detected_entities` | Shows entities detected in the user's question with metadata and properties |
| 65 | DetectedScopesSection | `detected_scopes` | Shows business concepts (scopes) detected with semantic specifications |
| 70 | PatternLibrarySection | `pattern_library` | Shows available reusable query patterns and templates |
| 75 | QueryRulesSection | `query_rules` | Provides rules for query generation (schema compliance, data types, best practices) |
| 80 | QuestionSection | `question` | Adds the user's question to the prompt |
| 90 | TaskInstructionsSection | `task_instructions` | Final task instructions telling LLM to generate Cypher query |

---

## Built-in Response Generator Sections

The response generator pipeline builds prompts for converting query results into natural language responses:

| Priority | Section | Name | Purpose |
|----------|---------|------|---------|
| 10 | SystemPromptSection | `system` | Sets the LLM's role as a data analyst who explains query results |
| 20 | ResponseProjectContextSection | `project_context` | Adds project context to help explain results in business domain terms |
| 30 | OriginalQuestionSection | `question` | Shows the user's original question for context |
| 40 | QueryInfoSection | `query` | Shows the Cypher query that was executed |
| 45 | FileContextSection | `file_context` | Adds relevant file content with citation markers for the LLM to reference |
| 45 | ResponseConversationContextSection | `conversation_context` | Adds conversation context including file references for follow-up questions |
| 50 | ResultsDataSection | `data` | Shows the query results data (with configurable max items) |
| 55 | ResponseEntityActionsSection | `entity_actions` | Informs AI about available entity actions and how to format action links |
| 60 | StatisticsSection | `statistics` | Shows execution statistics (time, row count) |
| 70 | GuidelinesSection | `guidelines` | Provides style-based guidelines (minimal, concise, friendly, detailed, technical) |
| 80 | ResponseTaskSection | `task` | Final instruction telling LLM to generate the response |
| 1000 | PrivacyAndSecurityGuidelinesSection | `security_restrictions` | Security and privacy guidelines to prevent data leakage (placed last for compliance) |

---

## Section Priority

Sections are ordered by priority (lower = earlier in prompt). Standard priority ranges:

### Query Generator Priorities
- **10-19**: Project and generic context
- **20-39**: Schema and structure
- **40-49**: Examples and file context
- **50-59**: Similar queries and conversation
- **60-69**: Detected entities and scopes
- **70-79**: Patterns and rules
- **80-89**: Question
- **90+**: Task instructions

### Response Generator Priorities
- **10-19**: System prompt
- **20-29**: Project context
- **30-39**: Question
- **40-49**: Query info and file context
- **50-59**: Data and actions
- **60-69**: Statistics
- **70-79**: Guidelines
- **80+**: Task instructions
- **1000**: Security (always last)

### Custom Priority

```php
class EarlySection extends BasePromptSection
{
    protected int $priority = 5; // Near the start of prompt
}

class LateSection extends BasePromptSection
{
    protected int $priority = 85; // Near the end, before task instructions
}
```

---

## Conditional Sections

Include sections based on context:

```php
class DebugSection extends BasePromptSection
{
    protected string $name = 'debug';
    protected int $priority = 5;

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        // Only include in development or when explicitly requested
        return app()->environment('local')
            || ($options['debug'] ?? false);
    }

    public function format(string $question, array $context, array $options = []): string
    {
        return "## Debug Mode\n\n" .
               "Include the generated Cypher query in your response.\n" .
               "Explain your reasoning step by step.\n\n";
    }
}
```

---

## Context Data

### Query Generator Context

```php
public function format(string $question, array $context, array $options = []): string
{
    // Available in $context:
    $schema = $context['graph_schema'] ?? [];           // Neo4j schema info
    $entities = $context['relevant_entities'] ?? [];    // Example entities
    $similar = $context['similar_queries'] ?? [];       // Similar past queries
    $entityMeta = $context['entity_metadata'] ?? [];    // Detected entities/scopes
    $fileContext = $context['file_context'] ?? [];      // File search results
    $conversation = $context['conversation_context'] ?? []; // Conversation history

    // Available in $options:
    $allowWrite = $options['allowWrite'] ?? false;      // Allow write operations
}
```

### Response Generator Context

```php
public function format(array $context, array $options = []): string
{
    // Available in $context:
    $question = $context['question'] ?? '';        // User's question
    $cypher = $context['cypher'] ?? '';           // Executed query
    $data = $context['data'] ?? [];               // Query results
    $stats = $context['stats'] ?? [];             // Execution statistics
    $fileContext = $context['file_context'] ?? []; // File references
    $conversation = $context['conversation_context'] ?? []; // Conversation history

    // Available in $options:
    $style = $options['style'] ?? 'friendly';     // Response style
    $format = $options['format'] ?? 'text';       // Output format
    $maxLength = $options['max_length'] ?? 100;   // Max response length
}
```

---

## Creating Custom Response Sections

```php
<?php

namespace App\Services\Ai\ResponseSections;

use Condoedge\Ai\Services\ResponseSections\BaseResponseSection;

class InsightsSection extends BaseResponseSection
{
    protected string $name = 'insights';
    protected int $priority = 65; // After statistics, before guidelines

    public function format(array $context, array $options = []): string
    {
        if (!config('ai.response_generation.include_insights', true)) {
            return '';
        }

        return "## Additional Instructions\n\n" .
               "If the data reveals interesting patterns or anomalies, " .
               "briefly mention them. For example:\n" .
               "- Trends (increasing/decreasing)\n" .
               "- Outliers or unusual values\n" .
               "- Comparisons to typical values\n\n";
    }

    public function shouldInclude(array $context, array $options = []): bool
    {
        return config('ai.response_generation.include_insights', true);
    }
}
```

---

## Example: Domain-Specific Section

```php
<?php

namespace App\Services\Ai\PromptSections;

use Condoedge\Ai\Services\PromptSections\BasePromptSection;

class EcommerceContextSection extends BasePromptSection
{
    protected string $name = 'ecommerce_context';
    protected int $priority = 85;

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        return config('ai.project.domain') === 'e-commerce';
    }

    public function format(string $question, array $context, array $options = []): string
    {
        return <<<PROMPT
## E-Commerce Domain Context

Key relationships in this e-commerce system:
- Customers place Orders
- Orders contain OrderItems
- OrderItems reference Products
- Products belong to Categories
- Products have Inventory records

Common metrics:
- Revenue = sum of order totals
- AOV (Average Order Value) = revenue / order count
- Customer Lifetime Value = sum of customer's order totals

Status values:
- Order status: pending, processing, shipped, delivered, cancelled
- Customer status: active, inactive, suspended
- Product status: active, out_of_stock, discontinued

PROMPT;
    }
}
```

---

## Modifying Built-in Sections

### Extend Existing Section

```php
<?php

namespace App\Services\Ai\PromptSections;

use Condoedge\Ai\Services\PromptSections\QueryRulesSection;

class CustomQueryRulesSection extends QueryRulesSection
{
    public function format(string $question, array $context, array $options = []): string
    {
        // Get default rules
        $output = parent::format($question, $context, $options);

        // Add custom rules
        $this->addRule('COMPANY_SPECIFIC', 'Always filter by tenant_id');
        $this->addRule('COMPANY_SPECIFIC', 'Exclude archived records by default');

        return $output;
    }
}
```

### Replace Section

```php
// In service provider
$this->app->resolving(SemanticPromptBuilder::class, function ($builder) {
    // Remove default section
    $builder->removeSection('query_rules');

    // Add custom replacement
    $builder->addSection(new CustomQueryRulesSection());
});
```

---

## Built-in Section Features

### GuidelinesSection Response Styles

The `GuidelinesSection` supports multiple response styles:

```php
// Available styles:
'minimal'   // Just the answer, nothing else (e.g., "Admin System" or "42")
'concise'   // One sentence answer
'friendly'  // Natural conversation style, 2-3 sentences max (default)
'detailed'  // Full explanation with context
'technical' // Includes query details and execution info
```

Configure via `config/ai.php`:
```php
'response_generation' => [
    'default_style' => 'friendly',
    'hide_technical_details' => true,
    'hide_execution_stats' => true,
    'hide_project_info' => true,
]
```

### QueryRulesSection Custom Rules

```php
$rulesSection = new QueryRulesSection();
$rulesSection->addRule('CUSTOM', 'Always use DISTINCT for person queries');
$rulesSection->addRule('PERFORMANCE', 'Use LIMIT 50 for relationship queries');
```

### SystemPromptSection Custom Prompt

```php
$systemSection = new SystemPromptSection();
$systemSection->setPrompt('You are a financial analyst specializing in investment portfolios.');
```

---

## Testing Sections

```php
use App\Services\Ai\PromptSections\BusinessRulesSection;

public function test_business_rules_section()
{
    config(['ai.project.business_rules' => [
        'Rule 1',
        'Rule 2',
    ]]);

    $section = new BusinessRulesSection();

    $this->assertTrue($section->shouldInclude('test question', [], []));

    $output = $section->format('test question', [], []);
    $this->assertStringContainsString('Rule 1', $output);
    $this->assertStringContainsString('Rule 2', $output);
}

public function test_conditional_inclusion()
{
    // GenericContextSection only includes when question has time keywords
    $section = new GenericContextSection();

    $this->assertFalse($section->shouldInclude('show all users', [], []));
    $this->assertTrue($section->shouldInclude('show users from last week', [], []));
    $this->assertTrue($section->shouldInclude('what happened yesterday', [], []));
}
```

---

## Related Documentation

- [Response Styles](/docs/{{version}}/configuration/response-styles) - Response configuration
- [Custom LLM Providers](/docs/{{version}}/extending/llm-providers) - LLM integration
- [Overview](/docs/{{version}}/usage/extending) - Extension overview
