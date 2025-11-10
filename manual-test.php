<?php

require 'vendor/autoload.php';

use AiSystem\Services\PatternLibrary;

// Test 1: Basic instantiation
echo "Test 1: Basic instantiation\n";
$lib = new PatternLibrary([]);
echo "✓ PatternLibrary created\n\n";

// Test 2: Load with patterns
echo "Test 2: Load with patterns\n";
$patterns = [
    'property_filter' => [
        'description' => 'Filter entities by property value',
        'parameters' => [
            'entity' => 'Entity label',
            'property' => 'Property name',
            'operator' => 'Comparison operator',
            'value' => 'Value to compare',
        ],
        'semantic_template' => 'Find {entity} where {property} {operator} {value}',
    ],
];
$lib2 = new PatternLibrary($patterns);
echo "✓ Pattern library loaded\n\n";

// Test 3: Get pattern
echo "Test 3: Get pattern\n";
$pattern = $lib2->getPattern('property_filter');
assert($pattern !== null, "Pattern should not be null");
assert($pattern['description'] === 'Filter entities by property value', "Description should match");
echo "✓ Pattern retrieved correctly\n\n";

// Test 4: Instantiate pattern
echo "Test 4: Instantiate pattern\n";
$result = $lib2->instantiatePattern('property_filter', [
    'entity' => 'Person',
    'property' => 'status',
    'operator' => 'equals',
    'value' => 'active',
]);
assert($result['pattern_name'] === 'property_filter', "Pattern name should match");
assert($result['semantic_description'] === 'Find Person where status equals active', "Description should match");
echo "✓ Pattern instantiated: " . $result['semantic_description'] . "\n\n";

// Test 5: Error handling - unknown pattern
echo "Test 5: Error handling - unknown pattern\n";
try {
    $lib2->instantiatePattern('unknown', []);
    echo "✗ Should have thrown exception\n";
} catch (\InvalidArgumentException $e) {
    echo "✓ Exception thrown correctly: " . $e->getMessage() . "\n\n";
}

// Test 6: Error handling - missing parameter
echo "Test 6: Error handling - missing parameter\n";
try {
    $lib2->instantiatePattern('property_filter', [
        'entity' => 'Person',
        // Missing other params
    ]);
    echo "✗ Should have thrown exception\n";
} catch (\InvalidArgumentException $e) {
    echo "✓ Exception thrown correctly: " . $e->getMessage() . "\n\n";
}

echo "\n=== ALL TESTS PASSED ===\n";
