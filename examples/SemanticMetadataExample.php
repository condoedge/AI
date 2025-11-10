<?php

/**
 * Semantic Metadata System - Usage Examples
 *
 * This file demonstrates how to use the new semantic metadata system
 * that is maximally configurable and minimally concrete.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AiSystem\Services\ContextRetriever;
use AiSystem\Services\QueryGenerator;
use AiSystem\Services\PatternLibrary;
use AiSystem\Services\SemanticPromptBuilder;
use AiSystem\Stores\Neo4jStore;
use AiSystem\Stores\QdrantStore;
use AiSystem\Providers\OpenAiEmbeddingProvider;
use AiSystem\Providers\OpenAiLlmProvider;

/*
|--------------------------------------------------------------------------
| Example 1: Pattern Library Usage
|--------------------------------------------------------------------------
|
| The pattern library provides reusable, generic query patterns.
|
*/

echo "=== EXAMPLE 1: Pattern Library ===\n\n";

// Load pattern library
$patternLibrary = new PatternLibrary();

// Get all available patterns
$patterns = $patternLibrary->getAllPatterns();

echo "Available Patterns:\n";
foreach ($patterns as $name => $pattern) {
    echo "- {$name}: {$pattern['description']}\n";
}
echo "\n";

// Instantiate a pattern with specific parameters
$instantiated = $patternLibrary->instantiatePattern('property_filter', [
    'entity' => 'Person',
    'property' => 'status',
    'operator' => 'equals',
    'value' => 'active',
]);

echo "Instantiated Pattern:\n";
echo "Pattern Name: {$instantiated['pattern_name']}\n";
echo "Semantic Description: {$instantiated['semantic_description']}\n";
echo "\n";

/*
|--------------------------------------------------------------------------
| Example 2: Semantic Scope Detection
|--------------------------------------------------------------------------
|
| Entity metadata now uses semantic descriptions instead of Cypher.
|
*/

echo "=== EXAMPLE 2: Semantic Scope Detection ===\n\n";

// Initialize services
$graphStore = new Neo4jStore([...]);
$vectorStore = new QdrantStore([...]);
$embeddingProvider = new OpenAiEmbeddingProvider([...]);

$retriever = new ContextRetriever(
    $vectorStore,
    $graphStore,
    $embeddingProvider
);

// Ask a question about volunteers
$question = "Show me all volunteers";

// Retrieve metadata
$metadata = $retriever->getEntityMetadata($question);

echo "Question: {$question}\n\n";

echo "Detected Entities:\n";
foreach ($metadata['detected_entities'] as $entity) {
    echo "- {$entity}\n";
}
echo "\n";

echo "Detected Scopes:\n";
foreach ($metadata['detected_scopes'] as $scopeName => $scope) {
    echo "- {$scopeName}\n";
    echo "  Entity: {$scope['entity']}\n";
    echo "  Type: {$scope['specification_type']}\n";
    echo "  Concept: {$scope['concept']}\n";

    if ($scope['specification_type'] === 'relationship_traversal') {
        echo "  Relationship Path:\n";
        $spec = $scope['relationship_spec'];
        $path = "{$spec['start_entity']}";
        foreach ($spec['path'] as $step) {
            $arrow = $step['direction'] === 'outgoing' ? '->' : '<-';
            $path .= " {$arrow}[:{$step['relationship']}]{$arrow} {$step['target_entity']}";
        }
        echo "    {$path}\n";

        if (!empty($spec['filter'])) {
            echo "  Filter: {$spec['filter']['entity']}.{$spec['filter']['property']} ";
            echo "{$spec['filter']['operator']} {$spec['filter']['value']}\n";
        }
    }

    if (!empty($scope['business_rules'])) {
        echo "  Business Rules:\n";
        foreach ($scope['business_rules'] as $rule) {
            echo "    - {$rule}\n";
        }
    }

    echo "\n";
}

/*
|--------------------------------------------------------------------------
| Example 3: Semantic Prompt Building
|--------------------------------------------------------------------------
|
| The semantic prompt builder creates LLM prompts from semantic metadata.
|
*/

