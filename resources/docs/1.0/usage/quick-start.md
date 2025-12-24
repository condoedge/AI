# Quick Start Guide

Get AI-powered natural language queries working in 5 minutes!

---

## TL;DR - The 30-Second Version

```php
// 1. Make your model Nodeable
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;
}

// 2. Run commands
// php artisan ai:discover
// php artisan ai:ingest

// 3. Ask questions in natural language
$response = AI::chat("How many active customers do we have?");
```

That's it! Keep reading for the complete walkthrough.

---

## Prerequisites

Before starting:

- ✅ Package installed (`composer require condoedge/ai`)
- ✅ Neo4j and Qdrant running ([Infrastructure Setup](/docs/{{version}}/foundations/infrastructure))
- ✅ API key configured (`OPENAI_API_KEY` or `ANTHROPIC_API_KEY`)

**Recommended:** Configure project context in `.env`:

```env
APP_NAME="My E-Commerce Platform"
AI_PROJECT_DESCRIPTION="E-commerce platform managing products, orders, and customers"
AI_PROJECT_DOMAIN=e-commerce
```

---

## Step 1: Make Your Model Nodeable (1 minute)

Add the `Nodeable` interface and trait to your Eloquent model:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'company', 'status'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
```

**That's it!** No manual configuration needed yet.

---

## Step 2: Discover and Generate Config (1 minute)

Run the discovery command to automatically generate configuration:

```bash
php artisan ai:discover
```

**Output:**
```
🔍 Discovering Nodeable entities...

Found 1 Nodeable model(s)

Discovering: App\Models\Customer
  ✓ Discovered successfully

✓ Configuration written to config/entities.php
✓ Discovered 1 entities

Next steps:
  1. Review config/entities.php
  2. Customize as needed (labels, properties, relationships)
  3. Re-run ai:discover to update configurations
```

**What was generated?**

The command analyzed your `Customer` model and created `config/entities.php`:

```php
<?php

return [
    'App\Models\Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => ['id', 'name', 'email', 'company', 'status'],
            'relationships' => [
                ['type' => 'ORDERS', 'target_label' => 'Order', 'inverse' => true]
            ]
        ],
        'vector' => [
            'collection' => 'customers',
            'embed_fields' => ['name', 'email', 'company'],
            'metadata' => ['id', 'name', 'company', 'status']
        ],
        'metadata' => [
            'aliases' => ['customer', 'customers', 'client', 'clients'],
            'scopes' => [
                'active' => [
                    'name' => 'active',
                    'cypher_pattern' => "n.status = 'active'",
                    'description' => 'Filter active customers',
                    'example_queries' => ['Show active customers', 'List all active clients']
                ]
            ]
        ]
    ]
];
```

**Review and customize** this file as needed. You can:
- Change the Neo4j label
- Add/remove properties
- Customize embed fields
- Add more aliases

---

## Step 3: Bulk Ingest Existing Data (2 minutes)

If you have existing customers in your database, ingest them into Neo4j + Qdrant:

```bash
php artisan ai:ingest
```

**Output:**
```
🚀 Bulk Entity Ingestion

Found 1 Nodeable model(s)

Processing: App\Models\Customer
  Found 500 entities
  [████████████████] 500/500 (100%) Ingested: 500, Failed: 0
  ✓ Ingested: 500


✓ Successfully ingested: 500

Ingestion complete!
```

**What happened:**
- All 500 existing customers were processed in batches
- Customer nodes created in Neo4j with properties
- Embeddings generated and stored in Qdrant
- Relationships created (if target nodes exist)

**Important:** If your entities have relationships to other entities (e.g., Users → Persons), some relationships may be skipped if target nodes don't exist yet. After ingesting all entities, run:

```bash
php artisan ai:sync-relationships
```

This reconciles any missing relationships. See: [Relationship Synchronization](/docs/{{version}}/usage/data-ingestion#relationship-synchronization)

**Optional flags:**
```bash
# Ingest specific model only
php artisan ai:ingest --model="App\Models\Customer"

# Custom batch size (default: 100)
php artisan ai:ingest --chunk=500

# Preview without ingesting
php artisan ai:ingest --dry-run
```

---

## Step 4: Auto-Sync New Data (Built-in!)

**Good news:** New and updated entities automatically sync to Neo4j + Qdrant via model events!

```php
// Create a new customer - automatically ingested
$customer = Customer::create([
    'name' => 'Acme Corp',
    'email' => 'contact@acme.com',
    'company' => 'Acme Corporation',
    'status' => 'active'
]);
// ✓ Automatically stored in Neo4j
// ✓ Automatically embedded and stored in Qdrant

// Update customer - automatically synced
$customer->status = 'inactive';
$customer->save();
// ✓ Automatically updated in Neo4j
// ✓ Automatically re-embedded and updated in Qdrant

