<?php

/**
 * ============================================================================
 * ENTITY CONFIGURATIONS - Getting Started
 * ============================================================================
 *
 * Welcome! This file defines how your domain entities map to:
 * - Neo4j (graph relationships)
 * - Qdrant (semantic/vector search)
 * - AI query generation (semantic metadata)
 *
 * ============================================================================
 * QUICK START (90% of use cases)
 * ============================================================================
 *
 * Just implement the `Nodeable` interface in your model:
 *
 *   use Condoedge\Ai\Domain\Contracts\Nodeable;
 *   use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
 *
 *   class Customer extends Model implements Nodeable
 *   {
 *       use HasNodeableConfig;  // That's it!
 *   }
 *
 * The trait automatically loads configuration from this file.
 *
 * ============================================================================
 * ZERO CONFIGURATION AUTO-DISCOVERY
 * ============================================================================
 *
 * Leave this file empty (or don't add your entity) and the system will:
 * - Auto-detect properties from $fillable, $casts, $dates
 * - Auto-detect relationships from belongsTo(), hasMany(), etc.
 * - Auto-detect scopes from scopeActive(), scopeRecent(), etc.
 * - Auto-generate aliases from table name inflections
 *
 * ============================================================================
 * WHEN TO ADD CUSTOM CONFIGURATION
 * ============================================================================
 *
 * Add entities here when you need:
 * 1. Custom aliases (e.g., "volunteers" for Person with type='volunteer')
 * 2. Semantic scopes (business terms like "high_value_customers")
 * 3. Custom relationship mapping for graph queries
 * 4. Specific fields for vector embeddings
 * 5. Property descriptions to help AI understand your domain
 *
 * ============================================================================
 * CONFIGURATION STRUCTURE
 * ============================================================================
 *
 * Each entity configuration has:
 *
 * 'EntityName' => [
 *     // Neo4j graph storage (REQUIRED)
 *     'graph' => [
 *         'label' => 'NodeLabel',
 *         'properties' => ['id', 'name', 'status'],
 *         'relationships' => [...],  // See examples below
 *     ],
 *
 *     // Qdrant vector search (OPTIONAL - only for semantic search)
 *     'vector' => [
 *         'collection' => 'collection_name',
 *         'embed_fields' => ['name', 'description'],  // Fields to embed
 *         'metadata' => ['id', 'status'],             // Metadata to store
 *     ],
 *
 *     // Semantic metadata for AI (OPTIONAL - improves query accuracy)
 *     'metadata' => [
 *         'aliases' => ['singular', 'plural', 'synonym'],
 *         'description' => 'What this entity represents',
 *         'scopes' => [...],           // Business terminology
 *         'common_properties' => [...], // Property descriptions
 *     ],
 *
 *     // Auto-sync to Neo4j (OPTIONAL)
 *     'auto_sync' => [
 *         'create' => true,  // Sync on model creation
 *         'update' => true,  // Sync on model update
 *         'delete' => true,  // Remove from Neo4j on delete
 *     ],
 * ],
 *
 * ============================================================================
 */

