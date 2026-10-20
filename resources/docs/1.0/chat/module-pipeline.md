# Module Pipeline System

The AI package uses a flexible, priority-based module pipeline for building LLM prompts and generating responses. This architecture allows for easy customization and extension without modifying core code.

## Overview

Both `SemanticPromptBuilder` and `ResponseGenerator` use the `HasInternalModules` trait, which provides:

- **Modular Architecture**: Compose outputs from reusable, configurable sections
- **Priority-based Processing**: Modules execute in priority order (lower = first)
- **Configuration-driven**: Load default modules from the `ai.query_generator_sections` / `ai.response_generator_sections` config lists

## Core Components

### HasInternalModules Trait

The trait provides the following capabilities:

```php
// Module Management
$builder->addModule(new CustomSection());      // Add a module
$builder->removeModule('section_name');        // Remove by name
$builder->getModule('name');                   // Get a module
$builder->hasModule('name');                   // Check existence
$builder->getModules();                        // Get all modules (keyed by name)
```

To replace a module, remove it and add the new one (or swap the class in the config list). Pipelines are customized application-wide through the config lists `ai.query_generator_sections` and `ai.response_generator_sections` (class-strings; closures receiving the builder are also supported).

### SectionModuleInterface

All modules must implement:

```php
interface SectionModuleInterface
{
    public function getName(): string;           // Unique identifier
    public function getPriority(): int;          // Processing order
    public function shouldInclude(...): bool;    // Conditional inclusion
    public function format(...): string;         // Generate content
}
```

## SemanticPromptBuilder

Builds LLM prompts for Neo4j Cypher query generation.

### Default Sections (in priority order)

| Priority | Name | Description |
|----------|------|-------------|
| 10 | project_context | Project name, description, business rules |
| 15 | generic_context | Current date/time |
| 17 | current_user | Current user and team context |
| 20 | schema | Schema cards: one compact card per selected label (typed properties, enum legends, live directed edges, fk hints) |
| 45 | file_context | Names of relevant documents (no document text) |
| 50 | similar_queries | Previously successful similar queries |
| 55 | conversation_context | Conversation history |
| 65 | detected_scopes | Scopes/filters detected in the question |
| 75 | query_rules | Rules for query generation |
| 80 | question | The user's actual question |
| 90 | task_instructions | Final instructions for the LLM |

### Configuration

```php
// config/ai.php
return [
    'query_generator_sections' => [
        \Condoedge\Ai\Services\PromptSections\ProjectContextSection::class,
        \Condoedge\Ai\Services\PromptSections\SchemaCardsSection::class,
        // ... add or remove sections as needed
    ],
];
```

### Usage Example

```php
$builder = new SemanticPromptBuilder();

// Customize project context
$builder->setProjectContext([
    'name' => 'My CRM',
    'description' => 'Customer relationship management system',
    'business_rules' => [
        'All dates are stored in UTC',
        'Customer IDs are UUIDs',
    ],
]);

// Add custom rules
$builder->addQueryRule('PERFORMANCE', 'Always use indexed properties');
$builder->addBusinessRule('Soft deletes are used for all entities');

// Customize task instructions
$builder->setTaskInstructions('Generate a read-only Cypher query...');

// Build the prompt
$prompt = $builder->buildPrompt(
    question: "How many active customers do we have?",
    context: [
        'schema' => $schema,
        'metadata' => $metadata,
        'detected_scopes' => ['status' => 'active'],
    ],
    allowWrite: false
);
```

## ResponseGenerator

Transforms query results into natural language explanations.

### Default Sections (in priority order)

| Priority | Name | Description |
|----------|------|-------------|
| 10 | system | System prompt setting the LLM's role |
| 20 | project_context | Project name and description |
| 30 | question | The user's original question |
| 40 | query_info | The Cypher query that was executed |
| 45 | file_context | Relevant file content with citations |
| 50 | data | The actual results data |
| 60 | statistics | Statistics about the results |
| 70 | guidelines | Guidelines for response formatting |
| 80 | task | Final task instructions for the LLM |
| 1000 | privacy_security | Privacy and security guidelines |

### Configuration

```php
// config/ai.php
return [
    'response_generator_sections' => [
        \Condoedge\Ai\Services\ResponseSections\SystemPromptSection::class,
        \Condoedge\Ai\Services\ResponseSections\ResponseProjectContextSection::class,
        \Condoedge\Ai\Services\ResponseSections\OriginalQuestionSection::class,
        // ... add or remove sections as needed
    ],
];
```

### Usage Example

