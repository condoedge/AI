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
    | Example: Person Entity
    |--------------------------------------------------------------------------
    */
    'Person' => [
        'graph' => [
            'label' => 'Person',
            'properties' => ['id', 'first_name', 'last_name', 'email', 'birth_date'],
            'relationships' => [
                [
                    'type' => 'MEMBER_OF',
                    'target_label' => 'Team',
                    'foreign_key' => 'team_id',
                    'properties' => ['since' => 'created_at'],
                ],
            ],
        ],
        'vector' => [
            'collection' => 'people',
            'embed_fields' => ['first_name', 'last_name', 'bio', 'notes'],
            'metadata' => ['id', 'email', 'team_id'],
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
    | Example: Order Entity
    |--------------------------------------------------------------------------
    */
    'Order' => [
        'graph' => [
            'label' => 'Order',
            'properties' => ['id', 'total', 'status', 'created_at'],
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
