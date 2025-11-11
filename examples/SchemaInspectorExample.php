<?php

/**
 * Schema Inspector Usage Example
 *
 * Demonstrates how to use the SchemaInspector service to extract
 * strategic hints from your database schema for auto-discovery.
 */

require __DIR__ . '/../vendor/autoload.php';

use Condoedge\Ai\Services\Discovery\SchemaInspector;
use Illuminate\Support\Facades\Schema;

// Initialize the inspector
$inspector = new SchemaInspector();

// Example 1: Get Foreign Keys
echo "=== Foreign Keys Detection ===\n";
$foreignKeys = $inspector->getForeignKeys('orders');
foreach ($foreignKeys as $column => $reference) {
    echo "Column '{$column}' references '{$reference['table']}.{$reference['column']}'\n";
}
// Output:
// Column 'customer_id' references 'customers.id'
// Column 'product_id' references 'products.id'

echo "\n";

// Example 2: Get Text Columns (suitable for embeddings)
echo "=== Text Columns for Embeddings ===\n";
$textColumns = $inspector->getTextColumns('products');
foreach ($textColumns as $column) {
    echo "Column '{$column}' is suitable for embeddings\n";
}
// Output:
// Column 'description' is suitable for embeddings
// Column 'details' is suitable for embeddings
// Column 'notes' is suitable for embeddings

echo "\n";

// Example 3: Get Indexed Columns (important properties)
echo "=== Indexed Columns (Important Properties) ===\n";
$indexedColumns = $inspector->getIndexedColumns('users');
foreach ($indexedColumns as $column) {
    echo "Column '{$column}' is indexed (likely important)\n";
}
// Output:
// Column 'email' is indexed (likely important)
// Column 'status' is indexed (likely important)

echo "\n";

// Example 4: Get All Column Types
echo "=== All Column Types ===\n";
$columnTypes = $inspector->getColumnTypes('users');
foreach ($columnTypes as $column => $type) {
    echo "Column '{$column}' is of type '{$type}'\n";
}
// Output:
// Column 'id' is of type 'integer'
// Column 'name' is of type 'string'
// Column 'email' is of type 'string'
// Column 'bio' is of type 'text'
// Column 'active' is of type 'boolean'

echo "\n";

// Example 5: Using for Auto-Discovery
echo "=== Auto-Discovery Configuration ===\n";

$table = 'products';

// Get all strategic hints
$foreignKeys = $inspector->getForeignKeys($table);
$textColumns = $inspector->getTextColumns($table);
$indexedColumns = $inspector->getIndexedColumns($table);
$columnTypes = $inspector->getColumnTypes($table);

// Build entity configuration
$config = [
    'label' => ucfirst($table),
    'searchable_properties' => array_merge($indexedColumns, $textColumns),
    'relationships' => [],
    'embeddable_fields' => $textColumns,
];

// Add relationships from foreign keys
foreach ($foreignKeys as $column => $reference) {
    $relationName = str_replace('_id', '', $column);
    $config['relationships'][$relationName] = [
        'type' => 'BELONGS_TO',
        'target' => ucfirst($reference['table']),
        'foreign_key' => $column,
    ];
}

echo "Auto-generated config for '{$table}':\n";
print_r($config);

echo "\n";

// Example 6: Cache Management
echo "=== Cache Management ===\n";

// Cached calls are fast
$start = microtime(true);
$inspector->getForeignKeys('users'); // First call - cache miss
$firstCallTime = microtime(true) - $start;

$start = microtime(true);
$inspector->getForeignKeys('users'); // Second call - cache hit
$secondCallTime = microtime(true) - $start;

echo sprintf(
    "First call: %.4fms, Second call: %.4fms (%.2fx faster)\n",
    $firstCallTime * 1000,
    $secondCallTime * 1000,
    $firstCallTime / max($secondCallTime, 0.000001)
);

// Clear cache when schema changes
$inspector->clearCache('users');
echo "Cache cleared for 'users' table\n";

// Or clear all caches
$inspector->clearAllCaches();
echo "All schema caches cleared\n";

echo "\n";

// Example 7: Cross-Database Support
echo "=== Cross-Database Support ===\n";

// The inspector automatically detects your database driver
// and uses the appropriate query syntax:
// - MySQL: INFORMATION_SCHEMA queries
// - PostgreSQL: pg_catalog queries
// - SQLite: PRAGMA statements

$driver = DB::connection()->getDriverName();
echo "Current database driver: {$driver}\n";
echo "SchemaInspector will automatically use {$driver}-specific queries\n";
