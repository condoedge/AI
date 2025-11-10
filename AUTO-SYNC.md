# Auto-Sync: Automatic Entity Synchronization

## Overview

The Auto-Sync feature automatically synchronizes your Eloquent models with Neo4j and Qdrant whenever they are created, updated, or deleted. No manual `AI::ingest()` calls needed!

## How It Works

When you use the `HasNodeableConfig` trait on a model that implements `Nodeable`, the trait automatically registers Laravel model events that sync your entities to the AI system:

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;  // That's it! Auto-sync is enabled
}

// Now these operations automatically sync to Neo4j and Qdrant:
$customer = Customer::create(['name' => 'Alice']);  // ✅ Auto-ingested
$customer->update(['name' => 'Alice Smith']);        // ✅ Auto-synced
$customer->delete();                                  // ✅ Auto-removed
```

## Configuration

### Global Configuration

Edit `config/ai.php` to control auto-sync globally:

```php
'auto_sync' => [
    // Enable/disable auto-sync for all entities
    'enabled' => env('AI_AUTO_SYNC_ENABLED', true),

    // Queue sync operations for async processing
    'queue' => env('AI_AUTO_SYNC_QUEUE', false),

    // Queue connection and name
    'queue_connection' => env('AI_AUTO_SYNC_QUEUE_CONNECTION', null),
    'queue_name' => env('AI_AUTO_SYNC_QUEUE_NAME', 'default'),

    // Control which operations trigger sync
    'operations' => [
        'create' => true,  // Sync on model creation
        'update' => true,  // Sync on model update
        'delete' => true,  // Remove on model deletion
    ],

    // Error handling
    'fail_silently' => true,  // Don't throw exceptions on sync errors
    'log_errors' => true,     // Log sync errors to Laravel log

    // Performance
    'eager_load_relationships' => true,  // Load relationships before sync
],
```

### Environment Variables

Add to your `.env` file:

```bash
# Enable/disable auto-sync
AI_AUTO_SYNC_ENABLED=true

# Queue sync operations (recommended for production)
AI_AUTO_SYNC_QUEUE=false

# Queue configuration
AI_AUTO_SYNC_QUEUE_CONNECTION=redis
AI_AUTO_SYNC_QUEUE_NAME=ai-sync

# Which operations to sync
AI_AUTO_SYNC_CREATE=true
AI_AUTO_SYNC_UPDATE=true
AI_AUTO_SYNC_DELETE=true

# Error handling
AI_AUTO_SYNC_FAIL_SILENTLY=true
AI_AUTO_SYNC_LOG_ERRORS=true

# Performance
AI_AUTO_SYNC_EAGER_LOAD=true
```

### Per-Entity Configuration

Override settings for specific entities in `config/entities.php`:

```php
return [
    'Customer' => [
        'graph' => [...],
        'vector' => [...],

        // Disable auto-sync for this entity only
        'auto_sync' => false,

        // Or control per operation
        'auto_sync' => [
            'create' => true,
            'update' => true,
            'delete' => false,  // Don't auto-remove on delete
        ],
    ],
];
```

### Per-Model Configuration

Override settings directly in the model class:

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    // Disable auto-sync for this model
    protected $aiAutoSync = false;

    // Enable queueing for this model only
    protected $aiSyncQueue = true;

    // Disable eager loading
    protected $aiEagerLoadRelationships = false;

    // Specify relationships to load
    protected $aiSyncRelationships = ['orders', 'addresses'];
}
```

## Queueing (Async Processing)

For production, it's recommended to queue sync operations to avoid blocking your application:

### 1. Enable Queueing

```bash
# .env
AI_AUTO_SYNC_QUEUE=true
AI_AUTO_SYNC_QUEUE_CONNECTION=redis
AI_AUTO_SYNC_QUEUE_NAME=ai-sync
```

### 2. Run Queue Worker

```bash
# Process ai-sync queue
php artisan queue:work redis --queue=ai-sync

# Or use Supervisor for production
```

### 3. Queue Jobs