```php
$generator = new ResponseGenerator($llmProvider, $config);

// Customize
$generator->setProjectContext([
    'name' => 'My CRM',
    'description' => 'Customer management system',
]);
$generator->setSystemPrompt('You are a helpful data analyst...');
$generator->addGuideline('Always include percentage changes when relevant');
$generator->setMaxDataItems(20);

// Generate response
$response = $generator->generate(
    originalQuestion: "How many customers do we have?",
    queryResult: [
        'data' => [['count' => 1500]],
        'stats' => ['execution_time' => 23],
    ],
    cypherQuery: "MATCH (c:Customer) RETURN count(c) as count",
    options: [
        'format' => 'text',
        'style' => 'detailed',
    ]
);

// Response structure
// $response = [
//     'answer' => 'You have 1,500 customers in your database.',
//     'format' => 'text',
//     'metadata' => ['style' => 'detailed', 'result_count' => 1, ...],
// ];
```

## Extending the Pipeline

### Creating Custom Sections

```php
use Condoedge\Ai\Contracts\PromptSectionInterface;

class CustomAnalyticsSection implements PromptSectionInterface
{
    public function getName(): string
    {
        return 'custom_analytics';
    }

    public function getPriority(): int
    {
        return 52; // After similar_queries (50), before conversation_context (55)
    }

    public function shouldInclude(string $question, array $context, array $options): bool
    {
        // Only include for analytics-related questions
        return str_contains(strtolower($question), 'analytics')
            || str_contains(strtolower($question), 'statistics');
    }

    public function format(string $question, array $context, array $options): string
    {
        return <<<SECTION

=== ANALYTICS CONTEXT ===

When generating analytics queries, consider:
- Use aggregation functions (COUNT, SUM, AVG, etc.)
- Group by relevant dimensions
- Consider time-based filtering

SECTION;
    }
}
```

### Registering Sections Application-Wide

To add, remove, or reorder sections for every builder instance, edit the config lists in your published `config/ai.php`:

```php
// config/ai.php
'query_generator_sections' => [
    // ... default sections ...
    \App\Ai\PromptSections\CustomAnalyticsSection::class,
    \App\Ai\PromptSections\CompanyContextSection::class,
],

'response_generator_sections' => [
    // ... default sections ...
],
```

Entries are class-strings (resolved through the container) or closures that receive the builder and return a section instance. Remove an entry from the list to drop that section.

For a single instance, use the instance API:

```php
$builder->addModule(new CompanyContextSection());
$builder->removeModule('similar_queries');
$builder->addBusinessRule('Our fiscal year starts in April');
```

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    HasInternalModules Trait                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────┐   ┌─────────────────────────────────────┐     │
│  │  Sections   │   │  Default module list (config:       │     │
│  │  (modules)  │   │  *_generator_sections)              │     │
│  └─────────────┘   └─────────────────────────────────────┘     │
│                                                                 │
│  Pipeline Processing:                                           │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ for each section (sorted by priority):                   │  │
│  │   if section.shouldInclude(): section.format()           │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
                              │
           ┌──────────────────┼──────────────────┐
           │                  │                  │
           ▼                  ▼                  ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│ SemanticPrompt  │  │ ResponseGen-    │  │ Your Custom     │
│ Builder         │  │ erator          │  │ Builder         │
│                 │  │                 │  │                 │
│ - buildPrompt() │  │ - generate()    │  │ - yourMethod()  │
│ - setProject-   │  │ - buildPrompt() │  │                 │
│   Context()     │  │ - setProject-   │  │                 │
│ - addQuery-     │  │   Context()     │  │                 │
│   Rule()        │  │ - addGuideline()│  │                 │
└─────────────────┘  └─────────────────┘  └─────────────────┘
```

---

## Prompt Building Pipeline

The `SemanticPromptBuilder` assembles LLM prompts by processing sections in priority order. Lower priority numbers execute first.

```mermaid
flowchart TD
    subgraph Input["Input Data"]
        Q[User Question]
        CTX[Context Array]
        OPT[Options]
    end

    subgraph Pipeline["Section Pipeline (sorted by priority)"]
        direction TB
        S10["10: project_context<br/>Project name, description, rules"]
        S15["15: generic_context<br/>Current date/time"]
        S17["17: current_user<br/>User and team context"]
        S20["20: schema<br/>Schema cards per selected label"]
        S45["45: file_context<br/>Relevant document names"]
        S50["50: similar_queries<br/>Past successful queries"]
        S55["55: conversation_context<br/>Chat history"]
        S65["65: detected_scopes<br/>Filters detected"]
        S75["75: query_rules<br/>Generation rules"]
        S80["80: question<br/>The actual question"]
        S90["90: task_instructions<br/>Final instructions"]
    end

    subgraph Processing["Processing Logic"]
        CHECK{shouldInclude?}
        FORMAT[section.format]
    end

    Q --> Pipeline
    CTX --> Pipeline
    OPT --> Pipeline

    S10 --> CHECK
    CHECK -->|Yes| FORMAT
    CHECK -->|No| SKIP[Skip section]
    FORMAT --> S15
    SKIP --> S15

    S15 -.-> S17 -.-> S20 -.-> S45 -.-> S50
    S50 -.-> S55 -.-> S65 -.-> S75 -.-> S80 -.-> S90

    S90 --> OUTPUT[Complete Prompt]
