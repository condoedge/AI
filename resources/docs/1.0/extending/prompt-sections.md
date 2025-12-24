# Custom Prompt Sections

Customize the prompts sent to the LLM for query and response generation.

---

## Overview

The prompt builder uses modular sections to construct prompts. Each section handles a specific part:

- **SystemSection**: System instructions and role
- **SchemaSection**: Database schema context
- **ExamplesSection**: Example queries
- **GuidelinesSection**: Response formatting guidelines
- **QuerySection**: The user's question

You can create custom sections to add domain-specific context or modify existing behavior.

---

## Section Interface

All prompt sections implement `PromptSectionInterface`:

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
     * Get the section priority (higher = earlier in prompt).
     */
    public function getPriority(): int;

    /**
     * Format the section content.
     *
     * @param array $context Available context data
     * @param array $options Additional options
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

    public function format(array $context, array $options = []): string
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

    public function shouldInclude(array $context, array $options = []): bool
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

## Built-in Sections

### SystemSection (Priority: 100)

Sets up the AI's role and capabilities:

```php
class SystemSection extends BasePromptSection
{
    protected string $name = 'system';
    protected int $priority = 100;

    public function format(array $context, array $options = []): string
    {
        return "You are an AI assistant that generates Cypher queries for Neo4j...\n";
    }
}
```

### SchemaSection (Priority: 80)

Provides database schema information:

```php
class SchemaSection extends BasePromptSection
{
    protected string $name = 'schema';
    protected int $priority = 80;

    public function format(array $context, array $options = []): string
    {
        // Formats entity labels, properties, relationships
        return "## Database Schema\n\n...";
    }
}
```

### ScopesSection (Priority: 75)

Lists available query scopes:

```php
class ScopesSection extends BasePromptSection
{
    protected string $name = 'scopes';
    protected int $priority = 75;

    public function format(array $context, array $options = []): string
    {
        // Formats scope definitions and examples
        return "## Available Scopes\n\n...";
    }
}
```

### ExamplesSection (Priority: 70)

Provides example queries:

```php
class ExamplesSection extends BasePromptSection
{
    protected string $name = 'examples';
    protected int $priority = 70;

    public function format(array $context, array $options = []): string
    {
        // Formats example questions and Cypher queries
        return "## Example Queries\n\n...";
    }
}
```

### GuidelinesSection (Priority: 60)

Response formatting instructions:

```php
class GuidelinesSection extends BasePromptSection
{
    protected string $name = 'guidelines';
    protected int $priority = 60;

    public function format(array $context, array $options = []): string
    {
        // Style-specific formatting instructions
        return "## Guidelines\n\n...";
    }
}
```

---

## Response Sections

Response sections format the prompt for natural language response generation:

```php
<?php

namespace App\Services\Ai\ResponseSections;

use Condoedge\Ai\Services\ResponseSections\BaseResponseSection;

class InsightsSection extends BaseResponseSection
{
    protected string $name = 'insights';
    protected int $priority = 50;

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
}
```

---

## Section Priority

Sections are ordered by priority (higher = earlier):

| Priority | Section | Purpose |
|----------|---------|---------|
| 100 | System | AI role and capabilities |
| 80 | Schema | Database structure |
| 75 | Scopes | Available filters |
| 70 | Examples | Example queries |
| 65 | Business Rules | Domain logic |
| 60 | Guidelines | Response format |
| 50 | Insights | Additional instructions |
| 10 | Query | User's question |

### Custom Priority

```php
class HighPrioritySection extends BasePromptSection
{
    protected int $priority = 90; // After system, before schema
}

class LowPrioritySection extends BasePromptSection
{
    protected int $priority = 20; // Near the end
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

    public function shouldInclude(array $context, array $options = []): bool
    {
        // Only include in development or when explicitly requested
        return app()->environment('local')
            || ($options['debug'] ?? false);
    }

    public function format(array $context, array $options = []): string
    {
        return "## Debug Mode\n\n" .
               "Include the generated Cypher query in your response.\n" .
               "Explain your reasoning step by step.\n\n";
    }
}
```

---

## Context Data

Sections receive context data:

```php
public function format(array $context, array $options = []): string
{
    // Available in $context:
    $schema = $context['schema'] ?? [];      // Entity schemas
    $scopes = $context['scopes'] ?? [];      // Available scopes
    $examples = $context['examples'] ?? [];  // Example queries
    $question = $context['question'] ?? '';  // User's question

    // Available in $options:
    $style = $options['style'] ?? 'friendly';
    $format = $options['format'] ?? 'text';
    $maxLength = $options['max_length'] ?? 100;
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

    public function shouldInclude(array $context, array $options = []): bool
    {
        return config('ai.project.domain') === 'e-commerce';
    }

    public function format(array $context, array $options = []): string
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

use Condoedge\Ai\Services\PromptSections\GuidelinesSection;

class CustomGuidelinesSection extends GuidelinesSection
{
    public function format(array $context, array $options = []): string
    {
        // Get default guidelines
        $output = parent::format($context, $options);

        // Add custom guidelines
        $output .= "\n## Company-Specific Guidelines\n\n";
        $output .= "- Always use metric units\n";
        $output .= "- Format currency as USD\n";
        $output .= "- Use 24-hour time format\n";

        return $output;
    }
}
```

### Replace Section

```php
// In service provider
$this->app->resolving(SemanticPromptBuilder::class, function ($builder) {
    // Remove default section
    $builder->removeSection('guidelines');

    // Add custom replacement
    $builder->addSection(new CustomGuidelinesSection());
});
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

    $this->assertTrue($section->shouldInclude([], []));

    $output = $section->format([], []);
    $this->assertStringContainsString('Rule 1', $output);
    $this->assertStringContainsString('Rule 2', $output);
}
```

---

## Related Documentation

- [Response Styles](/docs/{{version}}/configuration/response-styles) - Response configuration
- [Custom LLM Providers](/docs/{{version}}/extending/llm-providers) - LLM integration
- [Overview](/docs/{{version}}/usage/extending) - Extension overview
