<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Condoedge\Ai\Domain\Contracts\Nodeable;

/**
 * Test Order Model
 *
 * Used for feature testing the AI system with real data
 *
 * @property int $id
 * @property int $customer_id
 * @property string $order_number
 * @property float $total
 * @property string $status
 * @property \Illuminate\Support\Carbon $order_date
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class TestOrder extends Model implements Nodeable
{
    use HasFactory, HasNodeableConfig;

    protected $table = 'test_orders';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Condoedge\Ai\Tests\Database\Factories\TestOrderFactory::new();
    }

    protected $fillable = [
        'customer_id',
        'order_number',
        'total',
        'status',
        'order_date',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'order_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the customer that placed this order
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(TestCustomer::class, 'customer_id');
    }

    // ========================================
    // Nodeable Interface Implementation
    // ========================================

    /**
     * Get the node label for Neo4j
     */
    public function getNodeLabel(): string
    {
        return 'Order';
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
            'order_number' => $this->order_number,
            'total' => (float) $this->total,
            'status' => $this->status,
            'order_date' => $this->order_date->toDateString(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * Get relationships to other entities
     */
    public function getNodeRelationships(): array
    {
        $relationships = [];

        // Add relationship to customer
        if ($this->customer) {
            $relationships[] = [
                'type' => 'PLACED_BY',
                'direction' => 'outgoing',
                'target_label' => 'Customer',
                'target_id' => $this->customer_id,
                'properties' => [
                    'order_date' => $this->order_date->toDateString(),
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
        $customerName = $this->customer ? $this->customer->name : 'Unknown';

        return sprintf(
            "Order %s placed by %s on %s for $%.2f. Status: %s.",
            $this->order_number,
            $customerName,
            $this->order_date->format('Y-m-d'),
            $this->total,
            $this->status
        );
    }

    /**
     * Get metadata for vector store
     */
    public function getEmbeddingMetadata(): array
    {
        return [
            'entity_type' => 'Order',
            'entity_id' => $this->id,
            'customer_id' => $this->customer_id,
            'status' => $this->status,
            'total' => (float) $this->total,
            'order_date' => $this->order_date->toDateString(),
        ];
    }

    // ========================================
    // Query Scopes for Testing CypherScopeAdapter
    // ========================================

    /**
     * Scope: Pending orders
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Completed orders
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: High value orders
     */
    public function scopeHighValue($query)
    {
        return $query->where('total', '>', 1000);
    }

    /**
     * Scope: Recent orders (date filter)
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->whereDate('order_date', '>=', now()->subDays($days));
    }
}
