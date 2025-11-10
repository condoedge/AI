<?php

/**
 * Entity Configurations
 *
 * Define how your domain entities map to Neo4j (graph) and Qdrant (vector) storage.
 *
 * Each entity can specify:
 * - 'graph': Neo4j configuration (label, properties, relationships)
 * - 'vector': Qdrant configuration (collection, fields to embed, metadata)
 *
 * Models using HasNodeableConfig trait will automatically load these configurations.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Example: Customer Entity
    |--------------------------------------------------------------------------
    */
    'Customer' => [
        'graph' => [
            'label' => 'Customer',
            'properties' => ['id', 'name', 'email', 'created_at'],
            'relationships' => [
                [
                    'type' => 'BELONGS_TO',
                    'target_label' => 'Team',
                    'foreign_key' => 'team_id',
                ],
            ],
        ],
        'vector' => [
            'collection' => 'customers',
            'embed_fields' => ['name', 'email', 'description'],
            'metadata' => ['id', 'email', 'team_id', 'created_at'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Example: Person Entity (with Semantic Metadata)
    |--------------------------------------------------------------------------
    */
    'Person' => [
        'graph' => [
            'label' => 'Person',
            'properties' => ['id', 'first_name', 'last_name', 'email', 'birth_date', 'type', 'role', 'status'],
            'relationships' => [
                [
                    'type' => 'HAS_ROLE',
                    'target_label' => 'PersonTeam',
                    'foreign_key' => 'person_id',
                ],
                [
                    'type' => 'MEMBER_OF',
                    'target_label' => 'Team',
                    'foreign_key' => 'team_id',
                    'properties' => ['since' => 'created_at'],
                ],
                [
                    'type' => 'MANAGES',
                    'target_label' => 'Team',
                    'foreign_key' => 'managed_team_id',
                ],
            ],
        ],
        'vector' => [
            'collection' => 'people',
            'embed_fields' => ['first_name', 'last_name', 'bio', 'notes'],
            'metadata' => ['id', 'email', 'team_id', 'type', 'role'],
        ],

        /*
        |----------------------------------------------------------------------
        | Semantic Metadata for Entity Understanding
        |----------------------------------------------------------------------
        |
        | This metadata helps the AI system understand domain-specific
        | terminology and map business terms to entity filters.
        |
        */
        'metadata' => [
            // Alternative names for this entity
            'aliases' => ['person', 'people', 'user', 'users', 'individual', 'individuals', 'member', 'members'],

            // Description of the entity for AI context
            'description' => 'Represents individuals in the system including volunteers, customers, and staff members',

            // Scoped subsets with business terminology
            'scopes' => [
                // Volunteers - Relationship-based scope using semantic format
                'volunteers' => [
                    // Specification type: relationship_traversal (graph traversal pattern)
                    'specification_type' => 'relationship_traversal',

                    // Business concept in plain language
                    'concept' => 'People who volunteer on teams',

                    // Relationship specification - describes the graph path
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

                    // Business rules in plain language
                    'business_rules' => [
                        'A person is a volunteer if they have at least one volunteer role on any team',
                        'The volunteer role is stored in PersonTeam.role_type',
                        'Multiple volunteer roles on different teams = still one volunteer (use DISTINCT)',
                    ],

                    // Example questions for training the LLM
                    'examples' => [
                        'Show me all volunteers',
                        'How many volunteers do we have?',
                        'List volunteers on teams',
                        'Who are our volunteers?',
                        'Find people who volunteer',
                    ],
                ],
                'customers' => [
                    'description' => 'People who are customers',
                    'filter' => ['type' => 'customer'],
                    'cypher_pattern' => "type = 'customer'",
                    'examples' => [
                        'Show me all customers',
                        'How many customers do we have?',
                        'List customers',
                        'Which customers placed orders?',
                        'Find all customer records',
                    ],
                ],
                'staff' => [
                    'description' => 'People who are staff members or employees',
                    'filter' => ['role' => 'staff'],
                    'cypher_pattern' => "role = 'staff'",
                    'examples' => [
                        'List all staff members',
                        'Show me employees',
                        'How many staff do we have?',
                        'Who are the staff members?',
                    ],
                ],
                'active' => [
                    'description' => 'People with active status',
                    'filter' => ['status' => 'active'],
                    'cypher_pattern' => "status = 'active'",
                    'examples' => [
                        'Show active people',
                        'List active members',
                        'Who is currently active?',
                    ],
                ],
            ],

            // Property descriptions for better AI understanding
            'common_properties' => [
                'id' => 'Unique identifier for the person',
                'first_name' => 'Person\'s first name',
                'last_name' => 'Person\'s last name',
                'email' => 'Email address',
                'birth_date' => 'Date of birth',
                'type' => 'Person type: volunteer, customer, staff, etc.',
                'role' => 'Person role in the organization',
                'status' => 'Current status: active, inactive, pending, etc.',
                'team_id' => 'ID of the team this person belongs to',
            ],

            // Common combinations of scopes
            'combinations' => [
                'active_volunteers' => [
                    'description' => 'Active volunteers',
                    'filters' => ['type' => 'volunteer', 'status' => 'active'],
                    'cypher_pattern' => "type = 'volunteer' AND status = 'active'",
                    'examples' => [
                        'Show active volunteers',
                        'List volunteers who are active',
                        'Find all active volunteer members',
                    ],
                ],
                'active_customers' => [
                    'description' => 'Active customers',
                    'filters' => ['type' => 'customer', 'status' => 'active'],
                    'cypher_pattern' => "type = 'customer' AND status = 'active'",
                    'examples' => [
                        'Show active customers',
                        'List customers who are active',
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Example: Team Entity (Graph only, no vector search)
    |--------------------------------------------------------------------------
    */
    'Team' => [
        'graph' => [
            'label' => 'Team',
            'properties' => ['id', 'name', 'created_at'],
            'relationships' => [],
        ],
        // No 'vector' key = not searchable via semantic search
    ],

    /*
    |--------------------------------------------------------------------------
    | Example: Order Entity (with Semantic Metadata)
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
                    'foreign_key' => 'customer_id',
                ],
                [
                    'type' => 'CONTAINS',
                    'target_label' => 'Product',
                    'foreign_key' => 'product_id',
                ],
            ],
        ],
        'vector' => [
            'collection' => 'orders',
            'embed_fields' => ['notes', 'description'],
            'metadata' => ['id', 'customer_id', 'status', 'total'],
        ],

        'metadata' => [
            'aliases' => ['order', 'orders', 'purchase', 'purchases', 'sale', 'sales'],
            'description' => 'Represents customer orders and purchases',

            'scopes' => [
                'pending' => [
                    'description' => 'Orders awaiting processing',
                    'filter' => ['status' => 'pending'],
                    'cypher_pattern' => "status = 'pending'",
                    'examples' => [
                        'Show pending orders',
                        'List orders awaiting processing',
                        'How many pending orders?',
                    ],
                ],
                'completed' => [
                    'description' => 'Orders that have been completed',
                    'filter' => ['status' => 'completed'],
                    'cypher_pattern' => "status = 'completed'",
                    'examples' => [
                        'Show completed orders',
                        'List fulfilled orders',
                        'How many orders are completed?',
                    ],
                ],
                'cancelled' => [
                    'description' => 'Orders that were cancelled',
                    'filter' => ['status' => 'cancelled'],
                    'cypher_pattern' => "status = 'cancelled'",
                    'examples' => [
                        'Show cancelled orders',
                        'List orders that were cancelled',
                    ],
                ],
                'high_value' => [
                    'description' => 'Orders with high total value',
                    'filter' => [],
                    'cypher_pattern' => 'total > 1000',
                    'examples' => [
                        'Show high value orders',
                        'List expensive orders',
                        'Orders over $1000',
                    ],
                ],
            ],

            'common_properties' => [
                'id' => 'Unique order identifier',
                'total' => 'Total order amount in currency',
                'status' => 'Order status: pending, completed, cancelled, etc.',
                'created_at' => 'When the order was created',
                'customer_id' => 'ID of the customer who placed the order',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Example: Product Entity
    |--------------------------------------------------------------------------
    */
    'Product' => [
        'graph' => [
            'label' => 'Product',
            'properties' => ['id', 'name', 'price', 'sku'],
            'relationships' => [
                [
                    'type' => 'IN_CATEGORY',
                    'target_label' => 'Category',
                    'foreign_key' => 'category_id',
                ],
            ],
        ],
        'vector' => [
            'collection' => 'products',
            'embed_fields' => ['name', 'description', 'tags'],
            'metadata' => ['id', 'sku', 'price', 'category_id'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Add Your Custom Entities Here
    |--------------------------------------------------------------------------
    |
    | Follow the pattern above. Each entity should have:
    | - A unique key (matching your model class name)
    | - 'graph' configuration for Neo4j
    | - 'vector' configuration for Qdrant (optional)
    |
    */
];