```

### Section Inclusion Logic

Each section implements `shouldInclude()` to conditionally participate in the prompt:

```mermaid
flowchart LR
    subgraph Conditions["shouldInclude() Examples"]
        SC1["schema: Always included"]
        SC2["similar_queries: Only if queries exist"]
        SC3["conversation_context: Only if history exists"]
        SC4["file_context: Only if files provided"]
        SC5["detected_scopes: Only if scopes detected"]
    end
```

### Extension Points

The pipeline supports multiple extension mechanisms:

```mermaid
flowchart TD
    subgraph Extensions["Extension Mechanisms"]
        EXT1["addModule(section)<br/>Add new section"]
        EXT2["removeModule(name)<br/>Remove section"]
        EXT3["query_generator_sections / response_generator_sections<br/>Config: all instances"]
    end

    BUILDER[SemanticPromptBuilder] --> EXT1
    BUILDER --> EXT2
    CONFIG[config/ai.php] --> EXT3
```

---

## ResponseGenerator Pipeline

The `ResponseGenerator` uses the same pipeline architecture to build prompts for transforming query results into natural language.

```mermaid
flowchart TD
    subgraph Input["Input Data"]
        RQ[Original Question]
        RD[Query Results]
        RC[Cypher Query]
        RO[Options]
    end

    subgraph Pipeline["Response Section Pipeline"]
        direction TB
        R10["10: system<br/>LLM role definition"]
        R15["15: security_restrictions<br/>Privacy guidelines"]
        R20["20: project_context<br/>Project info"]
        R30["30: question<br/>Original question"]
        R40["40: query_info<br/>Executed Cypher"]
        R45["45: file_context<br/>File content/citations"]
        R50["50: data<br/>Results data"]
        R60["60: statistics<br/>Result stats"]
        R70["70: guidelines<br/>Formatting guidelines"]
        R80["80: task<br/>Task instructions"]
    end

    subgraph Output["Output"]
        PROMPT[Response Prompt]
        LLM[LLM Call]
        ANSWER[Natural Language Answer]
    end

    RQ --> Pipeline
    RD --> Pipeline
    RC --> Pipeline
    RO --> Pipeline

    R10 --> R15 --> R20 --> R30 --> R40 --> R45 --> R50 --> R60 --> R70 --> R80
    R80 --> PROMPT
    PROMPT --> LLM
    LLM --> ANSWER
```

---

## Complete Query-to-Response Flow

This diagram shows how both pipelines work together in the full AI query flow:

```mermaid
sequenceDiagram
    participant User
    participant QueryGen as QueryGenerator
    participant PromptBuilder as SemanticPromptBuilder
    participant LLM as LLM Provider
    participant QueryExec as QueryExecutor
    participant ResponseGen as ResponseGenerator

    User->>QueryGen: question + context
    QueryGen->>PromptBuilder: buildPrompt(question, context)

    Note over PromptBuilder: Process sections<br/>in priority order

    loop For each section (10-90)
        PromptBuilder->>PromptBuilder: if shouldInclude() then format()
    end

    PromptBuilder-->>QueryGen: Complete Cypher generation prompt
    QueryGen->>LLM: Generate Cypher
    LLM-->>QueryGen: Cypher query
    QueryGen->>QueryExec: Execute query
    QueryExec-->>QueryGen: Results + stats

    QueryGen->>ResponseGen: generate(question, results, cypher)
    ResponseGen->>ResponseGen: buildPrompt(context, options)

    Note over ResponseGen: Process 10 sections<br/>in priority order

    loop For each section (10-80)
        ResponseGen->>ResponseGen: if shouldInclude() then format()
    end

    ResponseGen->>LLM: Generate natural language response
    LLM-->>ResponseGen: Answer text
    ResponseGen-->>User: {answer, format, metadata}
```

## Best Practices

1. **Use configuration for default sections**: Define your section list in config files for easy deployment-specific customization.

2. **Keep sections focused**: Each section should have a single responsibility.

3. **Use appropriate priorities**: Leave gaps between priorities (10, 20, 30...) to allow insertion of custom sections.

4. **Use `shouldInclude` wisely**: Conditional inclusion keeps prompts concise and relevant.

5. **Document custom sections**: Include clear PHPDoc explaining what the section adds and when it's included.
