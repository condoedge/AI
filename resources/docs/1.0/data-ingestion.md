# Data Ingestion API

Complete guide to ingesting entities into Neo4j and Qdrant using the AI system.

---

## Overview

Data ingestion synchronizes your entities across:
- **Neo4j** - Graph database for relationships
- **Qdrant** - Vector database for semantic search

All ingestion happens automatically when you call `AI::ingest()` or use the `DataIngestionService`.

---

## Single Entity Ingestion

### Using AI Wrapper

```php
use Condoedge\Ai\Facades\AI;

$customer = Customer::find(1);
$status = AI::ingest($customer);
```

**Status Response:**
```php
[
    'graph_stored' => true,          // Stored in Neo4j
    'vector_stored' => true,         // Stored in Qdrant
    'relationships_created' => 2,    // Relationships created
    'errors' => []                   // Any errors
]
```

---

## Batch Ingestion

More efficient for multiple entities:

```php
$customers = Customer::all();
$result = AI::ingestBatch($customers->toArray());
```

**Summary Response:**
```php
[
    'total' => 100,
    'succeeded' => 98,
    'partially_succeeded' => 1,  // One store succeeded
    'failed' => 1,
    'errors' => [
        45 => ['Vector: Connection timeout']
    ]
]
```

---

## Sync Operation

Update if exists, create if not:

```php
$customer->name = 'Updated Name';
$customer->save();

$status = AI::sync($customer);
```

**Response:**
```php
[
    'action' => 'updated',  // or 'created'
    'graph_synced' => true,
    'vector_synced' => true,
    'errors' => []
]
```

---

## Remove Operation

Delete from both stores:

```php
$success = AI::remove($customer);
if ($success) {
    $customer->delete();
}
```

---

## Entity Configuration

Define in `config/entities.php`:

```php
'Customer' => [
    'graph' => [
        'label' => 'Customer',
        'properties' => ['id', 'name', 'email'],
        'relationships' => [
            [
                'type' => 'PURCHASED',
                'target_label' => 'Order',
                'foreign_key' => 'order_id'
            ]
        ]
    ],
    'vector' => [
        'collection' => 'customers',
        'embed_fields' => ['name', 'description'],
        'metadata' => ['id', 'email', 'status']
    ]
]
```

---

## Model Implementation

```php
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'description'];

    public function getId(): string|int
    {
        return $this->id;
    }
}
```

---

## Error Handling

### Graceful Degradation

If one store fails, the other continues:

```php
$status = AI::ingest($customer);

if (!empty($status['errors'])) {
    Log::warning('Partial ingestion failure', $status['errors']);
}

if ($status['graph_stored']) {
    // Neo4j succeeded
}

if ($status['vector_stored']) {
    // Qdrant succeeded
}
```

### Batch Error Handling

```php
$result = AI::ingestBatch($customers);

if ($result['failed'] > 0) {
    foreach ($result['errors'] as $entityId => $errors) {
        Log::error("Entity {$entityId}: " . implode(', ', $errors));
    }
}
```

---

## Automatic Sync with Observers

```php
use Condoedge\Ai\Facades\AI;

class CustomerObserver
{
    public function created(Customer $customer)
    {
        AI::ingest($customer);
    }

    public function updated(Customer $customer)
    {
        AI::sync($customer);
    }

    public function deleted(Customer $customer)
    {
        AI::remove($customer);
    }
}
```

Register in `AppServiceProvider`:

```php
public function boot()
{
    Customer::observe(CustomerObserver::class);
}
```

---

## Advanced: Direct Service Usage

```php
use Condoedge\Ai\Services\DataIngestionService;

$service = app(DataIngestionService::class);

// Single
$status = $service->ingest($customer);

// Batch
$result = $service->ingestBatch($customers);

// Sync
$status = $service->sync($customer);

// Remove
$success = $service->remove($customer);
```

---

See also: [Simple Usage](/docs/{{version}}/simple-usage) | [Configuration](/docs/{{version}}/configuration) | [Laravel Integration](/docs/{{version}}/laravel-integration)