echo "=== EXAMPLE 3: Semantic Prompt Building ===\n\n";

$promptBuilder = new SemanticPromptBuilder($patternLibrary);

// Get full context
$context = $retriever->retrieveContext($question);

// Build semantic prompt
$prompt = $promptBuilder->buildPrompt($question, $context, false);

echo "Generated Prompt:\n";
echo str_repeat('-', 80) . "\n";
echo $prompt;
echo "\n" . str_repeat('-', 80) . "\n\n";

/*
|--------------------------------------------------------------------------
| Example 4: Complete Query Generation
|--------------------------------------------------------------------------
|
| Generate Cypher queries from natural language using semantic metadata.
|
*/

echo "=== EXAMPLE 4: Complete Query Generation ===\n\n";

$llmProvider = new OpenAiLlmProvider([...]);

$queryGenerator = new QueryGenerator(
    $llmProvider,
    $graphStore,
    [],
    $promptBuilder
);

// Generate query
$result = $queryGenerator->generate($question, $context);

echo "Question: {$question}\n\n";
echo "Generated Cypher:\n";
echo "{$result['cypher']}\n\n";
echo "Explanation:\n";
echo "{$result['explanation']}\n\n";
echo "Confidence: {$result['confidence']}\n";
echo "Warnings: " . implode(', ', $result['warnings']) . "\n";
echo "\n";

/*
|--------------------------------------------------------------------------
| Example 5: Different Specification Types
|--------------------------------------------------------------------------
|
| Demonstrate all three specification types.
|
*/

echo "=== EXAMPLE 5: Specification Types ===\n\n";

// Type 1: Property Filter
$question1 = "Show active people";
$metadata1 = $retriever->getEntityMetadata($question1);

echo "Question: {$question1}\n";
echo "Type: {$metadata1['detected_scopes']['active']['specification_type']}\n";
echo "Filter: " . json_encode($metadata1['detected_scopes']['active']['filter'], JSON_PRETTY_PRINT) . "\n\n";

// Type 2: Relationship Traversal
$question2 = "Show me all volunteers";
$metadata2 = $retriever->getEntityMetadata($question2);

echo "Question: {$question2}\n";
echo "Type: {$metadata2['detected_scopes']['volunteers']['specification_type']}\n";
echo "Path: " . json_encode($metadata2['detected_scopes']['volunteers']['relationship_spec']['path'], JSON_PRETTY_PRINT) . "\n\n";

// Type 3: Pattern-Based
$question3 = "Show high value customers";
$metadata3 = $retriever->getEntityMetadata($question3);

echo "Question: {$question3}\n";
echo "Type: {$metadata3['detected_scopes']['high_value']['specification_type']}\n";
echo "Pattern: {$metadata3['detected_scopes']['high_value']['pattern']}\n";
echo "Parameters: " . json_encode($metadata3['detected_scopes']['high_value']['pattern_params'], JSON_PRETTY_PRINT) . "\n\n";

/*
|--------------------------------------------------------------------------
| Example 6: Complex Combinations
|--------------------------------------------------------------------------
|
| Combine multiple scopes for complex queries.
|
*/

echo "=== EXAMPLE 6: Complex Combinations ===\n\n";

$question = "Show active volunteers on marketing teams";

$context = $retriever->retrieveContext($question);
$result = $queryGenerator->generate($question, $context);

echo "Question: {$question}\n\n";

echo "Detected Scopes:\n";
foreach ($context['entity_metadata']['detected_scopes'] as $scopeName => $scope) {
    echo "- {$scopeName} ({$scope['entity']})\n";
}
echo "\n";

echo "Generated Query:\n";
echo "{$result['cypher']}\n\n";

/*
|--------------------------------------------------------------------------
| Example 7: Pattern Library Extension
|--------------------------------------------------------------------------
|
| How to add custom patterns to the library.
|
*/

echo "=== EXAMPLE 7: Adding Custom Patterns ===\n\n";

// Define custom pattern
$customPattern = [
    'description' => 'Find entities where property matches regex pattern',
    'parameters' => [
        'entity' => 'Entity label',
        'property' => 'Property name',
        'regex_pattern' => 'Regular expression',
    ],
    'semantic_template' => 'Find {entity} where {property} matches pattern {regex_pattern}',
];