Three queue jobs are automatically dispatched:
- `IngestEntityJob` - Created entities
- `SyncEntityJob` - Updated entities
- `RemoveEntityJob` - Deleted entities

Each job:
- Retries 3 times on failure
- Has a 120-second timeout
- Logs success/failure
- Tagged for Horizon monitoring

## Relationship Loading

By default, the system automatically loads relationships before syncing to ensure Neo4j has complete data:

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // Automatically inferred from GraphConfig
    // Will load 'orders' relationship before sync
}
```

### Specify Relationships Manually

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    // Explicitly define which relationships to load
    protected $aiSyncRelationships = ['orders', 'addresses', 'profile'];
}
```

### Disable Relationship Loading

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    // Disable automatic relationship loading
    protected $aiEagerLoadRelationships = false;
}
```

## Error Handling

### Silent Failure (Default)

By default, sync errors won't crash your application but will be logged:

```php
// This won't throw an exception even if Neo4j is down
$customer = Customer::create(['name' => 'Alice']);

// But errors are logged to storage/logs/laravel.log
```

### Throw Exceptions

To throw exceptions on sync errors:

```php
// config/ai.php
'auto_sync' => [
    'fail_silently' => false,  // Throw exceptions
],
```

Now sync errors will propagate:

```php
try {
    $customer = Customer::create(['name' => 'Alice']);
} catch (\Throwable $e) {
    // Handle Neo4j/Qdrant connection errors
}
```

### Custom Error Handling

Monitor the Laravel log for auto-sync errors:

```php
// storage/logs/laravel.log
[2024-01-15 10:30:00] local.ERROR: AI auto-sync failed for create operation
{
    "model": "App\\Models\\Customer",
    "id": 123,
    "operation": "create",
    "error": "Connection refused",
    "trace": "..."
}
```

## Disabling Auto-Sync

### Globally

```bash
# .env
AI_AUTO_SYNC_ENABLED=false
```

### For Specific Operations

```bash
# .env
AI_AUTO_SYNC_CREATE=true
AI_AUTO_SYNC_UPDATE=true
AI_AUTO_SYNC_DELETE=false  # Don't remove from Neo4j on delete
```

### For Specific Entity

```php
// config/entities.php
'Customer' => [
    'auto_sync' => false,  // No auto-sync for customers
],
```

### For Specific Model

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $aiAutoSync = false;  // Disable for this model
}
```

### Temporarily in Code

```php
// Disable auto-sync for a block of code
config(['ai.auto_sync.enabled' => false]);

Customer::create(['name' => 'Alice']);  // Won't sync

config(['ai.auto_sync.enabled' => true]);  // Re-enable
```

## Performance Considerations

### 1. Use Queueing in Production

```bash
AI_AUTO_SYNC_QUEUE=true
```

Prevents blocking HTTP requests while syncing to Neo4j/Qdrant.

### 2. Disable for Bulk Operations

```php
// Temporarily disable for bulk import
config(['ai.auto_sync.enabled' => false]);

foreach ($customers as $data) {
    Customer::create($data);  // Fast, no sync
}

config(['ai.auto_sync.enabled' => true]);

// Manually sync in batch
AI::ingestBatch(Customer::all());
```

