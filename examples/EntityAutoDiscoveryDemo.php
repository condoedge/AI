<?php

/**
 * Entity Auto-Discovery Demo
 *
 * Demonstrates how to use the EntityAutoDiscovery service to automatically
 * discover entity configuration from Eloquent models.
 *
 * Usage:
 *   php examples/EntityAutoDiscoveryDemo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Discovery\SchemaInspector;
use Condoedge\Ai\Services\Discovery\CypherScopeAdapter;
use Condoedge\Ai\Services\Discovery\PropertyDiscoverer;
use Condoedge\Ai\Services\Discovery\RelationshipDiscoverer;
use Condoedge\Ai\Services\Discovery\AliasGenerator;
use Condoedge\Ai\Services\Discovery\EmbedFieldDetector;
use Condoedge\Ai\Tests\Fixtures\TestCustomer;
use Condoedge\Ai\Tests\Fixtures\TestOrder;

// Initialize services manually for demo
$schema = new SchemaInspector();
$scopeAdapter = new CypherScopeAdapter();
$properties = new PropertyDiscoverer($schema);
$relationships = new RelationshipDiscoverer($schema);
$aliases = new AliasGenerator();
$embedFields = new EmbedFieldDetector($schema);

$discovery = new EntityAutoDiscovery(
    schema: $schema,
    scopeAdapter: $scopeAdapter,
    relationships: $relationships,
    properties: $properties,
    aliases: $aliases,
    embedFields: $embedFields
);

echo "=================================================================\n";
echo "Entity Auto-Discovery Demo\n";
echo "=================================================================\n\n";

// Demo 1: Discover complete configuration
echo "Demo 1: Discover Complete Configuration\n";
echo "-----------------------------------------------------------------\n";
$customerConfig = $discovery->discover(TestCustomer::class);
echo "Customer Configuration:\n";
echo json_encode($customerConfig, JSON_PRETTY_PRINT) . "\n\n";

// Demo 2: Discover only graph configuration
echo "Demo 2: Discover Graph Configuration Only\n";
echo "-----------------------------------------------------------------\n";
$graphConfig = $discovery->discoverGraph(TestCustomer::class);
echo "Graph Configuration:\n";
echo "  Label: {$graphConfig['label']}\n";
echo "  Properties: " . implode(', ', $graphConfig['properties']) . "\n";
echo "  Relationships: " . count($graphConfig['relationships']) . " discovered\n\n";

// Demo 3: Discover only vector configuration
echo "Demo 3: Discover Vector Configuration Only\n";
echo "-----------------------------------------------------------------\n";
$vectorConfig = $discovery->discoverVector(TestCustomer::class);
echo "Vector Configuration:\n";
echo "  Collection: {$vectorConfig['collection']}\n";
echo "  Embed Fields: " . implode(', ', $vectorConfig['embed_fields']) . "\n";
echo "  Metadata Fields: " . implode(', ', array_slice($vectorConfig['metadata'], 0, 5)) . "...\n\n";

// Demo 4: Discover metadata
echo "Demo 4: Discover Metadata\n";
echo "-----------------------------------------------------------------\n";
$metadataConfig = $discovery->discoverMetadata(TestCustomer::class);
echo "Metadata Configuration:\n";
echo "  Aliases: " . implode(', ', $metadataConfig['aliases']) . "\n";
echo "  Description: {$metadataConfig['description']}\n";
echo "  Scopes: " . implode(', ', array_keys($metadataConfig['scopes'])) . "\n\n";

// Demo 5: Merge with manual configuration
echo "Demo 5: Merge with Manual Configuration\n";
echo "-----------------------------------------------------------------\n";
$manualConfig = [
    'metadata' => [
        'aliases' => ['premium_customer', 'vip'],
        'description' => 'High-value customer entity',
    ],
];
$merged = $discovery->discoverAndMerge(TestCustomer::class, $manualConfig);
echo "Merged Configuration:\n";
echo "  Description: {$merged['metadata']['description']}\n";
echo "  Aliases: " . implode(', ', $merged['metadata']['aliases']) . "\n\n";

// Demo 6: Discover for Order model
echo "Demo 6: Discover Order Model\n";
echo "-----------------------------------------------------------------\n";
$orderConfig = $discovery->discover(TestOrder::class);
echo "Order Configuration:\n";
echo "  Label: {$orderConfig['graph']['label']}\n";
echo "  Properties: " . count($orderConfig['graph']['properties']) . " discovered\n";
echo "  Relationships: " . count($orderConfig['graph']['relationships']) . " discovered\n\n";

// Demo 7: Individual discoverer usage
echo "Demo 7: Using Individual Discoverers\n";
echo "-----------------------------------------------------------------\n";

echo "Property Discoverer:\n";
$props = $properties->discover(TestCustomer::class);
echo "  Discovered properties: " . implode(', ', array_slice($props, 0, 5)) . "...\n";

echo "\nAlias Generator:\n";
$generatedAliases = $aliases->generate(TestCustomer::class);
echo "  Generated aliases: " . implode(', ', $generatedAliases) . "\n";

echo "\nRelationship Discoverer:\n";
$rels = $relationships->discover(TestCustomer::class);
echo "  Discovered relationships: " . count($rels) . "\n";
foreach ($rels as $rel) {
    echo "    - {$rel['type']} -> {$rel['target_label']}\n";
}

echo "\nEmbed Field Detector:\n";
$embed = $embedFields->detect(TestCustomer::class);
echo "  Embed fields: " . (count($embed) > 0 ? implode(', ', $embed) : 'none') . "\n\n";

echo "=================================================================\n";
echo "Demo Complete!\n";
echo "=================================================================\n";
