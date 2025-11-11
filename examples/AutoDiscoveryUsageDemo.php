<?php

/**
 * Entity Auto-Discovery Usage Demo
 *
 * This file demonstrates the various ways to use auto-discovery with the AI system.
 * Shows progression from zero-config to full customization.
 *
 * @package Condoedge\Ai\Examples
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;
use Condoedge\Ai\Facades\AI;

echo "\n=== Entity Auto-Discovery Usage Examples ===\n\n";

// ============================================================================
// Example 1: Zero Configuration (Full Auto-Discovery)
// ============================================================================

echo "Example 1: Zero Configuration\n";
echo str_repeat("-", 50) . "\n";

/**
 * This is the simplest approach. Just use the trait and everything is
 * automatically discovered from your Eloquent model definition.
 */
class Product extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'description', 'price', 'category_id'];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}

echo "✓ Product model uses zero configuration\n";
echo "  Auto-discovers: properties, relationships, aliases, embed fields\n\n";

// ============================================================================
// Example 2: Selective Override with nodeableConfig() Method
// ============================================================================

echo "Example 2: Selective Override\n";
echo str_repeat("-", 50) . "\n";

/**
 * Override just the parts you need, rest is auto-discovered.
 * Use NodeableConfig::discover() to start with discovered config,
 * then customize specific aspects.
 */
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'phone', 'country'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Override just aliases, rest auto-discovered
     */
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->addAlias('client')
            ->addAlias('buyer');
    }
}

echo "✓ Customer model overrides aliases only\n";
echo "  Auto-discovers: properties, relationships, embed fields\n";
echo "  Manually adds: 'client', 'buyer' aliases\n\n";

// ============================================================================
// Example 3: Customize Discovery Hook
// ============================================================================

echo "Example 3: Customize Discovery\n";
echo str_repeat("-", 50) . "\n";

/**
 * Use customizeDiscovery() hook to modify the discovered configuration
 * before it's finalized. This is useful for making adjustments to
 * auto-discovered settings.
 */
class Order extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['customer_id', 'total', 'status', 'notes'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Hook into discovery process to customize
     */
    public function customizeDiscovery(NodeableConfig $config): NodeableConfig
    {
        return $config
            ->addAlias('purchase')
            ->addAlias('transaction')
            ->description('Order records with customer relationships');
    }
}

echo "✓ Order model customizes auto-discovery\n";
echo "  Auto-discovers: everything\n";
echo "  Customizes: adds aliases and description\n\n";

// ============================================================================
// Example 4: Complete Manual Override
// ============================================================================

echo "Example 4: Complete Manual Override\n";
echo str_repeat("-", 50) . "\n";

/**
 * For models that need complete control, return a full array config.
 * This bypasses auto-discovery entirely.
 */
class Invoice extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['order_id', 'amount', 'issued_at'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Full control, no auto-discovery
     */
    public function nodeableConfig(): array
    {
        return [
            'graph' => [
                'label' => 'Invoice',
                'properties' => ['id', 'amount', 'issued_at'],
                'relationships' => [
                    [
                        'type' => 'FOR_ORDER',
                        'target_label' => 'Order',
                        'foreign_key' => 'order_id',
                    ],
                ],
            ],
            'vector' => [
                'collection' => 'invoices',
                'embed_fields' => ['notes'],
                'metadata' => ['id', 'amount'],
            ],
            'metadata' => [
                'aliases' => ['invoice', 'bill', 'receipt'],
                'description' => 'Invoice documents',
            ],
        ];
    }
}

echo "✓ Invoice model uses complete manual configuration\n";
echo "  No auto-discovery, full control\n\n";

// ============================================================================
// Example 5: Using NodeableConfig Builder
// ============================================================================

echo "Example 5: NodeableConfig Builder\n";
echo str_repeat("-", 50) . "\n";

/**
 * Use the fluent builder API for clean, readable configuration.
 * Start from scratch with NodeableConfig::for()
 */
class Category extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'description'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Fluent builder approach
     */
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::for(static::class)
            ->label('Category')
            ->properties('id', 'name', 'description', 'created_at')
            ->relationship('CONTAINS', 'Product', null)
            ->collection('categories')
            ->embedFields('name', 'description')
            ->aliases('category', 'categories', 'product_group')
            ->description('Product categories');
    }
}

echo "✓ Category model uses fluent builder\n";
echo "  Clean, readable configuration\n\n";

// ============================================================================
// Example 6: Partial Builder with Discovery
// ============================================================================

echo "Example 6: Partial Builder with Discovery\n";
echo str_repeat("-", 50) . "\n";

/**
 * Combine discovery with builder for best of both worlds.
 * Start with discovered config, then override specific parts.
 */
class Review extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['product_id', 'user_id', 'rating', 'comment'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Start with discovery, override collection and embed fields
     */
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->collection('product_reviews') // Override collection name
            ->embedFields('comment'); // Only embed comment field
    }
}

echo "✓ Review model combines discovery with overrides\n";
echo "  Auto-discovers: properties, relationships, aliases\n";
echo "  Overrides: collection name, embed fields\n\n";

// ============================================================================
// Configuration Options
// ============================================================================

echo "Configuration Options\n";
echo str_repeat("-", 50) . "\n";
echo <<<CONFIG
# .env configuration options:

# Enable/disable auto-discovery globally
AI_AUTO_DISCOVERY_ENABLED=true

# Cache discovered configurations
AI_AUTO_DISCOVERY_CACHE=true
AI_AUTO_DISCOVERY_CACHE_TTL=3600

# config/ai.php customization:
'auto_discovery' => [
    'alias_mappings' => [
        'customers' => ['client', 'buyer'],
        'orders' => ['purchase', 'transaction'],
    ],
    'exclude_properties' => [
        'internal_notes',
        'admin_only_field',
    ],
],

CONFIG;
echo "\n";

// ============================================================================
// Usage with AI Facade
// ============================================================================

echo "Usage with AI Facade\n";
echo str_repeat("-", 50) . "\n";

// Note: These are examples, not actual execution
echo <<<USAGE
// All these models work seamlessly with AI facade

// Zero config model
\$product = Product::create([
    'name' => 'Widget',
    'description' => 'A useful widget',
    'price' => 29.99
]);
// Auto-synced to Neo4j and Qdrant

// Ask questions
AI::answerQuestion("Show all products in category Electronics");

// Selective override model
\$customer = Customer::create([
    'name' => 'Alice',
    'email' => 'alice@example.com',
]);
// Uses custom aliases: 'client', 'buyer'

AI::answerQuestion("Show all clients from USA");

// Complete manual override
\$invoice = Invoice::create([
    'order_id' => 1,
    'amount' => 100.00,
]);
// Uses manual configuration

USAGE;
echo "\n\n";

// ============================================================================
// Migration Path
// ============================================================================

echo "Migration Path from Manual Config\n";
echo str_repeat("-", 50) . "\n";
echo <<<MIGRATION
Step 1: Remove config/entities.php entries one by one
Step 2: Test that auto-discovery works correctly
Step 3: Add nodeableConfig() method only for customization
Step 4: Keep config/entities.php for complex cases

Backward compatibility:
- Existing config/entities.php files still work
- nodeableConfig() method takes priority
- Auto-discovery is the fallback

MIGRATION;
echo "\n\n";

echo "=== Demo Complete ===\n";
echo "Auto-discovery makes your models cleaner and more maintainable!\n\n";