// Add to pattern library configuration
echo "Custom Pattern Definition:\n";
echo json_encode($customPattern, JSON_PRETTY_PRINT);
echo "\n\n";

echo "Usage in entity config:\n";
echo <<<'PHP'
'gmail_users' => [
    'specification_type' => 'pattern',
    'concept' => 'Users with Gmail email addresses',
    'pattern' => 'regex_filter',
    'pattern_params' => [
        'entity' => 'Person',
        'property' => 'email',
        'regex_pattern' => '.*@gmail\.com$',
    ],
    'business_rules' => [
        'Email must match Gmail pattern',
    ],
],
PHP;
echo "\n\n";

/*
|--------------------------------------------------------------------------
| Example 8: Semantic Property Descriptions
|--------------------------------------------------------------------------
|
| Property semantic metadata helps LLM understand data structure.
|
*/

echo "=== EXAMPLE 8: Property Semantic Metadata ===\n\n";

$allMetadata = $retriever->getAllEntityMetadata();

if (isset($allMetadata['Person']['properties'])) {
    echo "Person Properties:\n";
    foreach ($allMetadata['Person']['properties'] as $propName => $propMeta) {
        echo "\n{$propName}:\n";
        echo "  Concept: {$propMeta['concept']}\n";
        echo "  Type: {$propMeta['type']}\n";

        if (isset($propMeta['possible_values'])) {
            echo "  Possible Values: " . implode(', ', $propMeta['possible_values']) . "\n";
        }

        if (isset($propMeta['business_meaning'])) {
            echo "  Business Meaning: {$propMeta['business_meaning']}\n";
        }
    }
}
echo "\n";

/*
|--------------------------------------------------------------------------
| Example 9: Relationship Semantic Descriptions
|--------------------------------------------------------------------------
|
| Relationship metadata provides context for graph traversal.
|
*/

echo "=== EXAMPLE 9: Relationship Semantic Metadata ===\n\n";

if (isset($allMetadata['Person']['relationships'])) {
    echo "Person Relationships:\n";
    foreach ($allMetadata['Person']['relationships'] as $relType => $relMeta) {
        echo "\n{$relType}:\n";
        echo "  Concept: {$relMeta['concept']}\n";
        echo "  Target: {$relMeta['target_entity']}\n";
        echo "  Direction: {$relMeta['direction']}\n";
        echo "  Cardinality: {$relMeta['cardinality']}\n";
        echo "  Business Meaning: {$relMeta['business_meaning']}\n";

        if (!empty($relMeta['common_use_cases'])) {
            echo "  Common Use Cases:\n";
            foreach ($relMeta['common_use_cases'] as $useCase) {
                echo "    - {$useCase}\n";
            }
        }
    }
}
echo "\n";

/*
|--------------------------------------------------------------------------
| Example 10: Configuration Validation
|--------------------------------------------------------------------------
|
| Validate semantic configurations before use.
|
*/

echo "=== EXAMPLE 10: Configuration Validation ===\n\n";

