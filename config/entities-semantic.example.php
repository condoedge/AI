<?php

/**
 * Semantic Entity Configurations - Example
 *
 * This file demonstrates the new semantic metadata approach where:
 * - Configuration describes WHAT (business concepts)
 * - System figures out HOW (query generation)
 * - NO hardcoded Cypher patterns
 * - Reusable pattern library
 * - Self-documenting structure
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Person Entity - Complete Semantic Example
    |--------------------------------------------------------------------------
    |
    | Demonstrates all specification types:
    | 1. property_filter - Simple attribute filtering
    | 2. relationship_traversal - Graph relationship navigation
    | 3. pattern - Pattern library usage
    |
    */
    'Person' => [
        'graph' => [
            'label' => 'Person',
            'properties' => ['id', 'first_name', 'last_name', 'email', 'birth_date', 'status'],
            'relationships' => [
                [
                    'type' => 'HAS_ROLE',
                    'target_label' => 'PersonTeam',
                    'description' => 'Person has a role on a team',
                ],
                [
                    'type' => 'MEMBER_OF',
                    'target_label' => 'Team',
                    'description' => 'Direct team membership',
                ],
                [
                    'type' => 'MANAGES',
                    'target_label' => 'Team',
                    'description' => 'Person manages a team',
                ],
            ],
        ],

        'vector' => [
            'collection' => 'people',
            'embed_fields' => ['first_name', 'last_name', 'bio', 'notes'],
            'metadata' => ['id', 'email', 'status'],
        ],

        'metadata' => [
            // High-level entity concept
            'concept' => 'Individuals in the system including volunteers, staff, and customers',

            // Alternative names for entity detection
            'aliases' => [
                'person',
                'people',
                'user',
                'users',
                'individual',
                'individuals',
                'member',
                'members',
            ],

            // Semantic property descriptions
            'properties' => [
                'id' => [
                    'concept' => 'Unique identifier for the person',
                    'type' => 'identifier',
                ],
                'first_name' => [
                    'concept' => 'Person\'s given name',
                    'type' => 'text',
                ],
                'last_name' => [
                    'concept' => 'Person\'s family name',
                    'type' => 'text',
                ],
                'email' => [
                    'concept' => 'Email address for contact',
                    'type' => 'text',
                    'unique' => true,
                ],
                'status' => [
                    'concept' => 'Current state of the person in the system',
                    'type' => 'categorical',
                    'possible_values' => ['active', 'inactive', 'pending', 'suspended'],
                    'default_value' => 'pending',
                    'business_meaning' => 'Active means person can access and use the system',
                ],
                'birth_date' => [
                    'concept' => 'Date of birth',
                    'type' => 'date',
                ],
            ],

            // Semantic relationship descriptions
            'relationships' => [
                'HAS_ROLE' => [
                    'concept' => 'Person has a specific role on a team',
                    'target_entity' => 'PersonTeam',
                    'direction' => 'outgoing',
                    'cardinality' => 'one_to_many',
                    'business_meaning' => 'Links person to their team roles through junction entity PersonTeam',
                    'common_use_cases' => [
                        'Finding volunteers: filter PersonTeam.role_type = "volunteer"',
                        'Finding leaders: filter PersonTeam.role_type = "leader"',
                        'Finding coordinators: filter PersonTeam.role_type = "coordinator"',
                    ],
                ],
                'MEMBER_OF' => [
                    'concept' => 'Person is a member of a team',
                    'target_entity' => 'Team',
                    'direction' => 'outgoing',
                    'cardinality' => 'one_to_many',
                    'business_meaning' => 'Direct team membership without specific role',
                ],
                'MANAGES' => [
                    'concept' => 'Person manages a team',
                    'target_entity' => 'Team',
                    'direction' => 'outgoing',
                    'cardinality' => 'one_to_many',
                    'business_meaning' => 'Person has management responsibility for team',
                ],
            ],

            // Declarative scopes (NO CYPHER!)
            'scopes' => [

                /*
                |------------------------------------------------------------------
                | Simple Property Filter Examples
                |------------------------------------------------------------------
                */

                'active' => [
                    'specification_type' => 'property_filter',

                    'concept' => 'People who are currently active in the system',

                    'filter' => [
                        'property' => 'status',
                        'operator' => 'equals',
                        'value' => 'active',
                    ],

                    'business_rules' => [
                        'A person is active if their status property equals "active"',
                        'Active people can access the system and appear in searches',
                    ],

                    'examples' => [
                        'Show active people',
                        'List active members',
                        'How many active users?',
                        'Who is currently active?',
                    ],
                ],

                'inactive' => [
                    'specification_type' => 'property_filter',

                    'concept' => 'People who are not currently active',

                    'filter' => [
                        'property' => 'status',
                        'operator' => 'equals',
                        'value' => 'inactive',
                    ],

                    'business_rules' => [
                        'Inactive people cannot access the system',
                        'Status becomes inactive when account is deactivated',
                    ],

                    'examples' => [
                        'Show inactive people',
                        'List inactive users',
                    ],
                ],

                /*
                |------------------------------------------------------------------
                | Relationship Traversal Examples
                |------------------------------------------------------------------
                */

                'volunteers' => [
                    'specification_type' => 'relationship_traversal',

                    'concept' => 'People who volunteer their time on teams',

                    'relationship_spec' => [
                        'start_entity' => 'Person',
                        'path' => [
                            [
                                'relationship' => 'HAS_ROLE',
                                'target_entity' => 'PersonTeam',
                                'direction' => 'outgoing',
                            ],
                            [
                                'relationship' => 'ON_TEAM',
                                'target_entity' => 'Team',
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
                        'A person is a volunteer if they have at least one volunteer role on any team',
                        'The volunteer role is indicated by PersonTeam.role_type = "volunteer"',
                        'Multiple volunteer roles on different teams = still one volunteer (use DISTINCT)',
                        'PersonTeam is the junction entity between Person and Team',
                    ],

                    'examples' => [
                        'Show me all volunteers',
                        'How many volunteers do we have?',
                        'List volunteers on teams',
                        'Who are our volunteers?',
                        'Find all volunteer members',
                    ],
                ],

                'team_leaders' => [
                    'specification_type' => 'relationship_traversal',

                    'concept' => 'People who lead teams',

                    'relationship_spec' => [
                        'start_entity' => 'Person',
                        'path' => [
                            [
                                'relationship' => 'HAS_ROLE',
                                'target_entity' => 'PersonTeam',
                                'direction' => 'outgoing',
                            ],
                            [
                                'relationship' => 'ON_TEAM',
                                'target_entity' => 'Team',
                                'direction' => 'outgoing',
                            ],
                        ],
                        'filter' => [
                            'entity' => 'PersonTeam',
                            'property' => 'role_type',
                            'operator' => 'equals',
                            'value' => 'leader',
                        ],
                        'return_distinct' => true,
                    ],

                    'business_rules' => [
                        'A team leader has role_type = "leader" in PersonTeam',
                        'Person can lead multiple teams',
                    ],

                    'examples' => [
                        'Show me team leaders',
                        'List people who lead teams',
                        'Who are the team leaders?',
                    ],
                ],

                'people_managing_marketing_teams' => [
                    'specification_type' => 'relationship_traversal',

                    'concept' => 'People who manage teams in the Marketing department',

                    'relationship_spec' => [
                        'start_entity' => 'Person',
                        'path' => [
                            [
                                'relationship' => 'MANAGES',
                                'target_entity' => 'Team',
                                'direction' => 'outgoing',
                            ],
                        ],
                        'filter' => [
                            'entity' => 'Team',
                            'property' => 'department',
                            'operator' => 'equals',
                            'value' => 'Marketing',
                        ],
                        'return_distinct' => true,
                    ],

                    'business_rules' => [
                        'Manager has MANAGES relationship to Team',
                        'Team must be in Marketing department',
                    ],

                    'examples' => [
                        'Show people managing marketing teams',
                        'List marketing team managers',
                        'Who manages marketing teams?',
                    ],
                ],

                /*
                |------------------------------------------------------------------
                | Pattern Library Usage Examples
                |------------------------------------------------------------------
                */

                'people_without_teams' => [
                    'specification_type' => 'pattern',

                    'concept' => 'People who are not on any team',

                    'pattern' => 'entity_without_relationship',

                    'pattern_params' => [
                        'entity' => 'Person',
                        'relationship' => 'MEMBER_OF',
                        'target_entity' => 'Team',
                        'direction' => 'outgoing',
                    ],

                    'business_rules' => [
                        'Person without teams has no MEMBER_OF relationship to any Team',
                        'Useful for finding unassigned people',
                    ],

                    'examples' => [
                        'Show people without teams',
                        'List unassigned people',
                        'Who is not on a team?',
                    ],
                ],

                'people_with_teams' => [
                    'specification_type' => 'pattern',

                    'concept' => 'People who are on at least one team',

                    'pattern' => 'entity_with_relationship',

                    'pattern_params' => [
                        'entity' => 'Person',
                        'relationship' => 'MEMBER_OF',
                        'target_entity' => 'Team',
                        'direction' => 'outgoing',
                    ],

                    'business_rules' => [
                        'Person with teams has at least one MEMBER_OF relationship',
                    ],

                    'examples' => [
                        'Show people with teams',
                        'List people on teams',
                    ],
                ],

                /*
                |------------------------------------------------------------------
                | Combined Filters (Relationship + Property)
                |------------------------------------------------------------------
                */

                'active_volunteers' => [
                    'specification_type' => 'relationship_traversal',

                    'concept' => 'Active people who volunteer',

                    'relationship_spec' => [
                        'start_entity' => 'Person',
                        'path' => [
                            [
                                'relationship' => 'HAS_ROLE',
                                'target_entity' => 'PersonTeam',
                                'direction' => 'outgoing',
                            ],
                        ],
                        'filters' => [
                            [
                                'entity' => 'Person',
                                'property' => 'status',
                                'operator' => 'equals',
                                'value' => 'active',
                            ],
                            [
                                'entity' => 'PersonTeam',
                                'property' => 'role_type',
                                'operator' => 'equals',
                                'value' => 'volunteer',
                            ],
                        ],
                        'return_distinct' => true,
                    ],

                    'business_rules' => [
                        'Person must be active (status = "active")',
                        'Person must have volunteer role (PersonTeam.role_type = "volunteer")',
                        'Both conditions must be true',
                    ],

                    'examples' => [
                        'Show active volunteers',
                        'List volunteers who are active',
                        'Find all active volunteer members',
                    ],
                ],

            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Entity - Aggregation Examples
    |--------------------------------------------------------------------------
    */
    'Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => ['id', 'name', 'email', 'created_at'],
            'relationships' => [
                [
                    'type' => 'PLACED',
                    'target_label' => 'Order',
                    'description' => 'Customer placed an order',
                ],
            ],
        ],

        'vector' => [
            'collection' => 'customers',
            'embed_fields' => ['name', 'email', 'notes'],
            'metadata' => ['id', 'email', 'created_at'],
        ],

        'metadata' => [
            'concept' => 'Individuals or organizations who purchase products',

            'aliases' => ['customer', 'customers', 'client', 'clients', 'buyer', 'purchaser'],

            'properties' => [
                'created_at' => [
                    'concept' => 'When customer account was created',
                    'type' => 'datetime',
                    'business_meaning' => 'Customer registration/signup date',
                ],
            ],

            'relationships' => [
                'PLACED' => [
                    'concept' => 'Customer placed an order',
                    'target_entity' => 'Order',
                    'direction' => 'outgoing',
                    'cardinality' => 'one_to_many',
                    'business_meaning' => 'Links customer to their purchase orders',
                ],
            ],

            'scopes' => [

                'with_orders' => [
                    'specification_type' => 'pattern',

                    'concept' => 'Customers who have placed at least one order',

                    'pattern' => 'entity_with_relationship',

                    'pattern_params' => [
                        'entity' => 'Customer',
                        'relationship' => 'PLACED',
                        'target_entity' => 'Order',
                        'direction' => 'outgoing',
                    ],

                    'business_rules' => [
                        'Customer with orders has at least one PLACED relationship to Order',
                        'Order status doesn\'t matter - any order counts',
                    ],

                    'examples' => [
                        'Show customers with orders',
                        'List customers who have ordered',
                        'Find customers who purchased',
                    ],
                ],

                'high_value' => [
                    'specification_type' => 'pattern',

                    'concept' => 'Customers with high total order value',

                    'pattern' => 'entity_with_aggregated_relationship',

                    'pattern_params' => [
                        'base_entity' => 'Customer',
                        'relationship' => 'PLACED',
                        'related_entity' => 'Order',
                        'aggregate_property' => 'total',
                        'aggregate_function' => 'sum',
                        'condition_operator' => 'greater_than',
                        'condition_value' => 10000,
                        'direction' => 'outgoing',
                    ],

                    'business_rules' => [
                        'High-value customer has sum(Order.total) > $10,000',
                        'All orders are included regardless of status',
                        'Calculation is cumulative across all time',
                    ],

                    'examples' => [
                        'Show high value customers',
                        'List customers with over $10k in orders',
                        'Find VIP customers',
                        'Who are our biggest spenders?',
                    ],
                ],

                'recent' => [
                    'specification_type' => 'pattern',

                    'concept' => 'Customers who joined recently',

                    'pattern' => 'temporal_filter',

                    'pattern_params' => [
                        'entity' => 'Customer',
                        'date_property' => 'created_at',
                        'temporal_operator' => 'within_last',
                        'temporal_value' => '30 days',
                    ],

                    'business_rules' => [
                        'Recent customer joined within last 30 days',
                        'Based on created_at timestamp',
                    ],

                    'examples' => [
                        'Show recent customers',
                        'List new customers',
                        'Who joined recently?',
                    ],
                ],

            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Entity - Status-Based Scopes
    |--------------------------------------------------------------------------
    */
    'Order' => [
        'graph' => [
            'label' => 'Order',
            'properties' => ['id', 'total', 'status', 'created_at', 'updated_at'],
            'relationships' => [
                [
                    'type' => 'PLACED_BY',
                    'target_label' => 'Customer',
                ],
                [
                    'type' => 'CONTAINS',
                    'target_label' => 'Product',
                ],
            ],
        ],

        'vector' => [
            'collection' => 'orders',
            'embed_fields' => ['notes', 'description'],
            'metadata' => ['id', 'status', 'total'],
        ],

        'metadata' => [
            'concept' => 'Customer orders and purchases',

            'aliases' => ['order', 'orders', 'purchase', 'purchases', 'sale'],

            'properties' => [
                'status' => [
                    'concept' => 'Current state of the order',
                    'type' => 'categorical',
                    'possible_values' => ['pending', 'processing', 'completed', 'cancelled', 'refunded'],
                    'business_meaning' => 'Tracks order lifecycle from placement to fulfillment',
                ],
                'total' => [
                    'concept' => 'Total order amount',
                    'type' => 'numeric',
                    'unit' => 'currency',
                    'business_meaning' => 'Sum of all line items including tax and fees',
                ],
            ],

            'scopes' => [

                'pending' => [
                    'specification_type' => 'property_filter',
                    'concept' => 'Orders awaiting processing',
                    'filter' => [
                        'property' => 'status',
                        'operator' => 'equals',
                        'value' => 'pending',
                    ],
                    'business_rules' => [
                        'Pending orders have not been processed yet',
                        'Require action from staff',
                    ],
                    'examples' => [
                        'Show pending orders',
                        'List orders awaiting processing',
                    ],
                ],

                'completed' => [
                    'specification_type' => 'property_filter',
                    'concept' => 'Orders that have been completed',
                    'filter' => [
                        'property' => 'status',
                        'operator' => 'equals',
                        'value' => 'completed',
                    ],
                    'examples' => [
                        'Show completed orders',
                        'List fulfilled orders',
                    ],
                ],

            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Entity - Simple Example
    |--------------------------------------------------------------------------
    */
    'Team' => [
        'graph' => [
            'label' => 'Team',
            'properties' => ['id', 'name', 'department', 'created_at'],
            'relationships' => [],
        ],

        'metadata' => [
            'concept' => 'Groups of people working together',

            'aliases' => ['team', 'teams', 'group', 'groups'],

            'properties' => [
                'department' => [
                    'concept' => 'Department or division the team belongs to',
                    'type' => 'categorical',
                    'possible_values' => ['Marketing', 'Sales', 'Engineering', 'Support', 'HR'],
                ],
            ],

            'scopes' => [

                'marketing' => [
                    'specification_type' => 'property_filter',
                    'concept' => 'Teams in the Marketing department',
                    'filter' => [
                        'property' => 'department',
                        'operator' => 'equals',
                        'value' => 'Marketing',
                    ],
                    'examples' => [
                        'Show marketing teams',
                        'List teams in marketing',
                    ],
                ],

            ],
        ],
    ],

];