// Delete customer - automatically removed
$customer->delete();
// ✓ Automatically removed from Neo4j
// ✓ Automatically removed from Qdrant
```

**How it works:**

The `HasNodeableConfig` trait automatically registers model events (created, updated, deleted) that sync changes to the AI system. No observers or manual syncing needed!

**Disable auto-sync for specific operations:**

```php
// Temporarily disable auto-sync
$customer = Customer::withoutEvents(function () {
    return Customer::create(['name' => 'Test']);
});

// Or disable in config
// config/ai.php
'auto_sync' => [
    'enabled' => false, // Disable globally
]
```

---

## Step 5: Ask Questions in Natural Language (1 minute)

Now query your data using natural language:

```php
use Condoedge\Ai\Facades\AI;

// Ask a question
$response = AI::chat("How many active customers do we have?");

echo $response;
// Output: "You have 347 active customers in the system."
```

**What happened:**
1. **Context Retrieval (RAG):**
   - Vector search found similar past queries
   - Graph schema retrieved from Neo4j
   - Scope "active" discovered from metadata
2. **Query Generation:**
   - LLM generated Cypher: `MATCH (n:Customer) WHERE n.status = 'active' RETURN count(n)`
   - Query validated for safety (no injection, no deletes)
3. **Execution:**
   - Cypher executed against Neo4j
   - Result: `347`
4. **Response Generation:**
   - LLM transformed result into natural language
   - Response: "You have 347 active customers in the system."

**More examples:**

```php
// Complex query with relationships
AI::chat("Show me customers who placed orders this month");

// Aggregation
AI::chat("What's the average number of orders per customer?");

// Using scopes
AI::chat("List all active customers in USA");
```

---

## Alternative: Use NodeableConfig Builder (Advanced)

Instead of `config/entities.php`, you can define configuration directly in your model using the fluent builder:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'company', 'status'];

    /**
     * Define AI configuration using builder pattern
     */
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::for(static::class)
            // Neo4j configuration
            ->label('Customer')
            ->properties('id', 'name', 'email', 'company', 'status')
            ->relationship('orders', 'Order', 'HAS_ORDER', 'customer_id')

            // Qdrant configuration
            ->collection('customers')
            ->embedFields('name', 'email', 'company')
            ->metadata(['id', 'name', 'company', 'status'])

            // Metadata
            ->aliases('customer', 'client', 'account')
            ->description('Customer entity with orders and contact info');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
```

**Benefits:**
- ✅ Configuration lives with the model (single source of truth)
- ✅ IDE autocomplete and type safety
- ✅ Can use logic and conditionals
- ✅ Overrides `config/entities.php` (highest priority)

**See [Advanced Usage](/docs/{{version}}/usage/advanced-usage) for complete NodeableConfig API.**

---

## Configuration Priority

The system resolves configuration in this order (highest to lowest):

1. **`nodeableConfig()` method** - In your model (highest priority)
2. **`config/entities.php`** - Generated by `php artisan ai:discover`
3. **Runtime auto-discovery** - Disabled by default (only for dev/testing)

**Recommendation:**
- Small projects: Use `config/entities.php` (simple, centralized)
- Large projects: Use `nodeableConfig()` (distributed, type-safe)
- Mix and match: Override specific models with `nodeableConfig()`

---

## Summary: Your Complete Workflow

```bash
# 1. Make model Nodeable
class Customer extends Model implements Nodeable { use HasNodeableConfig; }

# 2. Discover and generate config
php artisan ai:discover

# 3. Review/customize config/entities.php
# (Optional: Add nodeableConfig() method instead)

# 4. Bulk ingest existing data (one-time)
php artisan ai:ingest

# 5. New data auto-syncs automatically
$customer = Customer::create([...]); // Auto-ingested!

# 6. Ask questions in natural language
AI::chat("How many customers do we have?");
```

---

## Bonus: Add Chat UI (2 minutes)

Want a beautiful chat interface? Add the floating chat button to your layout:

```php
use Condoedge\Ai\Kompo\AiChatFloating;

// In your layout component
public function render()
{
    return _Rows(
        // Your page content...

        // Add floating chat button
        new AiChatFloating()
    );
}
```

Or open chat from any button:

```php
use Condoedge\Ai\Kompo\AiChatModal;

_Button('Ask AI')
    ->selfGet('openChat')
    ->inModal();

public function openChat()
{
    return new AiChatModal(null, [
        'welcome_title' => 'AI Assistant',
        'example_questions' => [
            'How many customers?',
            'Show recent orders',
        ],
    ]);
}
```

**See:** [Chat UI Components](/docs/{{version}}/chat/chat-ui) for full customization options.

---

## Next Steps

| What you want | Documentation |
|--------------|---------------|
| **Chat UI** | [Chat Components](/docs/{{version}}/chat/chat-ui) |
| **All AI methods** | [AI Facade Reference](/docs/{{version}}/usage/simple-usage) |
| **Custom configuration** | [Advanced Usage](/docs/{{version}}/usage/advanced-usage) |
| **Extend the system** | [Extending Guide](/docs/{{version}}/usage/extending) |
| **Troubleshooting** | [Troubleshooting](/docs/{{version}}/foundations/troubleshooting) |
