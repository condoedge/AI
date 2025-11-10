# LLM API

Interact with Large Language Models (OpenAI GPT-4o or Anthropic Claude) for chat, completions, and structured outputs.

---

## Overview

**Supported Providers:**
- OpenAI: GPT-4o (128K context window)
- Anthropic: Claude 3.5 Sonnet (200K context window)

**Capabilities:**
- Chat with conversation history
- JSON response mode
- Simple completions
- Streaming (advanced)

---

## Chat Completions

### Simple Chat

```php
use AiSystem\Facades\AI;

$response = AI::chat("What is the capital of France?");
echo $response; // "The capital of France is Paris."
```

### With Conversation History

```php
$conversation = [
    ['role' => 'system', 'content' => 'You are a helpful assistant'],
    ['role' => 'user', 'content' => 'Hello!'],
    ['role' => 'assistant', 'content' => 'Hi! How can I help?'],
    ['role' => 'user', 'content' => 'What is 2+2?']
];

$response = AI::chat($conversation);
echo $response; // "2+2 equals 4."
```

### With Options

```php
$response = AI::chat("Write a creative story", [
    'temperature' => 0.9,  // More creative (0.0-2.0)
    'max_tokens' => 500    // Limit response length
]);
```

---

## JSON Response Mode

Get structured JSON responses:

```php
$data = AI::chatJson("Generate a Cypher query for: Show all teams");

// Returns decoded JSON:
// {
//     "query": "MATCH (t:Team) RETURN t",
//     "explanation": "Matches all Team nodes"
// }

echo $data->query;
echo $data->explanation;
```

**Structured Data Extraction:**
```php
$prompt = "Extract: John Doe, age 30, email john@example.com";
$data = AI::chatJson($prompt);

// Returns:
// {
//     "name": "John Doe",
//     "age": 30,
//     "email": "john@example.com"
// }
```

---

## Simple Completions

```php
$response = AI::complete("Translate 'hello' to French");
echo $response; // "Bonjour"

// With system prompt
$response = AI::complete(
    "Translate 'hello' to French",
    "You are a professional translator"
);

// With options
$response = AI::complete(
    "Write a haiku about programming",
    "You are a poet",
    ['temperature' => 0.9]
);
```

---

## Configuration

Set in `.env`:

```env
# Choose provider
AI_LLM_PROVIDER=openai  # or anthropic

# OpenAI
OPENAI_API_KEY=sk-your-key-here
OPENAI_MODEL=gpt-4o
OPENAI_TEMPERATURE=0.3
OPENAI_MAX_TOKENS=2000

# Anthropic
ANTHROPIC_API_KEY=sk-ant-your-key-here
ANTHROPIC_MODEL=claude-3-5-sonnet-20241022
ANTHROPIC_TEMPERATURE=0.3
ANTHROPIC_MAX_TOKENS=4000
```

---

## Message Roles

| Role | Description |
|------|-------------|
| `system` | Instructions for the AI behavior |
| `user` | User's input/question |
| `assistant` | AI's previous responses |

```php
[
    ['role' => 'system', 'content' => 'You are an expert programmer'],
    ['role' => 'user', 'content' => 'Explain recursion'],
    ['role' => 'assistant', 'content' => 'Recursion is when...'],
    ['role' => 'user', 'content' => 'Can you give an example?']
]
```

---

## Options Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `temperature` | float | 0.3 | Creativity (0.0-2.0) |
| `max_tokens` | int | 2000 | Max response length |
| `model` | string | From config | Override model |

---

## Advanced: Direct Provider Usage

### OpenAI

```php
use AiSystem\LlmProviders\OpenAiLlmProvider;

$openai = new OpenAiLlmProvider([
    'api_key' => env('OPENAI_API_KEY'),
    'model' => 'gpt-4o',
    'temperature' => 0.3,
    'max_tokens' => 2000
]);

$response = $openai->chat([
    ['role' => 'user', 'content' => 'Hello!']
]);
```

### Anthropic

```php
use AiSystem\LlmProviders\AnthropicLlmProvider;

$claude = new AnthropicLlmProvider([
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model' => 'claude-3-5-sonnet-20241022',
    'temperature' => 0.3,
    'max_tokens' => 4000
]);

$response = $claude->chat([
    ['role' => 'user', 'content' => 'Explain quantum computing']
]);
```

---

## Error Handling

```php
try {
    $response = AI::chat("What is AI?");
} catch (\Exception $e) {
    Log::error('LLM error: ' . $e->getMessage());
    // Handle error (invalid API key, rate limit, etc.)
}
```

---

## Best Practices

### Temperature Settings

- **0.0-0.3**: Factual, consistent (queries, analysis)
- **0.3-0.7**: Balanced creativity
- **0.7-2.0**: Very creative (stories, brainstorming)

### Token Management

Monitor token usage to control costs:

```php
$response = AI::chat($prompt, ['max_tokens' => 500]);
```

### System Prompts

Use clear system prompts for consistent behavior:

```php
$messages = [
    ['role' => 'system', 'content' => 'You are a Cypher expert. Only output valid Cypher queries.'],
    ['role' => 'user', 'content' => $question]
];
```

---

See also: [Simple Usage](/docs/{{version}}/simple-usage) | [Context Retrieval](/docs/{{version}}/context-retrieval) | [Examples](/docs/{{version}}/examples)
