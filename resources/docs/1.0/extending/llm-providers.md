# Custom LLM Providers

Create custom LLM providers to integrate with any language model.

---

## Overview

The AI system supports pluggable LLM providers. Built-in providers:
- **OpenAI**: GPT-4, GPT-4o, GPT-3.5
- **Anthropic**: Claude 3.5, Claude 3

You can create custom providers to integrate with:
- Local models (Ollama, LM Studio)
- Cloud providers (Azure OpenAI, AWS Bedrock)
- Custom APIs

---

## Provider Interface

All LLM providers implement `LlmProviderInterface`:

```php
<?php

namespace Condoedge\Ai\Contracts;

interface LlmProviderInterface
{
    /**
     * Generate a chat completion.
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param array $options Additional options (temperature, max_tokens, etc.)
     * @return string The generated response text
     */
    public function chat(array $messages, array $options = []): string;

    /**
     * Generate a completion for a single prompt.
     *
     * @param string $prompt The prompt text
     * @param array $options Additional options
     * @return string The generated response text
     */
    public function complete(string $prompt, array $options = []): string;

    /**
     * Get the provider name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if the provider is available/configured.
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
```

---

## Creating a Custom Provider

### Step 1: Create Provider Class

```php
<?php

namespace App\Services\Ai\Providers;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Illuminate\Support\Facades\Http;

class OllamaProvider implements LlmProviderInterface
{
    protected string $baseUrl;
    protected string $model;
    protected float $temperature;

    public function __construct()
    {
        $this->baseUrl = config('ai.llm.ollama.base_url', 'http://localhost:11434');
        $this->model = config('ai.llm.ollama.model', 'llama2');
        $this->temperature = config('ai.llm.ollama.temperature', 0.3);
    }

    public function chat(array $messages, array $options = []): string
    {
        $response = Http::timeout(120)
            ->post("{$this->baseUrl}/api/chat", [
                'model' => $options['model'] ?? $this->model,
                'messages' => $messages,
                'stream' => false,
                'options' => [
                    'temperature' => $options['temperature'] ?? $this->temperature,
                ],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Ollama API error: " . $response->body());
        }

        return $response->json('message.content', '');
    }

    public function complete(string $prompt, array $options = []): string
    {
        return $this->chat([
            ['role' => 'user', 'content' => $prompt]
        ], $options);
    }

    public function getName(): string
    {
        return 'ollama';
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
```

### Step 2: Add Configuration

```php
// config/ai.php
'llm' => [
    'default' => env('AI_LLM_PROVIDER', 'ollama'),

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'llama2'),
        'temperature' => env('OLLAMA_TEMPERATURE', 0.3),
    ],
],
```

### Step 3: Register Provider

In a service provider:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\Providers\OllamaProvider;

class AiExtensionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register as additional provider
        $this->app->bind('ai.llm.ollama', OllamaProvider::class);

        // Or replace default binding when ollama is selected
        if (config('ai.llm.default') === 'ollama') {
            $this->app->bind(LlmProviderInterface::class, OllamaProvider::class);
        }
    }
}
```

### Step 4: Use Your Provider

```env
AI_LLM_PROVIDER=ollama
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=llama2
```

```php
use Condoedge\Ai\Facades\AI;

// Uses your custom Ollama provider
$response = AI::chat("How many customers do we have?");
```

---

## Example: Azure OpenAI Provider

```php
<?php

namespace App\Services\Ai\Providers;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Illuminate\Support\Facades\Http;

class AzureOpenAiProvider implements LlmProviderInterface
{
    protected string $endpoint;
    protected string $apiKey;
    protected string $deploymentName;
    protected string $apiVersion;

    public function __construct()
    {
        $this->endpoint = config('ai.llm.azure.endpoint');
        $this->apiKey = config('ai.llm.azure.api_key');
        $this->deploymentName = config('ai.llm.azure.deployment_name');
        $this->apiVersion = config('ai.llm.azure.api_version', '2024-02-15-preview');
    }

