<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI System Configuration
    |--------------------------------------------------------------------------
    |
    | Main configuration for the AI Text-to-Query system.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Documentation Settings (Larecipe)
    |--------------------------------------------------------------------------
    */
    'documentation' => [
        'enabled' => env('AI_DOCS_ENABLED', true),
        'route_prefix' => env('AI_DOCS_PREFIX', 'ai-docs'),
        'middleware' => ['web'], // Add 'auth' if you want to protect docs

        // Larecipe configuration
        'larecipe' => [
            'path' => resource_path('docs'),
            'default_version' => '1.0',
            'versions' => ['1.0'],
            'title' => 'AI System Documentation',
            'description' => 'Complete documentation for the AI Text-to-Query System',
            'author' => 'AI System',
            'url' => env('APP_URL', 'http://localhost'),

            // Auto-generation settings
            'auto_generate' => env('AI_DOCS_AUTO_GENERATE', false),
            'generate_on_deploy' => env('AI_DOCS_GENERATE_ON_DEPLOY', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Discovery Configuration
    |--------------------------------------------------------------------------
    |
    | Auto-discovery analyzes Eloquent models and generates entity configs.
    |
    | IMPORTANT: Runtime auto-discovery is DISABLED by default for performance.
    |
    | Workflow:
    |   1. Run: php artisan ai:discover
    |   2. Review generated config/entities.php
    |   3. Customize as needed
    |   4. Use static config at runtime (fast!)
    |
    | Only enable runtime_enabled for development/testing.
    |
    */
    'auto_discovery' => [
        // Enable auto-discovery command (php artisan ai:discover)
        'enabled' => env('AI_AUTO_DISCOVERY_ENABLED', true),

        // Enable runtime auto-discovery (SLOW - only for dev/testing)
        // When false, entities MUST be in config/entities.php or have nodeableConfig() method
        'runtime_enabled' => env('AI_AUTO_DISCOVERY_RUNTIME', false),

        // Cache discovered configurations
        'cache' => [
            'enabled' => env('AI_AUTO_DISCOVERY_CACHE', true),
            'ttl' => env('AI_AUTO_DISCOVERY_CACHE_TTL', 3600), // 1 hour
            'prefix' => 'ai.discovery.',
        ],

        // What to auto-discover
        'discover' => [
            'properties' => true,
            'relationships' => true,
            'scopes' => true,
            'aliases' => true,
            'embed_fields' => true,
        ],

        // Customization
        'alias_mappings' => [
            // Map table names to additional aliases
            // 'customers' => ['client', 'buyer'],
            // 'orders' => ['purchase', 'transaction'],
        ],

        'exclude_properties' => [
            // Additional properties to exclude from discovery
            // (password, tokens, etc. are excluded by default)
            // 'internal_notes',
            // 'admin_only_field',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Automatically sync Nodeable entities to Neo4j and Qdrant when they are
    | created, updated, or deleted in your database.
    |
    */
    'auto_sync' => [
        // Enable auto-sync globally (can be overridden per entity)
        'enabled' => env('AI_AUTO_SYNC_ENABLED', true),

        // Queue auto-sync operations for async processing
        'queue' => env('AI_AUTO_SYNC_QUEUE', false),

        // Queue connection to use (null = default)
        'queue_connection' => env('AI_AUTO_SYNC_QUEUE_CONNECTION', null),

        // Queue name to use
        'queue_name' => env('AI_AUTO_SYNC_QUEUE_NAME', 'default'),

        // Operations to sync automatically
        'operations' => [
            'create' => env('AI_AUTO_SYNC_CREATE', true),  // Sync on model creation
            'update' => env('AI_AUTO_SYNC_UPDATE', true),  // Sync on model update
            'delete' => env('AI_AUTO_SYNC_DELETE', true),  // Remove on model deletion
        ],

        // Error handling
        'fail_silently' => env('AI_AUTO_SYNC_FAIL_SILENTLY', true),  // Don't throw exceptions
        'log_errors' => env('AI_AUTO_SYNC_LOG_ERRORS', true),        // Log sync errors

        // Performance
        'eager_load_relationships' => env('AI_AUTO_SYNC_EAGER_LOAD', true),  // Load relationships before sync
    ],

    /*
    |--------------------------------------------------------------------------
    | Graph Database Configuration
    |--------------------------------------------------------------------------
    */
    'graph' => [
        'default' => env('AI_GRAPH_STORE', 'neo4j'),

        'neo4j' => [
            'enabled' => env('NEO4J_ENABLED', true),
            'uri' => env('NEO4J_URI', 'bolt://localhost:7687'),
            'username' => env('NEO4J_USERNAME', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', 'neo4j_password'),
            'database' => env('NEO4J_DATABASE', 'neo4j'), // Default database
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector Database Configuration
    |--------------------------------------------------------------------------
    */
    'vector' => [
        'default' => env('AI_VECTOR_STORE', 'qdrant'),

        'qdrant' => [
            'enabled' => env('QDRANT_ENABLED', true),
            'host' => env('QDRANT_HOST', 'localhost'),
            'port' => env('QDRANT_PORT', 6333),
            'api_key' => env('QDRANT_API_KEY', null), // Optional for cloud instances
            'timeout' => env('QDRANT_TIMEOUT', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Provider Configuration
    |--------------------------------------------------------------------------
    */
    'llm' => [
        'default' => env('AI_LLM_PROVIDER', 'openai'), // 'openai' or 'anthropic'

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'temperature' => env('OPENAI_TEMPERATURE', 0.3),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 2000),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022'),
            'temperature' => env('ANTHROPIC_TEMPERATURE', 0.3),
            'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 2000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Provider Configuration
    |--------------------------------------------------------------------------
    */
    'embedding' => [
        'default' => env('AI_EMBEDDING_PROVIDER', 'openai'), // 'openai' or 'anthropic'

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'dimensions' => 1536, // text-embedding-3-small = 1536, ada-002 = 1536
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_EMBEDDING_MODEL', 'claude-3-5-sonnet-20241022'), // Hypothetical
            'dimensions' => 1024,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Generation Settings
    |--------------------------------------------------------------------------
    */
    'query_generation' => [
        'default_limit' => env('AI_QUERY_DEFAULT_LIMIT', 100),
        'max_limit' => env('AI_QUERY_MAX_LIMIT', 1000),
        'allow_write_operations' => env('AI_ALLOW_WRITE_OPS', false),
        'max_retries' => env('AI_QUERY_MAX_RETRIES', 3),
        'temperature' => env('AI_QUERY_TEMPERATURE', 0.1), // Low for consistency
        'max_complexity' => env('AI_QUERY_MAX_COMPLEXITY', 100),
        'enable_templates' => env('AI_ENABLE_TEMPLATES', true),
        'template_confidence_threshold' => env('AI_TEMPLATE_THRESHOLD', 0.8),
        'timeout' => env('AI_QUERY_TIMEOUT', 30),
        'cache_ttl' => env('AI_CACHE_TTL', 3600), // Cache results for 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Execution Settings
    |--------------------------------------------------------------------------
    */
    'query_execution' => [
        'default_timeout' => env('AI_EXEC_TIMEOUT', 30), // seconds
        'max_timeout' => env('AI_EXEC_MAX_TIMEOUT', 120),
        'default_limit' => env('AI_EXEC_DEFAULT_LIMIT', 100),
        'max_limit' => env('AI_EXEC_MAX_LIMIT', 1000),
        'read_only_mode' => env('AI_EXEC_READ_ONLY', true),
        'default_format' => env('AI_EXEC_FORMAT', 'table'), // table, graph, json
        'enable_explain' => env('AI_EXEC_ENABLE_EXPLAIN', true),
        'log_slow_queries' => env('AI_EXEC_LOG_SLOW', true),
        'slow_query_threshold_ms' => env('AI_EXEC_SLOW_THRESHOLD', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Generation Settings
    |--------------------------------------------------------------------------
    */
    'response_generation' => [
        'default_format' => env('AI_RESPONSE_FORMAT', 'text'), // text, markdown, json
        'default_style' => env('AI_RESPONSE_STYLE', 'detailed'), // concise, detailed, technical
        'default_max_length' => env('AI_RESPONSE_MAX_LENGTH', 200), // words
        'temperature' => env('AI_RESPONSE_TEMPERATURE', 0.3),
        'include_insights' => env('AI_RESPONSE_INSIGHTS', true),
        'include_visualizations' => env('AI_RESPONSE_VIZ', true),
        'summarize_threshold' => env('AI_RESPONSE_SUMMARIZE_THRESHOLD', 10), // rows
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Retrieval (RAG) Settings
    |--------------------------------------------------------------------------
    */
    'rag' => [
        'vector_search_limit' => env('AI_VECTOR_SEARCH_LIMIT', 5),
        'similarity_threshold' => env('AI_SIMILARITY_THRESHOLD', 0.7),
        'include_schema' => env('AI_INCLUDE_SCHEMA', true),
        'include_examples' => env('AI_INCLUDE_EXAMPLES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for file content processing and semantic search.
    | Files are stored in dual storage:
    | - Neo4j: File metadata and relationships
    | - Qdrant: File content chunks with embeddings for semantic search
    |
    */
    'file_processing' => [
        // Enable file content processing
        'enabled' => env('AI_FILE_PROCESSING_ENABLED', true),

        // Qdrant collection name for file chunks
        'collection' => env('AI_FILE_COLLECTION', 'file_chunks'),

        // Supported file types for content extraction
        'supported_types' => ['pdf', 'docx', 'txt', 'md', 'markdown', 'log', 'text'],

        // Chunking settings
        'chunk_size' => env('AI_FILE_CHUNK_SIZE', 1000), // characters
        'chunk_overlap' => env('AI_FILE_CHUNK_OVERLAP', 200), // characters
        'preserve_sentences' => env('AI_FILE_PRESERVE_SENTENCES', true),
        'preserve_paragraphs' => env('AI_FILE_PRESERVE_PARAGRAPHS', true),

        // Queue processing settings
        'queue' => env('AI_FILE_QUEUE', false), // Queue file processing
        'queue_connection' => env('AI_FILE_QUEUE_CONNECTION', null),
        'queue_name' => env('AI_FILE_QUEUE_NAME', 'default'),
        'queue_threshold_bytes' => env('AI_FILE_QUEUE_THRESHOLD', 5 * 1024 * 1024), // 5MB

        // Error handling
        'fail_silently' => env('AI_FILE_FAIL_SILENTLY', true),
        'log_errors' => env('AI_FILE_LOG_ERRORS', true),

        // Search settings
        'default_search_limit' => env('AI_FILE_SEARCH_LIMIT', 10),
        'min_search_score' => env('AI_FILE_MIN_SCORE', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Pattern Library
    |--------------------------------------------------------------------------
    |
    | Reusable, generic query patterns for semantic metadata system.
    | These patterns are domain-agnostic templates that the LLM uses to generate
    | appropriate Cypher queries based on business rules and relationship specs.
    |
    | Patterns are loaded from a separate file (ai-patterns.php) if it exists,
    | otherwise falls back to default patterns or empty array.
    |
    */
    'query_patterns' => file_exists(__DIR__ . '/ai-patterns.php')
        ? require __DIR__ . '/ai-patterns.php'
        : [],

    /*
    |--------------------------------------------------------------------------
    | Entity Configurations
    |--------------------------------------------------------------------------
    |
    | Load entity configurations from separate file for better organization
    |
    */
    'entities' => require __DIR__ . '/entities.php',
];
