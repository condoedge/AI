<?php

/**
 * Entity Configurations with Relationship-Based Scopes
 *
 * This example file demonstrates the enhanced metadata system that supports
 * relationship-based scopes in addition to simple property filters.
 *
 * Pattern Types:
 * - 'simple': Property filters only (e.g., status = 'active')
 * - 'relationship': Traverse relationships (e.g., Person-[:HAS_ROLE]->PersonTeam)
 * - 'complex': Aggregations, calculations, subqueries
 *
 * Copy this file to config/entities.php to use these patterns.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Person Entity - Comprehensive Example with All Pattern Types
    |--------------------------------------------------------------------------
    */
    'Person' => [
        'graph' => [
            'label' => 'Person',
            'properties' => ['id', 'first_name', 'last_name', 'email', 'birth_date', 'status', 'created_at'],
            'relationships' => [
                [
                    'type' => 'HAS_ROLE',
                    'target_label' => 'PersonTeam',
                    'description' => 'Person has a role on a team through PersonTeam junction node',
                    'properties' => ['role_type', 'since', 'status'],
                ],
                [
                    'type' => 'MEMBER_OF',
                    'target_label' => 'Team',
                    'foreign_key' => 'team_id',
                    'description' => 'Direct team membership (legacy)',
                ],
                [
                    'type' => 'PLACED',
                    'target_label' => 'Order',
                    'description' => 'Orders placed by this person',
                ],
            ],
        ],

        'vector' => [
            'collection' => 'people',
            'embed_fields' => ['first_name', 'last_name', 'bio', 'notes'],
            'metadata' => ['id', 'email', 'status'],
        ],

        'metadata' => [
            'aliases' => ['person', 'people', 'user', 'users', 'individual', 'individuals', 'member', 'members'],
            'description' => 'Individuals in the system including volunteers, customers, team members, and staff',

            'scopes' => [
                // SIMPLE PATTERN: Property filter
                'active' => [
                    'pattern_type' => 'simple',
                    'description' => 'People with active status',
                    'filter' => ['status' => 'active'],
                    'cypher_pattern' => "p.status = 'active'",
                    'examples' => [
                        'Show active people',
                        'List active members',
                        'Who is currently active?',
                        'Find all active users',
                    ],
                ],

                'inactive' => [
                    'pattern_type' => 'simple',
                    'description' => 'People with inactive status',
                    'filter' => ['status' => 'inactive'],
                    'cypher_pattern' => "p.status = 'inactive'",
                    'examples' => [
                        'Show inactive people',
                        'List inactive members',
                    ],
                ],

                // RELATIONSHIP PATTERN: Volunteers
                'volunteers' => [
                    'pattern_type' => 'relationship',
                    'description' => 'People who have volunteer role on any team',

                    'relationship' => [
                        'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
                        'where' => "pt.role_type = 'volunteer'",
                        'return_distinct' => true,
                    ],

                    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
CYPHER,

                    'examples' => [
                        'Show me all volunteers',
                        'How many volunteers do we have?',
                        'List volunteers on teams',
                        'Who are our volunteers?',
                        'Find all volunteer members',
                        'Display volunteer roster',
                    ],
                ],

                // RELATIONSHIP PATTERN: Team Leaders
                'team_leaders' => [
                    'pattern_type' => 'relationship',
                    'description' => 'People who lead teams',

                    'relationship' => [
                        'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
                        'where' => "pt.role_type = 'leader'",
                        'return_distinct' => true,
                    ],

                    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'leader'
RETURN DISTINCT p
CYPHER,

                    'examples' => [
                        'Show me team leaders',
                        'List people who lead teams',
                        'Who are the team leaders?',
                        'Find all leaders',
                        'Show me team managers',
                    ],
                ],

                // RELATIONSHIP PATTERN: Team Members (any role)
                'team_members' => [
                    'pattern_type' => 'relationship',
                    'description' => 'People who are members of any team',

                    'relationship' => [
                        'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
                        'return_distinct' => true,
                    ],

                    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
RETURN DISTINCT p
CYPHER,

                    'examples' => [
                        'Show all team members',
                        'List people on teams',
                        'Who is on a team?',
                        'Find all team participants',
                    ],
                ],

                // COMBINED PATTERN: Active volunteers
                'active_volunteers' => [
                    'pattern_type' => 'relationship',
                    'description' => 'Active people with volunteer role on teams',

                    'relationship' => [
                        'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
                        'where' => "pt.role_type = 'volunteer' AND p.status = 'active'",
                        'return_distinct' => true,
                    ],

                    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer' AND p.status = 'active'
RETURN DISTINCT p
CYPHER,

                    'examples' => [
                        'Show active volunteers',
                        'List volunteers who are active',
                        'Find all active volunteer members',
                        'Who are the active volunteers?',
                    ],
                ],

                // COMPLEX PATTERN: People with multiple teams
                'multi_team_members' => [
                    'pattern_type' => 'complex',
                    'description' => 'People who are members of more than one team',

                    'cypher_template' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(:PersonTeam)-[:ON_TEAM]->(t:Team)
WITH p, count(DISTINCT t) as team_count
WHERE team_count > 1
RETURN p
CYPHER,

                    'examples' => [
                        'Show people on multiple teams',
                        'List members of more than one team',
                        'Who is on multiple teams?',
                    ],

                    'modification_guidance' => 'To change the threshold, modify "team_count > 1"',
                ],

                // RELATIONSHIP PATTERN: Customers (people who placed orders)
                'customers' => [
                    'pattern_type' => 'relationship',
                    'description' => 'People who have placed at least one order',

                    'relationship' => [
                        'pattern' => '(p:Person)-[:PLACED]->(o:Order)',
                        'return_distinct' => true,
                    ],

                    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:PLACED]->(o:Order)
RETURN DISTINCT p
CYPHER,

                    'examples' => [
                        'Show me all customers',
                        'List people who have ordered',
                        'Who are our customers?',
                        'Find all buyers',
                    ],
                ],

                // COMPLEX PATTERN: High-value customers
                'high_value_customers' => [
                    'pattern_type' => 'complex',
                    'description' => 'Customers who have placed orders totaling over $10,000',

                    'cypher_template' => <<<CYPHER
MATCH (p:Person)-[:PLACED]->(o:Order)
WITH p, sum(o.total) as total_value
WHERE total_value > 10000
RETURN p
CYPHER,

                    'examples' => [
                        'Show high value customers',
                        'List customers with over $10k in orders',
                        'Who are our biggest customers?',
                        'Find customers with high order values',
                    ],

                    'modification_guidance' => 'To change the threshold, modify "total_value > 10000"',
                ],

                // COMPLEX PATTERN: Recent customers
                'recent_customers' => [
                    'pattern_type' => 'relationship',
                    'description' => 'Customers who have placed orders in the last 30 days',

                    'relationship' => [
                        'pattern' => '(p:Person)-[:PLACED]->(o:Order)',
                        'where' => 'o.created_at > datetime() - duration({days: 30})',
                        'return_distinct' => true,
                    ],

                    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:PLACED]->(o:Order)
WHERE o.created_at > datetime() - duration({days: 30})
RETURN DISTINCT p
CYPHER,

                    'examples' => [
                        'Show recent customers',
                        'List customers who ordered recently',
                        'Who has ordered in the last month?',
                        'Find new customers',
                    ],
                ],
            ],

            'common_properties' => [
                'id' => 'Unique identifier for the person',
                'first_name' => 'Person\'s first name',
                'last_name' => 'Person\'s last name',
                'email' => 'Email address',
                'birth_date' => 'Date of birth (date format)',
                'status' => 'Current status: active, inactive, suspended',
                'created_at' => 'When the person record was created (datetime)',
            ],

            // Relationship documentation for LLM
            'relationships' => [
                'HAS_ROLE' => [
                    'description' => 'Person has a role on a team through PersonTeam junction node',
                    'target' => 'PersonTeam',
                    'properties' => ['role_type', 'since', 'status'],
                    'common_patterns' => [
                        'Volunteers: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.role_type = "volunteer"',
                        'Leaders: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.role_type = "leader"',
                        'Active roles: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.status = "active"',
                    ],
                ],
                'MEMBER_OF' => [
                    'description' => 'Direct team membership (legacy relationship)',
                    'target' => 'Team',
                    'common_patterns' => [
                        'Team members: (p)-[:MEMBER_OF]->(t:Team)',
                    ],
                ],
                'PLACED' => [
                    'description' => 'Orders placed by this person',
                    'target' => 'Order',
                    'common_patterns' => [
                        'Customers: (p)-[:PLACED]->(o:Order)',
                        'Recent orders: (p)-[:PLACED]->(o:Order) WHERE o.created_at > datetime() - duration({days: 30})',
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PersonTeam Entity - Junction Node for Team Membership
    |--------------------------------------------------------------------------
    */
    'PersonTeam' => [
        'graph' => [
            'label' => 'PersonTeam',
            'properties' => ['id', 'role_type', 'since', 'status', 'created_at'],
            'relationships' => [
                [
                    'type' => 'ON_TEAM',
                    'target_label' => 'Team',
                    'description' => 'Links person-role to specific team',
                ],
            ],
        ],

        'metadata' => [
            'aliases' => ['person_team', 'team_role', 'membership'],
            'description' => 'Junction node representing a person\'s role on a specific team',

            'common_properties' => [
                'id' => 'Unique identifier for this team membership',
                'role_type' => 'Role on the team: volunteer, leader, member, coordinator',
                'since' => 'When this person joined the team in this role (date)',
                'status' => 'Membership status: active, inactive, pending',
                'created_at' => 'When this membership was created (datetime)',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Entity - Simple with Relationship Context
    |--------------------------------------------------------------------------
    */
    'Team' => [
        'graph' => [
            'label' => 'Team',
            'properties' => ['id', 'name', 'description', 'status', 'created_at'],
            'relationships' => [],
        ],

        'vector' => [
            'collection' => 'teams',
            'embed_fields' => ['name', 'description'],
            'metadata' => ['id', 'status'],
        ],

        'metadata' => [
            'aliases' => ['team', 'teams', 'group', 'groups'],
            'description' => 'Teams that people can join in various roles',

            'scopes' => [
                'active' => [
                    'pattern_type' => 'simple',
                    'description' => 'Teams that are currently active',
                    'filter' => ['status' => 'active'],
                    'cypher_pattern' => "t.status = 'active'",
                    'examples' => [
                        'Show active teams',
                        'List active groups',
                    ],
                ],

                // RELATIONSHIP PATTERN: Teams with volunteers
                'with_volunteers' => [
                    'pattern_type' => 'relationship',
                    'description' => 'Teams that have at least one volunteer',

                    'relationship' => [
                        'pattern' => '(t:Team)<-[:ON_TEAM]-(pt:PersonTeam)<-[:HAS_ROLE]-(p:Person)',
                        'where' => "pt.role_type = 'volunteer'",
                        'return_distinct' => true,
                    ],

                    'cypher_pattern' => <<<CYPHER
MATCH (t:Team)<-[:ON_TEAM]-(pt:PersonTeam)<-[:HAS_ROLE]-(p:Person)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT t
CYPHER,

                    'examples' => [
                        'Show teams with volunteers',
                        'List teams that have volunteers',
                        'Which teams have volunteer members?',
                    ],
                ],

                // COMPLEX PATTERN: Large teams
                'large_teams' => [
                    'pattern_type' => 'complex',
                    'description' => 'Teams with more than 10 members',

                    'cypher_template' => <<<CYPHER
MATCH (t:Team)<-[:ON_TEAM]-(pt:PersonTeam)<-[:HAS_ROLE]-(p:Person)
WITH t, count(DISTINCT p) as member_count
WHERE member_count > 10
RETURN t
CYPHER,

                    'examples' => [
                        'Show large teams',
                        'List teams with many members',
                        'Which teams have more than 10 people?',
                    ],

                    'modification_guidance' => 'To change size threshold, modify "member_count > 10"',
                ],
            ],

            'common_properties' => [
                'id' => 'Unique team identifier',
                'name' => 'Team name',
                'description' => 'Team description and purpose',
                'status' => 'Team status: active, inactive, archived',
                'created_at' => 'When team was created (datetime)',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Entity - E-commerce Example
    |--------------------------------------------------------------------------
    */
    'Order' => [
        'graph' => [
            'label' => 'Order',
            'properties' => ['id', 'total', 'status', 'created_at', 'updated_at'],
            'relationships' => [
                [
                    'type' => 'PLACED_BY',
                    'target_label' => 'Person',
                    'foreign_key' => 'customer_id',
                    'description' => 'Customer who placed this order',
                ],
                [
                    'type' => 'CONTAINS',
                    'target_label' => 'Product',
                    'description' => 'Products in this order',
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
            'description' => 'Customer orders and purchases',

            'scopes' => [
                // SIMPLE PATTERNS: Order status
                'pending' => [
                    'pattern_type' => 'simple',
                    'description' => 'Orders awaiting processing',
                    'filter' => ['status' => 'pending'],
                    'cypher_pattern' => "o.status = 'pending'",
                    'examples' => [
                        'Show pending orders',
                        'List orders awaiting processing',
                        'How many pending orders?',
                    ],
                ],

                'completed' => [
                    'pattern_type' => 'simple',
                    'description' => 'Orders that have been completed',
                    'filter' => ['status' => 'completed'],
                    'cypher_pattern' => "o.status = 'completed'",
                    'examples' => [
                        'Show completed orders',
                        'List fulfilled orders',
                        'How many orders are completed?',
                    ],
                ],

                'cancelled' => [
                    'pattern_type' => 'simple',
                    'description' => 'Orders that were cancelled',
                    'filter' => ['status' => 'cancelled'],
                    'cypher_pattern' => "o.status = 'cancelled'",
                    'examples' => [
                        'Show cancelled orders',
                        'List orders that were cancelled',
                    ],
                ],

                'high_value' => [
                    'pattern_type' => 'simple',
                    'description' => 'Orders with high total value',
                    'filter' => [],
                    'cypher_pattern' => 'o.total > 1000',
                    'examples' => [
                        'Show high value orders',
                        'List expensive orders',
                        'Orders over $1000',
                    ],
                ],

                // RELATIONSHIP PATTERN: Orders with products
                'with_products' => [
                    'pattern_type' => 'relationship',
                    'description' => 'Orders containing products',

                    'relationship' => [
                        'pattern' => '(o:Order)-[:CONTAINS]->(p:Product)',
                        'return_distinct' => true,
                    ],

                    'cypher_pattern' => <<<CYPHER
MATCH (o:Order)-[:CONTAINS]->(p:Product)
RETURN DISTINCT o
CYPHER,

                    'examples' => [
                        'Show orders with products',
                        'List orders containing items',
                    ],
                ],

                // COMPLEX PATTERN: Large orders
                'large_orders' => [
                    'pattern_type' => 'complex',
                    'description' => 'Orders with more than 5 products',

                    'cypher_template' => <<<CYPHER
MATCH (o:Order)-[:CONTAINS]->(p:Product)
WITH o, count(p) as product_count
WHERE product_count > 5
RETURN o
CYPHER,

                    'examples' => [
                        'Show large orders',
                        'List orders with many products',
                        'Orders with more than 5 items',
                    ],

                    'modification_guidance' => 'To change item threshold, modify "product_count > 5"',
                ],

                // RELATIONSHIP PATTERN: Recent orders
                'recent' => [
                    'pattern_type' => 'simple',
                    'description' => 'Orders placed in the last 30 days',
                    'filter' => [],
                    'cypher_pattern' => 'o.created_at > datetime() - duration({days: 30})',
                    'examples' => [
                        'Show recent orders',
                        'List orders from last month',
                        'Orders in the last 30 days',
                    ],
                ],
            ],

            'common_properties' => [
                'id' => 'Unique order identifier',
                'total' => 'Total order amount in currency',
                'status' => 'Order status: pending, completed, cancelled, shipped',
                'created_at' => 'When the order was created (datetime)',
                'updated_at' => 'When the order was last updated (datetime)',
                'customer_id' => 'ID of the customer who placed the order',
            ],

            'relationships' => [
                'PLACED_BY' => [
                    'description' => 'Customer who placed this order',
                    'target' => 'Person',
                    'common_patterns' => [
                        'Orders by customer: (o)-[:PLACED_BY]->(p:Person)',
                    ],
                ],
                'CONTAINS' => [
                    'description' => 'Products in this order',
                    'target' => 'Product',
                    'common_patterns' => [
                        'Order products: (o)-[:CONTAINS]->(p:Product)',
                        'Large orders: (o)-[:CONTAINS]->(p:Product) WITH o, count(p) WHERE count > threshold',
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Entity - Simple Example
    |--------------------------------------------------------------------------
    */
    'Product' => [
        'graph' => [
            'label' => 'Product',
            'properties' => ['id', 'name', 'price', 'sku', 'stock', 'status'],
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

        'metadata' => [
            'aliases' => ['product', 'products', 'item', 'items'],
            'description' => 'Products available for purchase',

            'scopes' => [
                'in_stock' => [
                    'pattern_type' => 'simple',
                    'description' => 'Products currently in stock',
                    'filter' => [],
                    'cypher_pattern' => 'p.stock > 0',
                    'examples' => [
                        'Show products in stock',
                        'List available products',
                    ],
                ],

                'out_of_stock' => [
                    'pattern_type' => 'simple',
                    'description' => 'Products out of stock',
                    'filter' => ['stock' => 0],
                    'cypher_pattern' => 'p.stock = 0',
                    'examples' => [
                        'Show out of stock products',
                        'List unavailable items',
                    ],
                ],

                'active' => [
                    'pattern_type' => 'simple',
                    'description' => 'Active products',
                    'filter' => ['status' => 'active'],
                    'cypher_pattern' => "p.status = 'active'",
                    'examples' => [
                        'Show active products',
                        'List available items',
                    ],
                ],
            ],

            'common_properties' => [
                'id' => 'Unique product identifier',
                'name' => 'Product name',
                'price' => 'Product price in currency',
                'sku' => 'Stock keeping unit (unique code)',
                'stock' => 'Current stock quantity (integer)',
                'status' => 'Product status: active, discontinued, coming_soon',
            ],
        ],
    ],
];
