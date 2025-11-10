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
    | Documentation Settings
    |--------------------------------------------------------------------------
    */
    'documentation' => [
        'enabled' => env('AI_DOCS_ENABLED', true),
        'route_prefix' => env('AI_DOCS_PREFIX', 'ai-docs'),
        'middleware' => ['web'], // Add 'auth' if you want to protect docs
    ],

    /*
    |--------------------------------------------------------------------------
    | Neo4j Graph Database
    |--------------------------------------------------------------------------
    */
    'neo4j' => [
        'enabled' => env('NEO4J_ENABLED', true),
        'uri' => env('NEO4J_URI', 'bolt://localhost:7687'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'neo4j_password'),
        'database' => env('NEO4J_DATABASE', 'neo4j'), // Default database
    ],

    /*
    |--------------------------------------------------------------------------
    | Qdrant Vector Database
    |--------------------------------------------------------------------------
    */
    'qdrant' => [
        'enabled' => env('QDRANT_ENABLED', true),
        'host' => env('QDRANT_HOST', 'localhost'),
        'port' => env('QDRANT_PORT', 6333),
        'api_key' => env('QDRANT_API_KEY', null), // Optional for cloud instances
        'timeout' => env('QDRANT_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Provider Configuration
    |--------------------------------------------------------------------------
    */
    'llm' => [
        'default_provider' => env('AI_LLM_PROVIDER', 'openai'), // 'openai' or 'anthropic'

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
    'embeddings' => [
        'default_provider' => env('AI_EMBEDDING_PROVIDER', 'openai'), // 'openai' or 'anthropic'

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
        'max_results' => env('AI_MAX_RESULTS', 100),
        'timeout' => env('AI_QUERY_TIMEOUT', 30),
        'cache_ttl' => env('AI_CACHE_TTL', 3600), // Cache results for 1 hour
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
    | Entity Configurations
    |--------------------------------------------------------------------------
    |
    | Load entity configurations from separate file for better organization
    |
    */
    'entities' => require __DIR__ . '/entities.php',
];