return [

    /*
    |==========================================================================
    | EXAMPLE 1: SIMPLE ENTITY WITH STATUS SCOPES
    |==========================================================================
    |
    | A basic entity with property-based filtering.
    | Common for entities with status, type, or category fields.
    |
    */

    // Example: Uncomment to use
    // 'Order' => [
    //     'graph' => [
    //         'label' => 'Order',
    //         'properties' => ['id', 'total', 'status', 'created_at'],
    //         'relationships' => [
    //             [
    //                 'type' => 'PLACED_BY',
    //                 'target_label' => 'Customer',
    //                 'foreign_key' => 'customer_id',
    //             ],
    //         ],
    //     ],
    //
    //     'vector' => [
    //         'collection' => 'orders',
    //         'embed_fields' => ['notes', 'description'],
    //         'metadata' => ['id', 'status', 'total'],
    //     ],
    //
    //     'metadata' => [
    //         'aliases' => ['order', 'orders', 'purchase', 'sale'],
    //         'description' => 'Customer orders and purchases',
    //
    //         // Simple property-based scopes
    //         'scopes' => [
    //             'pending' => [
    //                 'description' => 'Orders awaiting processing',
    //                 'filter' => ['status' => 'pending'],
    //                 'cypher_pattern' => "status = 'pending'",
    //                 'examples' => [
    //                     'Show pending orders',
    //                     'How many orders are pending?',
    //                 ],
    //             ],
    //             'completed' => [
    //                 'description' => 'Orders that have been fulfilled',
    //                 'filter' => ['status' => 'completed'],
    //                 'cypher_pattern' => "status = 'completed'",
    //                 'examples' => ['Show completed orders'],
    //             ],
    //             'high_value' => [
    //                 'description' => 'Orders with high total value',
    //                 'filter' => [],
    //                 'cypher_pattern' => 'total > 1000',
    //                 'examples' => ['Show high value orders', 'Orders over $1000'],
    //             ],
    //         ],
    //
    //         'common_properties' => [
    //             'id' => 'Unique order identifier',
    //             'total' => 'Total order amount in currency',
    //             'status' => 'Order status: pending, processing, completed, cancelled',
    //             'created_at' => 'When the order was placed',
    //         ],
    //     ],
    // ],

    /*
    |==========================================================================
    | EXAMPLE 2: RELATIONSHIP-BASED SCOPES (ADVANCED)
    |==========================================================================
    |
    | When your business terms require graph traversal.
    | Example: "volunteers" = Person → PersonTeam (where role='volunteer') → Team
    |
    */

    // Example: Uncomment to use
    // 'Person' => [
    //     'graph' => [
    //         'label' => 'Person',
    //         'properties' => ['id', 'first_name', 'last_name', 'email', 'status'],
    //         'relationships' => [
    //             [
    //                 'type' => 'HAS_ROLE',
    //                 'target_label' => 'PersonTeam',
    //                 'description' => 'Person has a role on a team',
    //             ],
    //             [
    //                 'type' => 'MEMBER_OF',
    //                 'target_label' => 'Team',
    //                 'foreign_key' => 'team_id',
    //             ],
    //         ],
    //     ],
    //
    //     'vector' => [
    //         'collection' => 'people',
    //         'embed_fields' => ['first_name', 'last_name', 'bio'],
    //         'metadata' => ['id', 'email', 'status'],
    //     ],
    //
    //     'metadata' => [
    //         'aliases' => ['person', 'people', 'user', 'member', 'individual'],
    //         'description' => 'Individuals in the system',
    //
    //         'scopes' => [
    //             // Simple property filter
    //             'active' => [
    //                 'specification_type' => 'property_filter',
    //                 'concept' => 'People who are currently active',
    //                 'filter' => [
    //                     'property' => 'status',
    //                     'operator' => 'equals',
    //                     'value' => 'active',
    //                 ],
    //                 'business_rules' => [
    //                     'Active people can access the system',
    //                 ],
    //                 'examples' => ['Show active people', 'List active members'],
    //             ],
    //
    //             // Relationship traversal (uses graph paths)
    //             'volunteers' => [
    //                 'specification_type' => 'relationship_traversal',
    //                 'concept' => 'People who volunteer their time on teams',
    //                 'relationship_spec' => [
    //                     'start_entity' => 'Person',
    //                     'path' => [
    //                         [
    //                             'relationship' => 'HAS_ROLE',
    //                             'target_entity' => 'PersonTeam',
    //                             'direction' => 'outgoing',
    //                         ],
    //                     ],
    //                     'filter' => [
    //                         'entity' => 'PersonTeam',
    //                         'property' => 'role_type',
    //                         'operator' => 'equals',
    //                         'value' => 'volunteer',
    //                     ],
    //                     'return_distinct' => true,
    //                 ],
    //                 'business_rules' => [
    //                     'A person is a volunteer if they have at least one volunteer role',
    //                     'The volunteer role is stored in PersonTeam.role_type',
    //                 ],
    //                 'examples' => [
    //                     'Show me all volunteers',
    //                     'How many volunteers do we have?',
    //                 ],
    //             ],
    //
    //             // Pattern library usage (for common patterns)
    //             'people_without_teams' => [
    //                 'specification_type' => 'pattern',
    //                 'concept' => 'People who are not on any team',
    //                 'pattern' => 'entity_without_relationship',
    //                 'pattern_params' => [
    //                     'entity' => 'Person',
    //                     'relationship' => 'MEMBER_OF',
    //                     'target_entity' => 'Team',
    //                     'direction' => 'outgoing',
    //                 ],
    //                 'business_rules' => [
    //                     'Person without teams has no MEMBER_OF relationship',
    //                 ],
    //                 'examples' => ['Show people without teams', 'List unassigned people'],
    //             ],
    //         ],
    //
    //         'common_properties' => [
    //             'id' => 'Unique identifier for the person',
    //             'first_name' => 'Person\'s first name',
    //             'last_name' => 'Person\'s last name',
    //             'email' => 'Email address',
    //             'status' => 'Current status: active, inactive, suspended',
    //         ],
    //     ],
    // ],

    /*
    |==========================================================================
    | EXAMPLE 3: FILE ENTITY (DUAL STORAGE)
    |==========================================================================
    |
    | Files use both Neo4j (metadata) and Qdrant (content chunks).
    | Perfect for document search with AI-powered answers.
    |
    */

    // Example: Uncomment to use
    // 'File' => [
    //     'graph' => [
    //         'label' => 'File',
    //         'properties' => [
    //             'id',
    //             'name',
    //             'original_name',
    //             'size',
    //             'extension',
    //             'mime_type',
    //             'path',
    //             'uploaded_at',
    //         ],
    //         'relationships' => [
    //             [
    //                 'type' => 'UPLOADED_BY',
    //                 'target_label' => 'User',
    //                 'foreign_key' => 'user_id',
    //             ],
    //             [
    //                 'type' => 'BELONGS_TO_TEAM',
    //                 'target_label' => 'Team',
    //                 'foreign_key' => 'team_id',
    //             ],
    //         ],
    //     ],
    //     // Note: File content is stored separately via FileProcessor
    //     // No 'vector' config needed - content is chunked automatically
    //
    //     'metadata' => [
    //         'aliases' => ['file', 'files', 'document', 'attachment'],
    //         'description' => 'Uploaded files with searchable content',
    //
    //         'scopes' => [
    //             'documents' => [
    //                 'specification_type' => 'property_filter',
    //                 'concept' => 'Document files (PDF, DOCX, TXT)',
    //                 'filter' => [
    //                     'property' => 'extension',
    //                     'operator' => 'in',
    //                     'value' => ['pdf', 'docx', 'txt', 'md'],
    //                 ],
    //                 'examples' => ['Show all documents', 'Find PDF files'],
    //             ],
    //             'images' => [
    //                 'specification_type' => 'property_filter',
    //                 'concept' => 'Image files',
    //                 'filter' => [
    //                     'property' => 'mime_type',
    //                     'operator' => 'starts_with',
    //                     'value' => 'image/',
    //                 ],
    //                 'examples' => ['Show all images', 'List photos'],
    //             ],
    //         ],
    //
    //         'common_properties' => [
    //             'name' => 'File name',
    //             'extension' => 'File extension (pdf, docx, jpg, etc.)',
    //             'mime_type' => 'MIME type of the file',
    //             'size' => 'File size in bytes',
    //         ],
    //     ],
    //
    //     'auto_sync' => [
    //         'create' => true,
    //         'update' => true,
    //         'delete' => true,
    //     ],
    // ],

    /*
    |==========================================================================
    | EXAMPLE 4: GRAPH-ONLY ENTITY (NO SEMANTIC SEARCH)
    |==========================================================================
    |
    | Some entities don't need semantic search - just graph relationships.
    | Omit the 'vector' key to disable vector storage.
    |
    */

    // Example: Uncomment to use
    // 'Team' => [
    //     'graph' => [
    //         'label' => 'Team',
    //         'properties' => ['id', 'name', 'department', 'created_at'],
    //         'relationships' => [
    //             [
    //                 'type' => 'HAS_MANAGER',
    //                 'target_label' => 'Person',
    //                 'foreign_key' => 'manager_id',
    //             ],
    //         ],
    //     ],
    //     // No 'vector' key = not searchable via semantic search
    //
    //     'metadata' => [
    //         'aliases' => ['team', 'teams', 'group'],
    //         'description' => 'Organizational teams',
    //
    //         'scopes' => [
    //             'marketing' => [
    //                 'specification_type' => 'property_filter',
    //                 'concept' => 'Teams in the Marketing department',
    //                 'filter' => [
    //                     'property' => 'department',
    //                     'operator' => 'equals',
    //                     'value' => 'Marketing',
    //                 ],
    //                 'examples' => ['Show marketing teams'],
    //             ],
    //         ],
    //
    //         'common_properties' => [
    //             'department' => 'Department: Marketing, Sales, Engineering, etc.',
    //         ],
    //     ],
    // ],

    /*
    |==========================================================================
    | COMMON PATTERNS REFERENCE
    |==========================================================================
    |
    | Pattern Library Available Patterns:
    |
    | 1. property_filter
    |    - Simple attribute filtering (status, type, role)
    |    - Example: Find active people, pending orders
    |
    | 2. property_range
    |    - Numeric range filtering (age, price, quantity)
    |    - Example: Orders between $100-$500
    |
    | 3. relationship_traversal
    |    - Graph path navigation with filtering
    |    - Example: Find people who volunteer
    |
    | 4. entity_with_relationship
    |    - Has at least one relationship
    |    - Example: Customers with orders
    |
    | 5. entity_without_relationship
    |    - Missing a specific relationship
    |    - Example: People without teams
    |
    | 6. entity_with_aggregated_relationship
    |    - Aggregation-based filtering (sum, count, avg)
    |    - Example: High-value customers (sum of orders > $10k)
    |
    | 7. temporal_filter
    |    - Date/time-based filtering
    |    - Example: Recent customers (joined within 30 days)
    |
    | 8. multiple_property_filter
    |    - Combine multiple property conditions with AND/OR
    |    - Example: Active volunteers
    |
    | For runtime pattern implementation, see config/ai-patterns.php
    |
    */

    /*
    |==========================================================================
    | SCOPE SPECIFICATION TYPES REFERENCE
    |==========================================================================
    |
    | specification_type options:
    |
    | 'property_filter' - Simple property matching
    |   Required: filter[property, operator, value]
    |   Example: status = 'active'
    |
    | 'relationship_traversal' - Graph path navigation
    |   Required: relationship_spec[start_entity, path, filter]
    |   Example: Person -> PersonTeam (role='volunteer') -> Team
    |
    | 'pattern' - Use pre-built pattern from library
    |   Required: pattern, pattern_params
    |   Example: entity_without_relationship
    |
    */

    /*
    |==========================================================================
    | BEST PRACTICES
    |==========================================================================
    |
    | DO:
    | ✓ Use business terms users actually say
    | ✓ Include both singular and plural in aliases
    | ✓ Provide clear, non-technical descriptions
    | ✓ Add multiple example questions (3-5)
    | ✓ Document property types and possible values
    | ✓ Start simple, add complexity only when needed
    |
    | DON'T:
    | ✗ Use technical database terms as scope names
    | ✗ Forget to test after adding metadata
    | ✗ Leave out common variations in aliases
    | ✗ Use ambiguous or vague descriptions
    | ✗ Skip property documentation
    | ✗ Over-engineer - keep it simple!
    |
    */

    /*
    |==========================================================================
    | TESTING YOUR CONFIGURATION
    |==========================================================================
    |
    | 1. Test entity detection:
    |    php examples/EntityMetadataDemo.php
    |
    | 2. Test scope detection:
    |    $metadata = $retriever->getEntityMetadata('Show volunteers');
    |    var_dump($metadata['detected_scopes']);
    |
    | 3. Run unit tests:
    |    ./vendor/bin/phpunit tests/Unit/Services/EntityMetadataTest.php
    |
    */

    /*
    |==========================================================================
    | DOCUMENTATION LINKS
    |==========================================================================
    |
    | Quick Start Guide:
    |   docs/ENTITY_METADATA_QUICKSTART.md
    |
    | Full Documentation:
    |   docs/SEMANTIC_METADATA_REDESIGN.md
    |
    | Relationship Scopes:
    |   docs/RELATIONSHIP_SCOPES_QUICKSTART.md
    |
    | File Processing:
    |   docs/FILE_PROCESSING_DESIGN.md
    |
    | Examples:
    |   examples/EntityMetadataDemo.php
    |   examples/SemanticMetadataExample.php
    |
    */

    /*
    |==========================================================================
    | YOUR CUSTOM ENTITIES
    |==========================================================================
    |
    | Add your entity configurations below.
    | Start with the simplest approach and expand as needed.
    |
    */

    // Add your entities here...

];
