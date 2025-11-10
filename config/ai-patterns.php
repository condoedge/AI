<?php

/**
 * Query Pattern Library
 *
 * Defines reusable, generic query patterns that can be instantiated
 * with specific parameters from entity configurations.
 *
 * Patterns are domain-agnostic and work for any business domain.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Property Filter Pattern
    |--------------------------------------------------------------------------
    |
    | Find entities where a property matches a specific value.
    |
    | Use Case: Simple attribute filtering (status, type, role, etc.)
    |
    | Example:
    | - Find active people
    | - List pending orders
    | - Show completed tasks
    |
    */
    'property_filter' => [
        'description' => 'Filter entities by property value',

        'parameters' => [
            'entity' => 'Entity label (e.g., Person, Order, Product)',
            'property' => 'Property name to filter on',
            'operator' => 'Comparison operator: equals, not_equals, greater_than, less_than, contains, starts_with, ends_with',
            'value' => 'Value to compare against',
        ],

        'semantic_template' => 'Find {entity} where {property} {operator} {value}',

        'examples' => [
            [
                'description' => 'Find active people',
                'params' => [
                    'entity' => 'Person',
                    'property' => 'status',
                    'operator' => 'equals',
                    'value' => 'active',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Property Range Pattern
    |--------------------------------------------------------------------------
    |
    | Find entities where a numeric property falls within a range.
    |
    | Use Case: Numeric range filtering (age, price, quantity, etc.)
    |
    */
    'property_range' => [
        'description' => 'Filter entities by property value range',

        'parameters' => [
            'entity' => 'Entity label',
            'property' => 'Numeric property name',
            'min_value' => 'Minimum value (inclusive)',
            'max_value' => 'Maximum value (inclusive)',
        ],

        'semantic_template' => 'Find {entity} where {property} is between {min_value} and {max_value}',

        'examples' => [
            [
                'description' => 'Find orders between $100 and $500',
                'params' => [
                    'entity' => 'Order',
                    'property' => 'total',
                    'min_value' => 100,
                    'max_value' => 500,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Relationship Traversal Pattern
    |--------------------------------------------------------------------------
    |
    | Find entities connected through one or more relationships.
    |
    | Use Case: Graph traversal with filtering on intermediate nodes
    |
    | Example:
    | - Find people who volunteer (Person -> PersonTeam -> Team)
    | - Show customers who ordered specific product
    | - List managers of active teams
    |
    */
    'relationship_traversal' => [
        'description' => 'Find entities connected through relationship path',

        'parameters' => [
            'start_entity' => 'Starting entity label',
            'path' => 'Array of relationship steps (relationship, target_entity, direction)',
            'filter_entity' => 'Entity to apply filter on (optional)',
            'filter_property' => 'Property to filter (optional)',
            'filter_operator' => 'Filter operator (optional)',
            'filter_value' => 'Filter value (optional)',
            'return_distinct' => 'Whether to return distinct results (recommended: true)',
        ],

        'semantic_template' => 'Find {start_entity} connected through relationships where {filter_entity}.{filter_property} {filter_operator} {filter_value}',

        'examples' => [
            [
                'description' => 'Find people who volunteer',
                'params' => [
                    'start_entity' => 'Person',
                    'path' => [
                        ['relationship' => 'HAS_ROLE', 'target_entity' => 'PersonTeam', 'direction' => 'outgoing'],
                        ['relationship' => 'ON_TEAM', 'target_entity' => 'Team', 'direction' => 'outgoing'],
                    ],
                    'filter_entity' => 'PersonTeam',
                    'filter_property' => 'role_type',
                    'filter_operator' => 'equals',
                    'filter_value' => 'volunteer',
                    'return_distinct' => true,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity With Relationship Pattern
    |--------------------------------------------------------------------------
    |
    | Find entities that have at least one relationship of a specific type.
    |
    | Use Case: Existence checks
    |
    | Example:
    | - Find customers who have placed orders
    | - Show people on teams
    | - List products with reviews
    |
    */
    'entity_with_relationship' => [
        'description' => 'Find entities that have at least one relationship of a type',

        'parameters' => [
            'entity' => 'Entity label',
            'relationship' => 'Relationship type',
            'target_entity' => 'Target entity label (optional)',
            'direction' => 'Relationship direction: outgoing, incoming, any (default: any)',
        ],

        'semantic_template' => 'Find {entity} that have {relationship} relationship to {target_entity}',

        'examples' => [
            [
                'description' => 'Find customers with orders',
                'params' => [
                    'entity' => 'Customer',
                    'relationship' => 'PLACED',
                    'target_entity' => 'Order',
                    'direction' => 'outgoing',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Without Relationship Pattern
    |--------------------------------------------------------------------------
    |
    | Find entities that DON'T have a relationship of a specific type.
    |
    | Use Case: Absence checks, orphan detection
    |
    | Example:
    | - Find people without teams
    | - Show products without orders
    | - List customers who never purchased
    |
    */
    'entity_without_relationship' => [
        'description' => 'Find entities that lack a specific relationship',

        'parameters' => [
            'entity' => 'Entity label',
            'relationship' => 'Relationship type',
            'target_entity' => 'Target entity label (optional)',
            'direction' => 'Relationship direction: outgoing, incoming, any (default: any)',
        ],

        'semantic_template' => 'Find {entity} that do not have {relationship} relationship to {target_entity}',

        'examples' => [
            [
                'description' => 'Find people without teams',
                'params' => [
                    'entity' => 'Person',
                    'relationship' => 'MEMBER_OF',
                    'target_entity' => 'Team',
                    'direction' => 'outgoing',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity With Aggregated Relationship Pattern
    |--------------------------------------------------------------------------
    |
    | Find entities where an aggregated value from related entities meets a condition.
    |
    | Use Case: Aggregation-based filtering
    |
    | Example:
    | - Find high-value customers (sum of orders > threshold)
    | - Show popular products (count of orders > threshold)
    | - List active managers (count of team members > threshold)
    |
    */
    'entity_with_aggregated_relationship' => [
        'description' => 'Find entities where aggregation of related entities meets condition',

        'parameters' => [
            'base_entity' => 'Entity to return',
            'relationship' => 'Relationship to traverse',
            'related_entity' => 'Related entity to aggregate',
            'aggregate_property' => 'Property to aggregate (optional for count)',
            'aggregate_function' => 'Aggregation: sum, count, avg, max, min',
            'condition_operator' => 'Comparison: greater_than, less_than, equals, between',
            'condition_value' => 'Threshold value or array [min, max] for between',
            'direction' => 'Relationship direction: outgoing, incoming, any (default: outgoing)',
        ],

        'semantic_template' => 'Find {base_entity} where {aggregate_function} of {related_entity}.{aggregate_property} {condition_operator} {condition_value}',

        'examples' => [
            [
                'description' => 'Find high-value customers',
                'params' => [
                    'base_entity' => 'Customer',
                    'relationship' => 'PLACED',
                    'related_entity' => 'Order',
                    'aggregate_property' => 'total',
                    'aggregate_function' => 'sum',
                    'condition_operator' => 'greater_than',
                    'condition_value' => 10000,
                    'direction' => 'outgoing',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporal Filter Pattern
    |--------------------------------------------------------------------------
    |
    | Find entities based on date/time conditions.
    |
    | Use Case: Time-based filtering
    |
    | Example:
    | - Find recent customers (joined within last 30 days)
    | - Show upcoming events (starts after today)
    | - List expired memberships (ended before today)
    |
    */
    'temporal_filter' => [
        'description' => 'Filter entities by date/time conditions',

        'parameters' => [
            'entity' => 'Entity label',
            'date_property' => 'Date/datetime property name',
            'temporal_operator' => 'Operator: before, after, within_last, within_next, between',
            'temporal_value' => 'Date value or duration (e.g., "30 days", "2024-01-01")',
            'temporal_value_end' => 'End date for "between" operator (optional)',
        ],

        'semantic_template' => 'Find {entity} where {date_property} is {temporal_operator} {temporal_value}',

        'examples' => [
            [
                'description' => 'Find recent customers',
                'params' => [
                    'entity' => 'Customer',
                    'date_property' => 'created_at',
                    'temporal_operator' => 'within_last',
                    'temporal_value' => '30 days',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Hop Traversal Pattern
    |--------------------------------------------------------------------------
    |
    | Find entities through complex multi-step relationship paths.
    |
    | Use Case: Deep graph traversal
    |
    | Example:
    | - Find people who manage teams in specific department
    | - Show products ordered by customers in region
    | - List contributors to projects in category
    |
    */
    'multi_hop_traversal' => [
        'description' => 'Find entities through multi-step relationship path',

        'parameters' => [
            'start_entity' => 'Starting entity',
            'hops' => 'Array of relationship steps with filters',
            'end_entity' => 'Final entity to return (default: start_entity)',
            'return_distinct' => 'Return distinct results (recommended: true)',
        ],

        'semantic_template' => 'Find {start_entity} connected to {end_entity} through multi-hop path',

        'examples' => [
            [
                'description' => 'Find people managing marketing teams',
                'params' => [
                    'start_entity' => 'Person',
                    'hops' => [
                        [
                            'relationship' => 'MANAGES',
                            'target_entity' => 'Team',
                            'direction' => 'outgoing',
                            'filter' => [
                                'property' => 'department',
                                'operator' => 'equals',
                                'value' => 'Marketing',
                            ],
                        ],
                    ],
                    'end_entity' => 'Person',
                    'return_distinct' => true,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multiple Property Filter Pattern
    |--------------------------------------------------------------------------
    |
    | Find entities matching multiple property conditions.
    |
    | Use Case: Complex filtering with AND/OR logic
    |
    | Example:
    | - Find active volunteers (status = active AND type = volunteer)
    | - Show high-priority urgent tasks
    | - List premium customers in specific region
    |
    */
    'multiple_property_filter' => [
        'description' => 'Filter entities by multiple property conditions',

        'parameters' => [
            'entity' => 'Entity label',
            'filters' => 'Array of filter conditions',
            'logical_operator' => 'AND or OR (default: AND)',
        ],

        'semantic_template' => 'Find {entity} where multiple conditions are met',

        'examples' => [
            [
                'description' => 'Find active volunteers',
                'params' => [
                    'entity' => 'Person',
                    'filters' => [
                        ['property' => 'status', 'operator' => 'equals', 'value' => 'active'],
                        ['property' => 'type', 'operator' => 'equals', 'value' => 'volunteer'],
                    ],
                    'logical_operator' => 'AND',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Relationship + Property Filter Pattern
    |--------------------------------------------------------------------------
    |
    | Combine relationship traversal with property filtering.
    |
    | Use Case: Graph traversal with multiple filters
    |
    | Example:
    | - Find active people who volunteer
    | - Show completed orders from VIP customers
    | - List approved products in category
    |
    */
    'relationship_with_property_filter' => [
        'description' => 'Combine relationship traversal with property filters',

        'parameters' => [
            'start_entity' => 'Starting entity',
            'path' => 'Relationship path',
            'entity_filters' => 'Filters on entities in path',
            'return_distinct' => 'Return distinct (recommended: true)',
        ],

        'semantic_template' => 'Find {start_entity} through relationships with property filters',

        'examples' => [
            [
                'description' => 'Find active volunteers',
                'params' => [
                    'start_entity' => 'Person',
                    'path' => [
                        ['relationship' => 'HAS_ROLE', 'target_entity' => 'PersonTeam', 'direction' => 'outgoing'],
                    ],
                    'entity_filters' => [
                        ['entity' => 'Person', 'property' => 'status', 'operator' => 'equals', 'value' => 'active'],
                        ['entity' => 'PersonTeam', 'property' => 'role_type', 'operator' => 'equals', 'value' => 'volunteer'],
                    ],
                    'return_distinct' => true,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pattern Composition
    |--------------------------------------------------------------------------
    |
    | Combine multiple patterns for complex queries.
    |
    | Use Case: Advanced queries requiring multiple pattern types
    |
    */
    'composed' => [
        'description' => 'Compose multiple patterns into complex query',

        'parameters' => [
            'base_pattern' => 'Primary pattern name',
            'base_params' => 'Parameters for base pattern',
            'additional_patterns' => 'Array of additional patterns to combine',
            'combination_logic' => 'How to combine: AND, OR, UNION',
        ],

        'semantic_template' => 'Complex query combining multiple patterns',

        'examples' => [
            [
                'description' => 'Active volunteers on Marketing team',
                'params' => [
                    'base_pattern' => 'relationship_traversal',
                    'base_params' => [
                        'start_entity' => 'Person',
                        'path' => [
                            ['relationship' => 'HAS_ROLE', 'target_entity' => 'PersonTeam', 'direction' => 'outgoing'],
                            ['relationship' => 'ON_TEAM', 'target_entity' => 'Team', 'direction' => 'outgoing'],
                        ],
                        'filter_entity' => 'PersonTeam',
                        'filter_property' => 'role_type',
                        'filter_value' => 'volunteer',
                    ],
                    'additional_patterns' => [
                        [
                            'pattern' => 'property_filter',
                            'params' => [
                                'entity' => 'Person',
                                'property' => 'status',
                                'operator' => 'equals',
                                'value' => 'active',
                            ],
                        ],
                        [
                            'pattern' => 'property_filter',
                            'params' => [
                                'entity' => 'Team',
                                'property' => 'name',
                                'operator' => 'equals',
                                'value' => 'Marketing',
                            ],
                        ],
                    ],
                    'combination_logic' => 'AND',
                ],
            ],
        ],
    ],

];
