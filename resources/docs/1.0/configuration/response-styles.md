# Response Styles

Control how the AI generates natural language responses.

---

## Overview

Response styles determine the verbosity and format of AI responses. Choose the right style based on your use case:

| Style | Description | Example Output |
|-------|-------------|----------------|
| `minimal` | Just the answer | `Admin System` |
| `concise` | One sentence | `The next birthday is Admin System on Nov 29.` |
| `friendly` | Natural conversation (2-3 sentences) | `The next upcoming birthday is for Admin System on November 29th. They'll be celebrating soon!` |
| `detailed` | Full explanation with context | Includes relevant details and context about the data |
| `technical` | Debug mode with query details | Includes Cypher query, execution time, and metrics |

---

## Configuration

### Default Style

Set in `.env`:

```env
AI_RESPONSE_STYLE=friendly
```

Or in `config/ai.php`:

```php
'response_generation' => [
    'default_style' => 'friendly',
],
```

### Per-Request Style

Override the default for specific queries:

```php
use Condoedge\Ai\Facades\AI;

// Minimal - just the facts
$answer = AI::chat("Who has the next birthday?", [
    'style' => 'minimal'
]);
// Output: "Admin System"

// Concise - one sentence
$answer = AI::chat("Who has the next birthday?", [
    'style' => 'concise'
]);
// Output: "The next birthday is Admin System on November 29th."

// Detailed - with context
$answer = AI::chat("Who has the next birthday?", [
    'style' => 'detailed'
]);
// Output: "The next upcoming birthday in the system is Admin System,
// scheduled for November 29th. This person is part of the Admin team
// and has been in the system since 2023."
```

---

## Style Details

### `minimal`

Best for: API responses, data extraction, programmatic use.

```php
AI::chat("How many active customers?", ['style' => 'minimal']);
// Output: "347"

AI::chat("What is John's email?", ['style' => 'minimal']);
// Output: "john@example.com"
```

**Behavior:**
- Returns only the direct answer value
- No explanation or context
- Maximum ~20 words

### `concise`

Best for: Quick answers, dashboard widgets, notifications.

```php
AI::chat("How many active customers?", ['style' => 'concise']);
// Output: "There are 347 active customers."

AI::chat("Who placed the most orders?", ['style' => 'concise']);
// Output: "Acme Corp placed the most orders with 156 total."
```

**Behavior:**
- Single sentence answer
- Includes the key fact
- Maximum ~50 words

### `friendly` (Default)

Best for: Chatbots, user-facing interfaces, conversational AI.

```php
AI::chat("How many active customers?", ['style' => 'friendly']);
// Output: "You have 347 active customers in your system.
// That's about 78% of your total customer base."

AI::chat("Show me recent orders", ['style' => 'friendly']);
// Output: "Here are your 5 most recent orders. The latest one
// is from Acme Corp for $1,250, placed earlier today."
```

**Behavior:**
- Natural, conversational tone
- 2-3 sentences maximum
- Focuses on what matters to the user
- Hides all technical details

### `detailed`

Best for: Reports, analysis, internal tools.

```php
AI::chat("Analyze customer growth", ['style' => 'detailed']);
// Output: "Your customer base has grown significantly over the past quarter.
// You currently have 347 active customers, up from 312 at the start of Q3,
// representing an 11% growth rate. The majority of new customers (68%)
// came from the enterprise segment, while SMB growth remained steady at 5%."
```

**Behavior:**
- Full explanation with context
- Relevant insights and comparisons
- Maximum ~200 words
- Can include bullet points for lists

### `technical`

Best for: Debugging, development, query optimization.

```php
AI::chat("How many active customers?", ['style' => 'technical']);
// Output: "Result: 347 active customers
//
// Query: MATCH (n:Customer) WHERE n.status = 'active' RETURN count(n)
// Execution time: 12ms
// Rows scanned: 445
// Index used: customer_status_idx"
```

**Behavior:**
- Includes Cypher query
- Shows execution metrics
- Useful for debugging
- Maximum ~300 words

---

## Hiding Technical Details

Control what technical information is excluded from responses:

```env
# Hide query/database references (default: true)
AI_RESPONSE_HIDE_TECHNICAL=true

# Hide execution time/performance (default: true)
AI_RESPONSE_HIDE_STATS=true

# Hide project/system name (default: true)
AI_RESPONSE_HIDE_PROJECT=true
```

**Impact by style:**

