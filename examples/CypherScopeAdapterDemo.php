<?php

/**
 * CypherScope Adapter Demo
 *
 * Demonstrates how to use the CypherScope Adapter to automatically convert
 * Eloquent scopes to Cypher patterns for the RAG system.
 *
 * Usage:
 *   php examples/CypherScopeAdapterDemo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;
use Condoedge\Ai\Services\Discovery\CypherQueryBuilderSpy;
use Condoedge\Ai\Services\Discovery\CypherPatternGenerator;
use Condoedge\Ai\Tests\Fixtures\TestCustomer;
use Condoedge\Ai\Tests\Fixtures\TestOrder;

echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║                  CypherScope Adapter Demonstration                       ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// Example 1: Basic Query Builder Spy
// ============================================================================
echo "┌─ Example 1: Query Builder Spy ────────────────────────────────────────┐\n";
echo "│ The spy records Eloquent method calls for conversion to Cypher        │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$spy = new CypherQueryBuilderSpy();
$spy->where('status', 'active')
    ->where('lifetime_value', '>', 5000)
    ->whereIn('country', ['US', 'CA', 'UK'])
    ->whereNotNull('email');

$calls = $spy->getCalls();
echo "✓ Recorded " . count($calls) . " method calls:\n\n";

foreach ($calls as $i => $call) {
    echo "  " . ($i + 1) . ". {$call['method']}(";
    if ($call['method'] === 'whereIn') {
        echo "'{$call['column']}', [" . implode(', ', array_map(fn($v) => "'$v'", $call['values'])) . "])";
    } else if (isset($call['column'])) {
        echo "'{$call['column']}'";
        if (isset($call['operator']) && isset($call['value'])) {
            echo ", '{$call['operator']}', ";
            echo is_numeric($call['value']) ? $call['value'] : "'{$call['value']}'";
        }
    }
    echo ")\n";
}
echo "\n";

// ============================================================================
// Example 2: Pattern Generator
// ============================================================================
echo "┌─ Example 2: Cypher Pattern Generator ─────────────────────────────────┐\n";
echo "│ Converts recorded calls to Neo4j Cypher syntax                        │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$generator = new CypherPatternGenerator();
$pattern = $generator->generate($calls);

echo "✓ Generated Cypher Pattern:\n\n";
echo "  " . $pattern . "\n\n";

// ============================================================================
// Example 3: Discover Scopes in TestCustomer Model
// ============================================================================
echo "┌─ Example 3: Auto-Discover Model Scopes ───────────────────────────────┐\n";
echo "│ Automatically detects all scope methods in a model                    │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$adapter = new CypherScopeAdapter();
$customerScopes = $adapter->discoverScopes(TestCustomer::class);

echo "✓ Found " . count($customerScopes) . " scopes in TestCustomer:\n\n";

$scopesByType = [];
foreach ($customerScopes as $name => $data) {
    $type = $data['specification_type'];
    $scopesByType[$type][] = $name;
}

foreach ($scopesByType as $type => $names) {
    echo "  {$type}:\n";
    foreach ($names as $name) {
        echo "    • {$name}\n";
    }
    echo "\n";
}

// ============================================================================
// Example 4: Property Filter Scope (Simple)
// ============================================================================
echo "┌─ Example 4: Property Filter Scope ─────────────────────────────────────┐\n";
echo "│ Simple where condition converted to Cypher                            │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$activeScope = $customerScopes['active'];

echo "Scope: active\n";
echo str_repeat("─", 76) . "\n";
echo "Type:           {$activeScope['specification_type']}\n";
echo "Concept:        {$activeScope['concept']}\n";
echo "Cypher Pattern: {$activeScope['cypher_pattern']}\n";
echo "Filter:         " . json_encode($activeScope['filter']) . "\n";
echo "\nGenerated Examples:\n";
foreach ($activeScope['examples'] as $i => $example) {
    echo "  " . ($i + 1) . ". {$example}\n";
}
echo "\n";

// ============================================================================
// Example 5: Property Filter with Comparison
// ============================================================================
echo "┌─ Example 5: Comparison Operator Scope ────────────────────────────────┐\n";
echo "│ Handles greater than, less than, etc.                                 │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$highValueScope = $customerScopes['high_value'];

echo "Scope: high_value\n";
echo str_repeat("─", 76) . "\n";
echo "Type:           {$highValueScope['specification_type']}\n";
echo "Concept:        {$highValueScope['concept']}\n";
echo "Cypher Pattern: {$highValueScope['cypher_pattern']}\n";
echo "\nGenerated Examples:\n";
foreach ($highValueScope['examples'] as $i => $example) {
    echo "  " . ($i + 1) . ". {$example}\n";
}
echo "\n";

// ============================================================================
// Example 6: Multiple Conditions (AND)
// ============================================================================
echo "┌─ Example 6: Multiple Conditions (VIP Scope) ──────────────────────────┐\n";
echo "│ Combines multiple where clauses with AND                              │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$vipScope = $customerScopes['vip'];

echo "Scope: vip\n";
echo str_repeat("─", 76) . "\n";
echo "Type:           {$vipScope['specification_type']}\n";
echo "Concept:        {$vipScope['concept']}\n";
echo "Cypher Pattern: {$vipScope['cypher_pattern']}\n";
echo "\n";

// ============================================================================
// Example 7: whereIn Scope
// ============================================================================
echo "┌─ Example 7: WhereIn Scope ─────────────────────────────────────────────┐\n";
echo "│ Handles IN operator for multiple values                               │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$inCountriesScope = $customerScopes['in_countries'];

echo "Scope: in_countries\n";
echo str_repeat("─", 76) . "\n";
echo "Type:           {$inCountriesScope['specification_type']}\n";
echo "Concept:        {$inCountriesScope['concept']}\n";
echo "Cypher Pattern: {$inCountriesScope['cypher_pattern']}\n";
echo "\n";

// ============================================================================
// Example 8: whereNull Scope
// ============================================================================
echo "┌─ Example 8: WhereNull Scope ───────────────────────────────────────────┐\n";
echo "│ Handles IS NULL checks                                                │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$withoutCountryScope = $customerScopes['without_country'];

echo "Scope: without_country\n";
echo str_repeat("─", 76) . "\n";
echo "Type:           {$withoutCountryScope['specification_type']}\n";
echo "Concept:        {$withoutCountryScope['concept']}\n";
echo "Cypher Pattern: {$withoutCountryScope['cypher_pattern']}\n";
echo "\n";

// ============================================================================
// Example 9: Relationship Traversal (whereHas)
// ============================================================================
echo "┌─ Example 9: Relationship Traversal (Simple) ──────────────────────────┐\n";
echo "│ Converts whereHas to MATCH pattern                                    │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$withOrdersScope = $customerScopes['with_orders'];

echo "Scope: with_orders\n";
echo str_repeat("─", 76) . "\n";
echo "Type:           {$withOrdersScope['specification_type']}\n";
echo "Concept:        {$withOrdersScope['concept']}\n";
echo "Cypher Pattern:\n";
echo "  {$withOrdersScope['cypher_pattern']}\n";
echo "\nParsed Structure:\n";
echo json_encode($withOrdersScope['parsed_structure'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// ============================================================================
// Example 10: Relationship with Nested Conditions
// ============================================================================
echo "┌─ Example 10: Relationship with Conditions ────────────────────────────┐\n";
echo "│ whereHas with nested query builder calls                              │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$completedOrdersScope = $customerScopes['with_completed_orders'];

echo "Scope: with_completed_orders\n";
echo str_repeat("─", 76) . "\n";
echo "Type:           {$completedOrdersScope['specification_type']}\n";
echo "Concept:        {$completedOrdersScope['concept']}\n";
echo "Cypher Pattern:\n";
echo "  {$completedOrdersScope['cypher_pattern']}\n";
echo "\nParsed Structure:\n";
echo json_encode($completedOrdersScope['parsed_structure'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "\nGenerated Examples:\n";
foreach ($completedOrdersScope['examples'] as $i => $example) {
    echo "  " . ($i + 1) . ". {$example}\n";
}
echo "\n";

// ============================================================================
// Example 11: Order Model Scopes
// ============================================================================
echo "┌─ Example 11: Order Model Scopes ──────────────────────────────────────┐\n";
echo "│ Works with any Eloquent model                                         │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

$orderScopes = $adapter->discoverScopes(TestOrder::class);

echo "✓ Found " . count($orderScopes) . " scopes in TestOrder:\n\n";

foreach ($orderScopes as $name => $data) {
    echo "  {$name}:\n";
    echo "    Pattern: {$data['cypher_pattern']}\n\n";
}

// ============================================================================
// Example 12: Integration with Entity Config
// ============================================================================
echo "┌─ Example 12: Integration with Entity Config ──────────────────────────┐\n";
echo "│ Output format matches config/entities.php structure                   │\n";
echo "└────────────────────────────────────────────────────────────────────────┘\n\n";

echo "✓ Example config/entities.php structure:\n\n";
echo "```php\n";
echo "'Customer' => [\n";
echo "    'metadata' => [\n";
echo "        'scopes' => [\n";

// Show first 3 scopes as example
$count = 0;
foreach ($customerScopes as $name => $data) {
    if ($count++ >= 3) break;

    echo "            '{$name}' => [\n";
    echo "                'specification_type' => '{$data['specification_type']}',\n";
    echo "                'concept' => '{$data['concept']}',\n";
    echo "                'cypher_pattern' => \"{$data['cypher_pattern']}\",\n";
    if (isset($data['filter']) && !empty($data['filter'])) {
        echo "                'filter' => " . str_replace(["\n", "  "], ['', ''], json_encode($data['filter'])) . ",\n";
    }
    echo "                'examples' => [\n";
    foreach (array_slice($data['examples'], 0, 2) as $example) {
        echo "                    '{$example}',\n";
    }
    echo "                ],\n";
    echo "            ],\n";
}

echo "            // ... more scopes\n";
echo "        ],\n";
echo "    ],\n";
echo "],\n";
echo "```\n\n";

// ============================================================================
// Summary
// ============================================================================
echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║                             Summary                                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

echo "The CypherScope Adapter provides:\n\n";
echo "  ✓ Automatic discovery of Eloquent scopes\n";
echo "  ✓ Conversion to Neo4j Cypher patterns\n";
echo "  ✓ Support for all common query builder methods\n";
echo "  ✓ Relationship traversal (whereHas)\n";
echo "  ✓ Auto-generated examples for RAG\n";
echo "  ✓ Direct integration with entity configs\n\n";

echo "Supported Methods:\n";
echo "  • where, orWhere\n";
echo "  • whereIn, whereNotIn\n";
echo "  • whereNull, whereNotNull\n";
echo "  • whereHas, whereDoesntHave\n";
echo "  • whereDate, whereTime\n";
echo "  • whereBetween, whereNotBetween\n";
echo "  • whereColumn\n\n";

echo "Next Steps:\n";
echo "  1. Add CypherScope Adapter to your model discovery pipeline\n";
echo "  2. Automatically populate entity configs\n";
echo "  3. Developers write familiar Eloquent scopes\n";
echo "  4. System automatically generates Cypher patterns\n\n";

echo "✨ Demo completed successfully!\n";