    public function chat(array $messages, array $options = []): string
    {
        $url = "{$this->endpoint}/openai/deployments/{$this->deploymentName}/chat/completions?api-version={$this->apiVersion}";

        $response = Http::withHeaders([
            'api-key' => $this->apiKey,
        ])->post($url, [
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.3,
            'max_tokens' => $options['max_tokens'] ?? 2000,
        ]);

        if (!$response->successful()) {
            throw new \Exception("Azure OpenAI error: " . $response->body());
        }

        return $response->json('choices.0.message.content', '');
    }

    public function complete(string $prompt, array $options = []): string
    {
        return $this->chat([
            ['role' => 'user', 'content' => $prompt]
        ], $options);
    }

    public function getName(): string
    {
        return 'azure';
    }

    public function isAvailable(): bool
    {
        return !empty($this->endpoint) && !empty($this->apiKey);
    }
}
```

**Configuration:**

```php
// config/ai.php
'llm' => [
    'azure' => [
        'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
        'api_key' => env('AZURE_OPENAI_API_KEY'),
        'deployment_name' => env('AZURE_OPENAI_DEPLOYMENT'),
        'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-02-15-preview'),
    ],
],
```

---

## Example: AWS Bedrock Provider

```php
<?php

namespace App\Services\Ai\Providers;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Aws\BedrockRuntime\BedrockRuntimeClient;

class BedrockProvider implements LlmProviderInterface
{
    protected BedrockRuntimeClient $client;
    protected string $modelId;

    public function __construct()
    {
        $this->client = new BedrockRuntimeClient([
            'region' => config('ai.llm.bedrock.region', 'us-east-1'),
            'version' => 'latest',
        ]);
        $this->modelId = config('ai.llm.bedrock.model_id', 'anthropic.claude-3-sonnet-20240229-v1:0');
    }

    public function chat(array $messages, array $options = []): string
    {
        $body = [
            'anthropic_version' => 'bedrock-2023-05-31',
            'max_tokens' => $options['max_tokens'] ?? 2000,
            'messages' => $messages,
        ];

        $result = $this->client->invokeModel([
            'modelId' => $this->modelId,
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode($body),
        ]);

        $response = json_decode($result['body'], true);
        return $response['content'][0]['text'] ?? '';
    }

    public function complete(string $prompt, array $options = []): string
    {
        return $this->chat([
            ['role' => 'user', 'content' => $prompt]
        ], $options);
    }

    public function getName(): string
    {
        return 'bedrock';
    }

    public function isAvailable(): bool
    {
        return class_exists(BedrockRuntimeClient::class);
    }
}
```

---

## Message Format

The `chat()` method receives messages in this format:

```php
$messages = [
    ['role' => 'system', 'content' => 'You are a helpful assistant...'],
    ['role' => 'user', 'content' => 'How many customers?'],
    ['role' => 'assistant', 'content' => 'Let me check...'],
    ['role' => 'user', 'content' => 'In the USA specifically'],
];
```

Convert to your provider's format as needed.

---

## Options

Common options passed to `chat()`:

| Option | Type | Description |
|--------|------|-------------|
| `temperature` | float | Creativity (0-1) |
| `max_tokens` | int | Maximum response tokens |
| `model` | string | Override model |
| `stop` | array | Stop sequences |

---

## Testing Providers

```php
use Condoedge\Ai\Contracts\LlmProviderInterface;

// Test availability
$provider = app(LlmProviderInterface::class);
$this->assertTrue($provider->isAvailable());

// Test chat
$response = $provider->chat([
    ['role' => 'user', 'content' => 'Say hello']
]);
$this->assertNotEmpty($response);
```

---

## Related Documentation

- [Custom Embedding Providers](/docs/{{version}}/extending/embedding-providers) - Embedding integration
- [Overview](/docs/{{version}}/usage/extending) - Extension overview
- [Configuration](/docs/{{version}}/foundations/configuration) - Configuration guide