| Setting | minimal | concise | friendly | detailed | technical |
|---------|---------|---------|----------|----------|-----------|
| hide_technical | Auto | Auto | Auto | Respects | Ignored |
| hide_stats | Auto | Auto | Auto | Respects | Ignored |
| hide_project | Auto | Auto | Auto | Respects | Ignored |

- **Auto**: Always hidden regardless of setting
- **Respects**: Uses config value
- **Ignored**: Always shown

---

## Response Format

In addition to style, you can control the output format:

```env
AI_RESPONSE_FORMAT=text  # text, markdown, json
```

### Text (Default)

Plain text responses:

```php
AI::chat("List top 3 customers", ['format' => 'text']);
// Output: "The top 3 customers by revenue are: 1. Acme Corp ($50,000)
// 2. TechStart Inc ($35,000) 3. Global Systems ($28,000)"
```

### Markdown

Formatted markdown:

```php
AI::chat("List top 3 customers", ['format' => 'markdown']);
// Output: "## Top Customers by Revenue
//
// | Rank | Customer | Revenue |
// |------|----------|---------|
// | 1 | Acme Corp | $50,000 |
// | 2 | TechStart Inc | $35,000 |
// | 3 | Global Systems | $28,000 |"
```

### JSON

Structured data:

```php
AI::chat("List top 3 customers", ['format' => 'json']);
// Output: {
//   "summary": "Top 3 customers by revenue",
//   "details": [
//     {"rank": 1, "customer": "Acme Corp", "revenue": 50000},
//     {"rank": 2, "customer": "TechStart Inc", "revenue": 35000},
//     {"rank": 3, "customer": "Global Systems", "revenue": 28000}
//   ]
// }
```

---

## Maximum Response Length

Control response length per style:

```php
// config/ai.php
'response_generation' => [
    'default_max_length' => 100, // words (auto-adjusted by style)
],
```

**Default lengths by style:**

| Style | Max Words |
|-------|-----------|
| minimal | 20 |
| concise | 50 |
| friendly | 100 |
| detailed | 200 |
| technical | 300 |

Override per request:

```php
AI::chat("Explain the data", [
    'style' => 'detailed',
    'max_length' => 500  // Allow longer response
]);
```

---

## Custom Guidelines

Add custom instructions for response generation:

```php
use Condoedge\Ai\Services\ResponseSections\GuidelinesSection;

$guidelines = app(GuidelinesSection::class);

// Add custom guidelines
$guidelines->addGuideline('Always mention the data freshness');
$guidelines->addGuideline('Use metric units for measurements');

// Add things to avoid
$guidelines->addAvoid('Technical jargon');
$guidelines->addAvoid('Abbreviations without explanation');

// Or set all at once
$guidelines->setGuidelines([
    'Be formal and professional',
    'Include confidence levels when uncertain',
]);
```

---

## Creating Custom Styles

Add your own response style:

```php
use Condoedge\Ai\Services\ResponseSections\GuidelinesSection;

$guidelines = app(GuidelinesSection::class);

$guidelines->addStyle('executive',
    'Provide a high-level executive summary. Focus on business impact, ' .
    'key metrics, and actionable insights. Avoid technical details. ' .
    'Use bullet points for clarity.'
);

// Use the custom style
AI::chat("Summarize Q3 performance", ['style' => 'executive']);
```

---

## Best Practices

### Choose the Right Style

| Use Case | Recommended Style |
|----------|-------------------|
| API/programmatic access | `minimal` |
| Dashboard widgets | `concise` |
| Chatbot/user-facing | `friendly` |
| Reports/analysis | `detailed` |
| Development/debugging | `technical` |

### Style Consistency

Set a default style that matches your primary use case:

```env
# For a user-facing chatbot
AI_RESPONSE_STYLE=friendly

# For an internal analytics tool
AI_RESPONSE_STYLE=detailed

# For an API service
AI_RESPONSE_STYLE=minimal
```

### Combining Options

Mix style, format, and custom guidelines:

```php
AI::chat("Monthly sales report", [
    'style' => 'detailed',
    'format' => 'markdown',
    'max_length' => 300,
]);
```

---

## Related Documentation

- [Entity Configuration](/docs/{{version}}/configuration/entities) - Configure entities
- [Environment Variables](/docs/{{version}}/configuration/environment) - All settings
- [AI Facade](/docs/{{version}}/usage/simple-usage) - Using the AI facade
- [Custom Prompt Sections](/docs/{{version}}/extending/prompt-sections) - Advanced customization