### 3. Selective Relationship Loading

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    // Only load specific relationships
    protected $aiSyncRelationships = ['orders'];  // Don't load all

    // Or disable completely for simple entities
    protected $aiEagerLoadRelationships = false;
}
```

### 4. Tune Queue Workers

```bash
# Run multiple workers for high throughput
php artisan queue:work redis --queue=ai-sync --tries=3 --timeout=120
```

## Testing with Auto-Sync

### Disable in Tests

```php
// tests/TestCase.php
protected function setUp(): void
{
    parent::setUp();

    // Disable auto-sync for faster tests
    config(['ai.auto_sync.enabled' => false]);
}
```

### Test Auto-Sync Behavior

```php
public function test_customer_auto_syncs_on_creation()
{
    // Enable auto-sync
    config(['ai.auto_sync.enabled' => true]);

    // Mock the AI facade
    AI::shouldReceive('ingest')
        ->once()
        ->with(Mockery::type(Customer::class));

    // Create customer
    $customer = Customer::create(['name' => 'Alice']);

    // Assert sync was called
    AI::shouldHaveReceived('ingest');
}
```

### Test Queue Jobs

```php
public function test_customer_creation_dispatches_job()
{
    Queue::fake();

    config(['ai.auto_sync.queue' => true]);

    Customer::create(['name' => 'Alice']);

    Queue::assertPushed(IngestEntityJob::class);
}
```

## Migration Guide

### Before (Manual Sync)

```php
// Old way: Manual syncing
$customer = Customer::create(['name' => 'Alice']);
AI::ingest($customer);  // ❌ Manual call required

$customer->update(['name' => 'Alice Smith']);
AI::sync($customer);  // ❌ Manual call required

$customer->delete();
AI::remove($customer);  // ❌ Manual call required
```

### After (Auto-Sync)

```php
// New way: Automatic syncing
$customer = Customer::create(['name' => 'Alice']);
// ✅ Automatically ingested!

$customer->update(['name' => 'Alice Smith']);
// ✅ Automatically synced!

$customer->delete();
// ✅ Automatically removed!
```

## Advanced Usage

### Conditional Sync

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected static function bootHasNodeableConfig(): void
    {
        parent::bootHasNodeableConfig();

        // Add custom logic before auto-sync
        static::creating(function ($customer) {
            // Only sync verified customers
            if (!$customer->is_verified) {
                $customer->aiAutoSync = false;
            }
        });
    }
}
```

### Sync-Specific Data

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function getNodeProperties(): array
    {
        // Customize what gets synced to Neo4j
        return array_merge(parent::getNodeProperties(), [
            'synced_at' => now()->toIso8601String(),
        ]);
    }
}
```

### Monitor Sync Operations

```php
// Use Laravel Horizon to monitor queue jobs
// Or add custom event listeners

Event::listen('eloquent.created: App\\Models\\Customer', function ($customer) {
    Log::info("Customer {$customer->id} created and will be synced");
});
```

## Troubleshooting

### Auto-Sync Not Working

1. **Check configuration:**
   ```php
   config('ai.auto_sync.enabled');  // Should be true
   ```

2. **Verify trait is used:**
   ```php
   class Customer extends Model implements Nodeable
   {
       use HasNodeableConfig;  // ← Must be present
   }
   ```

3. **Check logs:**
   ```bash
   tail -f storage/logs/laravel.log | grep "AI auto-sync"
   ```

4. **Test manually:**
   ```php
   AI::ingest($customer);  // Does manual sync work?
   ```

### Queue Jobs Not Processing

1. **Check queue worker is running:**
   ```bash
   php artisan queue:work redis --queue=ai-sync
   ```

2. **Check failed jobs:**
   ```bash
   php artisan queue:failed
   ```

3. **Retry failed jobs:**
   ```bash
   php artisan queue:retry all
   ```

### Performance Issues

1. **Enable queueing:**
   ```bash
   AI_AUTO_SYNC_QUEUE=true
   ```

2. **Disable for bulk operations:**
   ```php
   config(['ai.auto_sync.enabled' => false]);
   // ... bulk create
   config(['ai.auto_sync.enabled' => true]);
   ```

3. **Optimize relationship loading:**
   ```php
   protected $aiSyncRelationships = ['orders'];  // Only essential ones
   ```

## Summary

Auto-Sync makes keeping Neo4j and Qdrant in sync with your database effortless:

✅ **Zero Boilerplate** - Just use the trait
✅ **Flexible** - Configure globally, per-entity, or per-model
✅ **Production-Ready** - Queue support with retry logic
✅ **Error-Resilient** - Fails silently by default, logs all errors
✅ **Testable** - Easy to mock and disable in tests
✅ **Performance** - Async processing, selective relationship loading

```php
// That's all you need!
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;
}
```
