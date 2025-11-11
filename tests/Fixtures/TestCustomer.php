<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Condoedge\Ai\Domain\Contracts\Nodeable;

/**
 * Test Customer Model
 *
 * Used for feature testing the AI system with real data
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $status
 * @property string|null $country
 * @property float $lifetime_value
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class TestCustomer extends Model implements Nodeable
{
    use HasFactory, HasNodeableConfig;

    protected $table = 'test_customers';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Condoedge\Ai\Tests\Database\Factories\TestCustomerFactory::new();
    }

    protected $fillable = [
        'name',
        'email',
        'status',
        'country',
        'lifetime_value',
    ];

    protected $casts = [
        'lifetime_value' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the orders for this customer
     */
    public function orders(): HasMany
    {
        return $this->hasMany(TestOrder::class, 'customer_id');
    }

    // ========================================
    // Nodeable Interface Implementation
    // ========================================

    /**
     * Get the node label for Neo4j
     */
    public function getNodeLabel(): string
    {
        return 'Customer';
    }

    /**
     * Get the unique identifier for the node
     */
    public function getNodeId(): string|int
    {
        return $this->id;
    }

    /**
     * Get properties to store in the graph node
     */
    public function getNodeProperties(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'country' => $this->country,
            'lifetime_value' => (float) $this->lifetime_value,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * Get relationships to other entities
     */
    public function getNodeRelationships(): array
    {
        $relationships = [];

        // Add relationships to orders
        foreach ($this->orders as $order) {
            $relationships[] = [
                'type' => 'PLACED',
                'direction' => 'outgoing',
                'target_label' => 'Order',
                'target_id' => $order->id,
                'properties' => [
                    'placed_at' => $order->created_at->toIso8601String(),
                ],
            ];
        }

        return $relationships;
    }

    /**
     * Get text representation for embedding
     */
    public function getEmbeddingText(): string
    {
        return sprintf(
            "Customer %s (%s) from %s with status %s. Lifetime value: $%.2f. Has %d orders.",
            $this->name,
            $this->email,
            $this->country ?? 'Unknown',
            $this->status,
            $this->lifetime_value,
            $this->orders()->count()
        );
    }

    /**
     * Get metadata for vector store
     */
    public function getEmbeddingMetadata(): array
    {
        return [
            'entity_type' => 'Customer',
            'entity_id' => $this->id,
            'status' => $this->status,
            'country' => $this->country,
            'order_count' => $this->orders()->count(),
        ];
    }

    // ========================================
    // Query Scopes for Testing CypherScopeAdapter
    // ========================================

    /**
     * Scope: Active customers
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: Inactive customers
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope: High value customers
     */
    public function scopeHighValue($query)
    {
        return $query->where('lifetime_value', '>', 1000);
    }

    /**
     * Scope: Customers from specific country
     */
    public function scopeFromCountry($query, $country)
    {
        return $query->where('country', $country);
    }

    /**
     * Scope: Customers with orders (relationship)
     */
    public function scopeWithOrders($query)
    {
        return $query->whereHas('orders');
    }

    /**
     * Scope: Customers with completed orders (relationship with nested condition)
     */
    public function scopeWithCompletedOrders($query)
    {
        return $query->whereHas('orders', function($q) {
            $q->where('status', 'completed');
        });
    }

    /**
     * Scope: VIP customers (multiple conditions)
     */
    public function scopeVip($query)
    {
        return $query->where('status', 'active')
                     ->where('lifetime_value', '>=', 5000);
    }

    /**
     * Scope: Customers with null country
     */
    public function scopeWithoutCountry($query)
    {
        return $query->whereNull('country');
    }

    /**
     * Scope: Customers in specific countries
     */
    public function scopeInCountries($query, array $countries)
    {
        return $query->whereIn('country', $countries);
    }
}
