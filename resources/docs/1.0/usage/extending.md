# Extending the Package

This guide covers the escape hatches you can use when the zero-config defaults are not enough. It focuses on customization, not core refactors (see the Internals track for that).

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

Define reusable prompts + Cypher templates inside `config/ai-patterns.php`.

```php
return [
    'goal_vs_actual' => [
        'description' => 'Compare a target metric against actuals',
        'cypher_template' => 'MATCH (t:{label}) WHERE t.{dimension} = $value RETURN t.goal, t.actual',
        'parameters' => ['label', 'dimension', 'value'],
        'examples' => [
            'How close are {label} to their {dimension} targets?',
        ],
    ],
];
```

Patterns with high-confidence matches skip the LLM entirely, reducing latency and token spend.

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

Ready to touch the internals? Continue with the [Core Components Guide](/docs/{{version}}/internals/components).
