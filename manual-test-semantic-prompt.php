<?php

require 'vendor/autoload.php';

use AiSystem\Services\PatternLibrary;
use AiSystem\Services\SemanticPromptBuilder;

echo "=== Testing SemanticPromptBuilder ===\n\n";

// Setup
$patterns = [
    'relationship_traversal' => [
        'description' => 'Find entities through relationships',
        'parameters' => [
            'start_entity' => 'Starting entity',
            'path' => 'Relationship path',
            'filter_entity' => 'Entity to filter',
            'filter_property' => 'Property to filter',
            'filter_value' => 'Filter value',
        ],
        'semantic_template' => 'Find {start_entity} connected through {path} where {filter_entity}.{filter_property} equals {filter_value}',
    ],
];

$library = new PatternLibrary($patterns);
$builder = new SemanticPromptBuilder($library);

// Test 1: Basic prompt building
echo "Test 1: Basic prompt without scopes\n";
$context = [
    'graph_schema' => [
        'labels' => ['Person', 'Team'],
        'relationships' => ['MEMBER_OF', 'HAS_ROLE'],
    ],
    'entity_metadata' => [],
];

$prompt = $builder->buildPrompt("How many people are there?", $context, false);

assert(str_contains($prompt, 'Person'), "Should contain Person label");
assert(str_contains($prompt, 'Team'), "Should contain Team label");
assert(str_contains($prompt, 'MEMBER_OF'), "Should contain MEMBER_OF relationship");
assert(str_contains($prompt, 'How many people are there?'), "Should contain question");
echo "✓ Basic prompt built successfully\n";
echo "Prompt length: " . strlen($prompt) . " characters\n\n";

// Test 2: Prompt with semantic scope
echo "Test 2: Prompt with semantic scope (volunteers)\n";
$context = [
    'graph_schema' => [
        'labels' => ['Person', 'PersonTeam', 'Team'],
        'relationships' => ['HAS_ROLE', 'MEMBER_OF'],
    ],
    'entity_metadata' => [
        'detected_scopes' => [
            'volunteers' => [
                'entity' => 'Person',
                'scope' => 'volunteers',
                'specification_type' => 'relationship_traversal',
                'concept' => 'People who volunteer on teams',
                'relationship_spec' => [
                    'start_entity' => 'Person',
                    'path' => [
                        [
                            'relationship' => 'HAS_ROLE',
                            'target_entity' => 'PersonTeam',
                            'direction' => 'outgoing',
                        ],
                    ],
                    'filter' => [
                        'entity' => 'PersonTeam',
                        'property' => 'role_type',
                        'operator' => 'equals',
                        'value' => 'volunteer',
                    ],
                    'return_distinct' => true,
                ],
                'business_rules' => [
                    'A person is a volunteer if they have at least one volunteer role',
                    'Multiple volunteer roles = still one volunteer (use DISTINCT)',
                ],
                'examples' => [
                    'Show me all volunteers',
                    'How many volunteers do we have?',
                ],
            ],
        ],
    ],
];

$prompt = $builder->buildPrompt("How many volunteers do we have?", $context, false);

// Verify semantic scope is included
assert(str_contains($prompt, 'VOLUNTEERS'), "Should contain scope name");
assert(str_contains($prompt, 'People who volunteer on teams'), "Should contain concept");
assert(str_contains($prompt, 'RELATIONSHIP PATH'), "Should contain relationship path");
assert(str_contains($prompt, 'HAS_ROLE'), "Should contain HAS_ROLE relationship");
assert(str_contains($prompt, 'PersonTeam'), "Should contain PersonTeam");
assert(str_contains($prompt, 'role_type'), "Should contain role_type property");
assert(str_contains($prompt, 'volunteer'), "Should contain volunteer value");
assert(str_contains($prompt, 'BUSINESS RULES'), "Should contain business rules");
assert(str_contains($prompt, 'DISTINCT'), "Should mention DISTINCT");

echo "✓ Semantic scope prompt built successfully\n";
echo "Prompt length: " . strlen($prompt) . " characters\n\n";

// Output sample of the prompt
echo "--- Prompt Sample (first 500 chars) ---\n";
echo substr($prompt, 0, 500) . "...\n\n";

// Test 3: Property filter scope
echo "Test 3: Prompt with property filter scope\n";
$context = [
    'graph_schema' => [
        'labels' => ['Person'],
        'relationships' => [],
    ],
    'entity_metadata' => [
        'detected_scopes' => [
            'active' => [
                'entity' => 'Person',
                'scope' => 'active',
                'specification_type' => 'property_filter',
                'concept' => 'People with active status',
                'filter' => [
                    'property' => 'status',
                    'operator' => 'equals',
                    'value' => 'active',
                ],
                'business_rules' => [
                    'Person is active if status property equals "active"',
                ],
            ],
        ],
    ],
];

$prompt = $builder->buildPrompt("Show active people", $context, false);

assert(str_contains($prompt, 'ACTIVE'), "Should contain scope name");
assert(str_contains($prompt, 'People with active status'), "Should contain concept");
assert(str_contains($prompt, 'FILTER'), "Should contain filter section");
assert(str_contains($prompt, 'status'), "Should contain status property");
assert(str_contains($prompt, 'active'), "Should contain active value");

echo "✓ Property filter scope prompt built successfully\n";
echo "Prompt length: " . strlen($prompt) . " characters\n\n";

// Test 4: Write operation constraint
echo "Test 4: Read-only vs write operations\n";
$simpleContext = [
    'graph_schema' => ['labels' => ['Person']],
    'entity_metadata' => [],
];

$readOnlyPrompt = $builder->buildPrompt("Show people", $simpleContext, false);
$writePrompt = $builder->buildPrompt("Create a person", $simpleContext, true);

assert(str_contains($readOnlyPrompt, 'NO write operations'), "Read-only should restrict writes");
assert(!str_contains($writePrompt, 'NO write operations'), "Write mode should allow writes");

echo "✓ Write operation constraints working correctly\n\n";

// Test 5: Query generation rules
echo "Test 5: Query generation rules included\n";
$prompt = $builder->buildPrompt("Test question", $simpleContext, false);

assert(str_contains($prompt, 'SCHEMA COMPLIANCE'), "Should include schema compliance rules");
assert(str_contains($prompt, 'BUSINESS RULES'), "Should mention business rules section");
assert(str_contains($prompt, 'QUERY BEST PRACTICES'), "Should include best practices");
assert(str_contains($prompt, 'OUTPUT FORMAT'), "Should specify output format");
assert(str_contains($prompt, 'LIMIT'), "Should mention LIMIT clause");
assert(str_contains($prompt, 'DISTINCT'), "Should mention DISTINCT");

echo "✓ All query generation rules included\n\n";

// Test 6: Pattern library documentation
echo "Test 6: Pattern library included in prompt\n";
$prompt = $builder->buildPrompt("Test", $simpleContext, false);

assert(str_contains($prompt, 'AVAILABLE QUERY PATTERNS'), "Should include pattern library");
assert(str_contains($prompt, 'relationship_traversal'), "Should list available patterns");

echo "✓ Pattern library documented in prompt\n\n";

echo "=== ALL SEMANTIC PROMPT BUILDER TESTS PASSED ===\n";