function validateSemanticScope(array $scope, string $scopeName): array
{
    $errors = [];
    $warnings = [];

    // Required fields
    if (empty($scope['specification_type'])) {
        $errors[] = "{$scopeName}: specification_type is required";
    }

    if (empty($scope['concept'])) {
        $warnings[] = "{$scopeName}: Missing concept description";
    }

    // Type-specific validation
    switch ($scope['specification_type'] ?? '') {
        case 'property_filter':
            if (empty($scope['filter'])) {
                $errors[] = "{$scopeName}: property_filter requires 'filter' field";
            } else {
                $required = ['property', 'operator', 'value'];
                foreach ($required as $field) {
                    if (!isset($scope['filter'][$field])) {
                        $errors[] = "{$scopeName}: filter missing '{$field}'";
                    }
                }
            }
            break;

        case 'relationship_traversal':
            if (empty($scope['relationship_spec'])) {
                $errors[] = "{$scopeName}: relationship_traversal requires 'relationship_spec'";
            } else {
                if (empty($scope['relationship_spec']['start_entity'])) {
                    $errors[] = "{$scopeName}: relationship_spec missing 'start_entity'";
                }
                if (empty($scope['relationship_spec']['path'])) {
                    $errors[] = "{$scopeName}: relationship_spec missing 'path'";
                }
            }
            break;

        case 'pattern':
            if (empty($scope['pattern'])) {
                $errors[] = "{$scopeName}: pattern type requires 'pattern' field";
            }
            if (empty($scope['pattern_params'])) {
                $errors[] = "{$scopeName}: pattern type requires 'pattern_params'";
            }
            break;
    }

    // Recommendations
    if (empty($scope['business_rules'])) {
        $warnings[] = "{$scopeName}: Consider adding business_rules for clarity";
    }

    if (empty($scope['examples'])) {
        $warnings[] = "{$scopeName}: Consider adding example questions";
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

// Example validation
$scopeToValidate = [
    'specification_type' => 'property_filter',
    'concept' => 'Active people',
    'filter' => [
        'property' => 'status',
        'operator' => 'equals',
        'value' => 'active',
    ],
    'business_rules' => [
        'Person is active if status equals "active"',
    ],
];

$validation = validateSemanticScope($scopeToValidate, 'active');

echo "Validation Result:\n";
echo "Valid: " . ($validation['valid'] ? 'Yes' : 'No') . "\n";

if (!empty($validation['errors'])) {
    echo "Errors:\n";
    foreach ($validation['errors'] as $error) {
        echo "  - {$error}\n";
    }
}

if (!empty($validation['warnings'])) {
    echo "Warnings:\n";
    foreach ($validation['warnings'] as $warning) {
        echo "  - {$warning}\n";
    }
}

echo "\n";

/*
|--------------------------------------------------------------------------
| Example 11: Migration Helper
|--------------------------------------------------------------------------
|
| Convert old concrete patterns to new semantic format.
|
*/

echo "=== EXAMPLE 11: Migration Helper ===\n\n";

function migrateToSemantic(array $oldScope): array
{
    // Detect pattern type from old config
    if (isset($oldScope['cypher_pattern']) && strpos($oldScope['cypher_pattern'], 'MATCH') !== false) {
        // Complex pattern - needs manual review
        return [
            'specification_type' => 'pattern',
            'concept' => $oldScope['description'] ?? 'Needs description',
            'pattern' => 'custom', // Needs pattern library addition
            'business_rules' => [],
            'examples' => $oldScope['examples'] ?? [],
            'migration_notes' => 'Manual review required - complex Cypher pattern detected',
        ];
    } elseif (!empty($oldScope['filter']) && count($oldScope['filter']) === 1) {
        // Simple property filter
        $property = array_key_first($oldScope['filter']);
        $value = $oldScope['filter'][$property];

        return [
            'specification_type' => 'property_filter',
            'concept' => $oldScope['description'] ?? "Filter by {$property}",
            'filter' => [
                'property' => $property,
                'operator' => 'equals',
                'value' => $value,
            ],
            'business_rules' => [
                "Entity matches when {$property} equals \"{$value}\"",
            ],
            'examples' => $oldScope['examples'] ?? [],
        ];
    } else {
        // Needs manual migration
        return [
            'specification_type' => 'unknown',
            'concept' => $oldScope['description'] ?? 'Needs description',
            'migration_notes' => 'Manual migration required',
            'original_config' => $oldScope,
        ];
    }
}

// Example migration
$oldConfig = [
    'description' => 'People who volunteer',
    'filter' => ['type' => 'volunteer'],
    'cypher_pattern' => "type = 'volunteer'",
    'examples' => ['Show volunteers'],
];

$newConfig = migrateToSemantic($oldConfig);

echo "Old Config:\n";
echo json_encode($oldConfig, JSON_PRETTY_PRINT);
echo "\n\nMigrated Config:\n";
echo json_encode($newConfig, JSON_PRETTY_PRINT);
echo "\n\n";

echo "=== END OF EXAMPLES ===\n";
