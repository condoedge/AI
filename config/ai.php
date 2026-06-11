<?php

use Condoedge\Ai\Services\SemanticPromptBuilder;

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
    | Project Metadata
    |--------------------------------------------------------------------------
    |
    | High-level information about your project/domain that helps the LLM
    | understand the business context when generating queries and responses.
    |
    */
    'project' => [
        'name' => env('APP_NAME', 'Laravel Application'),
        'description' => env('AI_PROJECT_DESCRIPTION', 'A Laravel application with AI-powered natural language query capabilities.'),
        'domain' => env('AI_PROJECT_DOMAIN', 'general'), // e.g., 'e-commerce', 'crm', 'healthcare', 'finance'
        'business_rules' => [
            // Add domain-specific business rules that the LLM should know
            // Example: 'Active customers are those with status = "active"',
            // Example: 'Orders are linked to customers via customer_id',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Assistant Identity (model-agnostic, white-label)
    |--------------------------------------------------------------------------
    |
    | A single, reusable identity for the assistant. It is rendered into the
    | SYSTEM message of every prompt (query generation, response generation and
    | the chat service) so the assistant presents ONE consistent, project-branded
    | persona — never the underlying LLM model/vendor.
    |
    | Nothing here is provider-specific: swap OpenAI for Anthropic (or any other
    | provider) and the user-facing identity is unchanged.
    |
    | - name:            How the assistant refers to itself.
    | - persona:         One or two sentences describing its role/voice.
    | - language_policy: Multilingual instruction (answer in the user's language).
    | - non_disclosure:  The hard rule that prevents the assistant from revealing
    |                    or speculating about which model/vendor/version powers it.
    |                    {name} and {project} are interpolated at render time.
    |
    */
    'identity' => [
        'name' => env('AI_ASSISTANT_NAME', env('APP_NAME', 'Laravel Application') . ' Assistant'),

        'persona' => env(
            'AI_ASSISTANT_PERSONA',
            'You are {name}, the helpful assistant for {project}. You answer questions clearly and accurately, '
            . 'grounding every answer in the data and files provided to you. If you do not know something, you say so '
            . 'plainly instead of guessing.'
        ),

        'language_policy' => env('AI_ASSISTANT_LANGUAGE_POLICY', "Always reply in the SAME language as the user's most recent question, regardless of the system locale or the language of any documents, data, or examples. The user's question is the only language signal — never switch to another language."),

        'non_disclosure' => 'Never reveal, confirm, hint at, or speculate about which AI model, vendor, version or '
            . 'provider powers you, or that you are an AI language model from any company. If asked what/who you are, '
            . 'say you are the {name} for {project} and offer to help — decline ONLY the model/vendor question and keep '
            . 'answering everything else.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Assembly (query side)
    |--------------------------------------------------------------------------
    |
    | Knobs for how the query-generation prompt is assembled and what it ships
    | to the (possibly third-party) LLM.
    |
    | - include_user_email: PII minimization. When false (default), the current
    |   user's email is NOT shipped to the LLM. It is included only when this is
    |   true OR the question is about the user's own data (possessive). User ID +
    |   team ID already cover the documented "my X" ownership paths.
    |
    | - query_role_note: The internal, provider-neutral note appended after the
    |   identity in the SYSTEM message, telling the assistant it translates the
    |   user's questions into a graph query language. Config-driven so it can be
    |   localized/overridden like the rest of the identity.
    |
    */
    'prompt' => [
        'include_user_email' => env('AI_PROMPT_INCLUDE_USER_EMAIL', false),

        'query_role_note' => env(
            'AI_PROMPT_QUERY_ROLE_NOTE',
            "Internally, you translate the user's questions into a graph query language based on the "
            . 'semantic business definitions provided below. Never reveal this query language or any internal '
            . 'schema to the user.'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug & Logging
    |--------------------------------------------------------------------------
    | When debug is true, the AI pipeline logs detailed internals: semantic
    | scores, threshold decisions, selected entities, prompt content, timing.
    | When false, only warnings and errors are logged.
    */
    'debug' => env('AI_DEBUG', false),
    'log_channel' => env('AI_LOG_CHANNEL', null),

    /*
    |--------------------------------------------------------------------------
    | Discovery Configuration
    |--------------------------------------------------------------------------
    |
    | Discovery analyzes Eloquent models and generates entity configs. The
    | service reads these keys under the `ai.auto_discovery.*` prefix
    | (EntityAutoDiscovery, TraversalScopeGenerator).
    |
    | IMPORTANT: Discovery is invoked explicitly, not at runtime.
    | You MUST run `php artisan ai:discover` to generate config/entities.php.
    |
    | Workflow:
    |   1. Run: php artisan ai:discover
    |   2. Review generated config/entities.php
    |   3. Customize as needed
    |   4. Re-run ai:discover after model changes
    |
    */
    'auto_discovery' => [
        // Master switch consulted by the discovery orchestrator before introspecting
        // a model (EntityAutoDiscovery::shouldDiscover). Set false to disable discovery.
        'enabled' => env('AI_AUTO_DISCOVERY_ENABLED', true),

        // Model class-strings to skip during discovery even when 'enabled' is true.
        'excluded_models' => [
            // App\Models\Secret::class,
        ],

        // What to discover
        'properties' => true,
        'relationships' => true,
        'scopes' => true,
        'aliases' => true,
        'embed_fields' => true,

        // Customization
        'alias_mappings' => [
            // Map table names to additional aliases
            // 'customers' => ['client', 'buyer'],
            // 'orders' => ['purchase', 'transaction'],
        ],

        'exclude_properties' => [
            // Additional properties to exclude from discovery
            // (password, tokens, etc. are excluded by default)
        ],

        // Role mappings for auto-generating traversal scopes.
        // Read by TraversalScopeGenerator::getRoleMappings as
        // ai.auto_discovery.role_mappings.{Entity}.{field}.
        // Maps discriminator field values to scope names.
        'role_mappings' => [
            // Example: PersonTeam role_type mappings
            // 'PersonTeam' => [
            //     'role_type' => [
            //         3 => 'volunteers',
            //         4 => 'scouts',
            //         5 => 'parents',
            //         6 => 'leaders',
            //     ],
            // ],
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
    | Access Control Configuration
    |--------------------------------------------------------------------------
    */
    'access_control' => [
        'default_threshold' => env('AI_ACCESS_DEFAULT_THRESHOLD', 5),
        'thresholds' => [],
        'identifying_fields' => [
            '*' => ['date_of_birth', 'email', 'phone', 'ssn', 'medical_id'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Team-based query filtering for AI. Filters all AI queries by user's
    | permitted teams using Neo4j relationship traversal.
    |
    | Permission chain checks permissions in order; first with teams wins.
    | Global count permission allows seeing total counts across all teams.
    |
    */
    'security' => [
        // Master switch
        'enabled' => env('AI_SECURITY_ENABLED', true),

        'permission_level' => 'teams', // 'teams' or 'roles'

        // Permission chain - checked in order, first with teams wins
        'permission_chain' => [
            '{Entity}.AiRetrieving',   // AI-specific (primary)
            '{Entity}',                // Standard entity (fallback)
        ],

        // Global count permission - allows seeing total counts
        'global_count_permission' => '{Entity}.AiGlobalCount',

        // Default team_path for entities without explicit config
        // 'auto' = detect from relationships
        // 'team_id' = assume direct column
        'default_team_path' => 'auto',

        // What to do when an entity has NO permission metadata, no user can be
        // resolved, or no team filter can be injected for a sensitive entity.
        //   'deny'   = FAIL CLOSED — block the query (raises SecurityException,
        //              caught upstream and turned into a safe error answer).
        //   'bypass' = FAIL OPEN — run the query unfiltered (logs a warning).
        // 'deny' is the safe multi-tenant default. 'bypass' is the rollout escape
        // hatch for environments that have not yet configured permission metadata.
        'on_permission_unexistent' => env('AI_ON_PERMISSION_UNEXISTENT', 'deny'), // 'deny' or 'bypass'

        // What to do when no team_path found
        // 'deny' = block query
        // 'bypass' = allow without filtering
        'on_missing_team_path' => 'deny',

        // Response scope clarification
        'clarify_scope' => false,
        'scope_prefix' => 'Based on your permissions, ',

        // Logging
        'log_denied_access' => true,
        'log_missing_team_path' => true,
        'log_channel' => env('AI_SECURITY_LOG_CHANNEL', 'ai-security'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Triggers Configuration
    |--------------------------------------------------------------------------
    */
    'sync_triggers' => [],

    /*
    |--------------------------------------------------------------------------
    | Model Namespaces
    |--------------------------------------------------------------------------
    */
    'model_namespaces' => [
        'App\Models',
    ],

    /*
    |--------------------------------------------------------------------------
    | Graph Database Configuration
    |--------------------------------------------------------------------------
    |
    | Neo4j connection with automatic driver selection based on URI scheme:
    |
    | Bolt Protocol (recommended for production):
    |   - bolt://localhost:7687      - Unencrypted Bolt
    |   - neo4j://localhost:7687     - Bolt with auto-routing
    |   - neo4j+s://host:7687        - Bolt with TLS
    |   - neo4j+ssc://host:7687      - Bolt with self-signed cert
    |
    | HTTP Protocol (legacy, development only):
    |   - http://localhost:7474      - HTTP API
    |   - https://host:7474          - HTTPS API
    |
    | Security: Encrypted Passwords
    |
    | For enhanced security, use encrypted passwords:
    |
    | 1. Generate encrypted password:
    |    php artisan tinker
    |    >>> encrypt('your_neo4j_password')
    |
    | 2. Set in .env:
    |    NEO4J_PASSWORD_ENCRYPTED=eyJpdiI6Ii4uLg==
    |
    | 3. The system will automatically decrypt on-demand.
    |    If both password and password_encrypted are set,
    |    password_encrypted takes precedence.
    |
    */
    'graph' => [
        'default' => env('AI_GRAPH_STORE', 'neo4j'),

        'neo4j' => [
            'enabled' => env('NEO4J_ENABLED', true),
            // URI determines driver: bolt/neo4j schemes use Bolt, http/https use HTTP API
            'uri' => env('NEO4J_URI', 'bolt://localhost:7687'),
            'username' => env('NEO4J_USERNAME', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', 'neo4j_password'),
            'password_encrypted' => env('NEO4J_PASSWORD_ENCRYPTED'), // Preferred: encrypted password
            'database' => env('NEO4J_DATABASE', 'neo4j'),
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
    | LLM Role Configuration
    |--------------------------------------------------------------------------
    |
    | Override the default LLM for specific pipeline stages.
    | Only set fields you want to override; the rest inherit from the base provider.
    |
    | Available roles:
    | - query_understanding: Pre-processes questions (fast model recommended)
    | - query_generation: Generates Cypher queries (needs reasoning power)
    | - response_generation: Generates natural language answers (fast model OK)
    | - eval_judge: Grades answers in `ai:eval` (use a strong, cheap judge at temp 0)
    | - eval_generator: Phrases eval questions in `ai:eval` (any fast model)
    |
    */
    'llm_roles' => [
        // 'query_understanding' => ['model' => 'gpt-4o-mini', 'temperature' => 0.0],
        // 'query_generation' => null,  // uses default
        // 'response_generation' => ['model' => 'gpt-4o-mini'],
        // 'eval_judge' => ['model' => 'gpt-4o', 'temperature' => 0.0],     // LLM-as-judge for ai:eval
        // 'eval_generator' => ['model' => 'gpt-4o-mini', 'temperature' => 0.4], // question phrasing for ai:eval
    ],

    /*
    |--------------------------------------------------------------------------
    | Eval Harness (ai:eval)
    |--------------------------------------------------------------------------
    |
    | Configuration for the closed-Q&A eval harness with per-stage failure
    | attribution. The harness generates label-free questions from this config,
    | drives them through the real pipeline, grades answers with a reference-free
    | LLM judge, and attributes failure to one weakest stage.
    |
    | - stage_thresholds: minimum average score (0-1) each stage must meet for
    |                     the run to PASS. Lower a threshold to relax it; the run
    |                     exits non-zero if any stage average falls below its min.
    | - row_pass_threshold: a single row's weakest-stage score below this marks
    |                       the row 'failed' in the report.
    | - judge: which LLM role + sampling the LLM-as-judge uses (temp 0 = stable).
    | - report_path: where the JSON report is written (null = only print).
    |
    */
    'eval' => [
        'stage_thresholds' => [
            'understanding' => env('AI_EVAL_THRESHOLD_UNDERSTANDING', 0.6),
            'retrieval' => env('AI_EVAL_THRESHOLD_RETRIEVAL', 0.6),
            'prompt' => env('AI_EVAL_THRESHOLD_PROMPT', 0.6),
            'generation' => env('AI_EVAL_THRESHOLD_GENERATION', 0.6),
        ],

        'row_pass_threshold' => env('AI_EVAL_ROW_PASS_THRESHOLD', 0.6),

        'judge' => [
            'role' => 'eval_judge',
            'temperature' => env('AI_EVAL_JUDGE_TEMPERATURE', 0.0),
            'max_tokens' => env('AI_EVAL_JUDGE_MAX_TOKENS', 800),
        ],

        'report_path' => env('AI_EVAL_REPORT_PATH', storage_path('app/ai-eval-report.json')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Provider Configuration
    |--------------------------------------------------------------------------
    */
    'embedding' => [
        'default' => env('AI_EMBEDDING_PROVIDER', 'openai'), // 'openai' or 'anthropic'

        // Override to force a specific dimension. If null, uses the active provider's dimensions.
        'vector_size' => env('AI_EMBEDDING_VECTOR_SIZE', null),

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
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rate limits to prevent DoS attacks via expensive query flooding.
    | These limits apply to query execution and LLM API calls.
    |
    */
    'rate_limits' => [
        'queries_per_minute' => env('AI_QUERIES_PER_MINUTE', 30),
        'llm_requests_per_minute' => env('AI_LLM_REQUESTS_PER_MINUTE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resilience Configuration
    |--------------------------------------------------------------------------
    |
    | Circuit breaker + retry tuning for external LLM calls. The providers wrap
    | every request in a circuit breaker named 'llm' and retry ONLY transient
    | HTTP failures (429 / 5xx / network errors); permanent errors fail fast.
    |
    | NOTE: cross-process breaker state requires a shared, atomic cache driver
    | (redis/memcached). On array/file drivers it degrades to per-process.
    |
    */
    'resilience' => [
        'llm' => [
            'circuit_breaker' => [
                'failure_threshold' => env('AI_LLM_CB_FAILURE_THRESHOLD', 5),
                'recovery_timeout_seconds' => env('AI_LLM_CB_RECOVERY_TIMEOUT', 30),
                'success_threshold' => env('AI_LLM_CB_SUCCESS_THRESHOLD', 2),
            ],
            'retry' => [
                'max_attempts' => env('AI_LLM_RETRY_MAX_ATTEMPTS', 3),
                'base_delay_ms' => env('AI_LLM_RETRY_BASE_DELAY_MS', 500),
                'max_delay_ms' => env('AI_LLM_RETRY_MAX_DELAY_MS', 10000),
                'jitter' => env('AI_LLM_RETRY_JITTER', 0.3),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Generation Settings
    |--------------------------------------------------------------------------
    |
    | Controls how the AI generates natural language responses.
    |
    | Available styles:
    | - 'minimal': Just the answer, nothing else (e.g., "Admin System" or "42")
    | - 'concise': One sentence answer (e.g., "The next birthday is Admin System on Nov 29.")
    | - 'friendly': Natural conversational style, 2-3 sentences max (RECOMMENDED)
    | - 'detailed': Full explanation with context
    | - 'technical': Includes query details for debugging
    |
    | The hide_* options control what technical details are excluded from responses.
    | These apply to 'detailed' style. 'minimal', 'concise', and 'friendly' styles
    | automatically hide all technical details.
    |
    */
    'response_generation' => [
        'default_format' => env('AI_RESPONSE_FORMAT', 'text'), // text, markdown, json
        'default_style' => env('AI_RESPONSE_STYLE', 'friendly'), // minimal, concise, friendly, detailed, technical
        'default_max_length' => env('AI_RESPONSE_MAX_LENGTH', 500), // words (auto-adjusted by style)
        'temperature' => env('AI_RESPONSE_TEMPERATURE', 0.3),
        'include_insights' => env('AI_RESPONSE_INSIGHTS', true),
        'include_visualizations' => env('AI_RESPONSE_VIZ', true),
        'summarize_threshold' => env('AI_RESPONSE_SUMMARIZE_THRESHOLD', 10), // rows

        // Hide technical details from responses (affects 'detailed' style)
        'hide_technical_details' => env('AI_RESPONSE_HIDE_TECHNICAL', true),
        'hide_execution_stats' => env('AI_RESPONSE_HIDE_STATS', true),
        'hide_project_info' => env('AI_RESPONSE_HIDE_PROJECT', true),
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

        // Second-stage re-ranking of retrieved candidates for precision. Applies to
        // BOTH file search and the graph/RAG path (similar-query examples). When true,
        // an over-fetched pool is re-scored by a model-agnostic LLM reranker (via the
        // configured LLM provider) before the final limit is applied; when false, a
        // NullReranker passes candidates through unchanged (no cost, no extra fetch).
        'rerank_enabled' => env('AI_RAG_RERANK_ENABLED', false),

        // Hybrid retrieval: fuse a sparse/lexical signal (BM25-style token overlap over
        // entity names/aliases/scope terminology) with the dense semantic ranking using
        // Reciprocal Rank Fusion. Lifts recall on exact name/alias/acronym/ID queries the
        // embedding misses. When false, semantic selection uses the dense ranking only.
        'hybrid_enabled' => env('AI_RAG_HYBRID_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Relationship Importance Weights
    |--------------------------------------------------------------------------
    |
    | Configurable weights for different relationship types to prioritize
    | more important relationships (like PURCHASED) over less important ones
    | (like VIEWED) when retrieving context for queries.
    |
    | Weights should be between 0.0 and 1.0, where:
    | - 1.0 = highest importance (direct business relationships)
    | - 0.5 = medium importance (secondary relationships)
    | - 0.0 = lowest importance (can be filtered out)
    |
    */
    'relationship_weights' => [
        // High importance - direct business relationships
        'PURCHASED' => 1.0,
        'BELONGS_TO' => 0.9,
        'MEMBER_OF' => 0.9,
        'CREATED_BY' => 0.8,
        'OWNS' => 0.8,

        // Medium importance - secondary relationships
        'RELATED_TO' => 0.6,
        'TAGGED_WITH' => 0.5,
        'CATEGORIZED_AS' => 0.5,

        // Low importance - metadata relationships
        'VIEWED' => 0.3,
        'LOGGED' => 0.2,

        // Default for unspecified relationships
        'default' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic Matching Configuration
    |--------------------------------------------------------------------------
    |
    | Semantic matching uses vector embeddings for fuzzy/semantic text matching
    | instead of hardcoded string comparisons. This enables:
    | - Entity detection: "clients" → Customer entity
    | - Scope detection: "volunteers" → volunteer scope
    | - Template matching: "display all" → list_all template
    |
    | Features:
    | - Handles synonyms and variations automatically
    | - Configurable similarity thresholds
    | - Falls back to exact matching if disabled
    |
    | Workflow:
    |   1. Enable: AI_SEMANTIC_MATCHING=true
    |   2. Index: php artisan ai:index-semantic --rebuild
    |
    */
    'semantic_matching' => [
        // Enable semantic matching (falls back to exact matching if false)
        'enabled' => env('AI_SEMANTIC_MATCHING', true),

        // Fallback to exact matching if semantic matching fails
        'fallback_to_exact' => env('AI_FALLBACK_EXACT_MATCH', true),

        // Similarity thresholds (0.0 - 1.0)
        // Higher = more precise, Lower = more recall
        'thresholds' => [
            'entity_detection' => (float) env('AI_SEMANTIC_THRESHOLD_ENTITY', 0.75),
            'scope_detection' => (float) env('AI_SEMANTIC_THRESHOLD_SCOPE', 0.70),
            'template_detection' => (float) env('AI_SEMANTIC_THRESHOLD_TEMPLATE', 0.65),
            'label_inference' => (float) env('AI_SEMANTIC_THRESHOLD_LABEL', 0.70),
        ],

        // Vector store collections for semantic indexes
        'collections' => [
            'entities' => 'semantic_entities',
            'scopes' => 'semantic_scopes',
            'templates' => 'semantic_templates',
        ],

        // Cache embeddings in memory to avoid redundant API calls
        'cache_embeddings' => env('AI_SEMANTIC_CACHE_EMBEDDINGS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic Context Selection
    |--------------------------------------------------------------------------
    |
    | Intelligent context selection uses vector similarity to determine which
    | entities, relationships, and schema information are relevant to a question.
    | This significantly reduces token consumption by only including relevant
    | context instead of all available metadata.
    |
    | Benefits:
    | - Reduced token usage (up to 80% reduction)
    | - Faster LLM responses
    | - More focused context for better query generation
    |
    | Workflow:
    |   1. Run: php artisan ai:index-context
    |   2. System will automatically use semantic context selection
    |
    */
    'semantic_context' => [
        // Enable semantic context selection (falls back to full context if false)
        'enabled' => env('AI_SEMANTIC_CONTEXT_ENABLED', true),

        // Vector store collection for context index
        'collection' => env('AI_SEMANTIC_CONTEXT_COLLECTION', 'context_index'),

        // Similarity threshold for context selection (0.0 - 1.0)
        // Higher = more precise, Lower = more recall
        'threshold' => (float) env('AI_SEMANTIC_CONTEXT_THRESHOLD', 0.45),

        // Maximum number of context items to retrieve
        'top_k' => (int) env('AI_SEMANTIC_CONTEXT_TOP_K', 10),

        // Vector dimensions (should match embedding provider)
        'dimension' => (int) env('AI_SEMANTIC_CONTEXT_DIMENSION', 1536),

        // Score assigned to the conversation's focused entity when force-included
        // in follow-up queries. Lower = less dominant when user switches topics.
        'conversation_focus_score' => (float) env('AI_SEMANTIC_CONTEXT_CONVERSATION_FOCUS_SCORE', 0.55),

        // Score assigned to the page-context entity type (which page/record the chat was opened
        // from) when soft-included. Soft prior, never a hard filter — same default as the
        // conversation focus so it ranks the current record without dominating other topics.
        'page_context_focus_score' => (float) env('AI_SEMANTIC_CONTEXT_PAGE_CONTEXT_FOCUS_SCORE', 0.55),

        // Score assigned to entities injected by Query Understanding.
        // Should be high enough to ensure they're included in context.
        'qu_entity_score' => (float) env('AI_SEMANTIC_CONTEXT_QU_ENTITY_SCORE', 0.8),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Entity Paths
    |--------------------------------------------------------------------------
    |
    | Defines how the authenticated user relates to different entities.
    | When users ask about "my X" or "I", these paths tell the LLM how to
    | traverse the graph from the current user to the target entity.
    |
    | Each path defines:
    | - description: Human-readable explanation
    | - cypher: The MATCH pattern to find the user's entities
    | - entities: Labels involved in the path (auto-included in context)
    | - relationships: Relationship types involved (auto-included in context)
    |
    | Example:
    |   'Unit' => [
    |       'description' => 'User units via Contact and ContactUnit',
    |       'cypher' => 'MATCH (c:Contact {user_id: $userId})-[:CONTACT_UNITS]->(cu:ContactUnit)-[:UNIT]->(n:Unit) WHERE cu.sold_at IS NULL',
    |       'entities' => ['Contact', 'ContactUnit', 'Unit'],
    |       'relationships' => ['CONTACT_UNITS', 'UNIT'],
    |   ],
    |
    */
    'user_entity_paths' => [
        // Override in your app's config/ai.php to define paths
        // See CondoEdge config for examples
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Understanding
    |--------------------------------------------------------------------------
    |
    | Pre-processes natural language questions before semantic selection to
    | improve entity matching. Uses a fast LLM call to identify entity types,
    | person names, property concepts, and rewrite the query for better
    | semantic matching.
    |
    | This layer runs BEFORE semantic context selection and helps resolve
    | ambiguous references like person names, role-based terms, and
    | domain-specific vocabulary that embedding search alone may miss.
    |
    */
    'query_understanding' => [
        // Enable query understanding pre-processing. When enabled, QU runs on every
        // question (it is STEP 1, before semantic selection produces any score), so
        // this on/off switch is the gate. Disable it to skip the extra LLM round-trip.
        'enabled' => env('AI_QUERY_UNDERSTANDING_ENABLED', true),

        // Maximum tokens for the QU LLM response
        'max_tokens' => (int) env('AI_QUERY_UNDERSTANDING_MAX_TOKENS', 500),

        // Temperature for QU LLM call (0.0 = deterministic)
        'temperature' => (float) env('AI_QUERY_UNDERSTANDING_TEMPERATURE', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scope Semantic Matching
    |--------------------------------------------------------------------------
    |
    | Semantic matching for scope detection uses vector embeddings to match
    | natural language phrases to registered scopes.
    |
    | Example: "show me volunteers" → matches volunteers scope
    |
    | Workflow:
    |   1. Run: php artisan ai:index-scopes
    |   2. System will automatically use semantic scope matching
    |
    */
    'scope_matching' => [
        // Vector store collection for scope examples
        'collection' => env('AI_SCOPE_MATCHING_COLLECTION', 'scope_examples'),

        // Similarity threshold for scope matching (0.0 - 1.0)
        'threshold' => (float) env('AI_SCOPE_MATCHING_THRESHOLD', 0.70),

        // Maximum number of scopes to retrieve
        'top_k' => (int) env('AI_SCOPE_MATCHING_TOP_K', 5),
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
    | File Context Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how files are used as context for AI responses.
    | Supports two modes: physical files (docs) and database files.
    |
    */
    'file_context' => [
        // Enable file context in AI responses
        'enabled' => env('AI_FILE_CONTEXT_ENABLED', true),

        // Security mode for database files (can be overridden via boot method)
        'security_enabled' => env('AI_FILE_SECURITY_ENABLED', true),

        // Physical file paths (glob patterns) - no security, you define what's available
        // These are indexed via `ai:ingest --docs`
        'physical_paths' => [
            // 'docs/**/*.mdx',
            // 'resources/docs/**/*.md',
        ],

        // Supported extensions for physical files
        'supported_extensions' => ['md', 'mdx', 'txt', 'rst'],

        // Base path for physical files (relative to project root)
        'base_path' => env('AI_DOCS_BASE_PATH', base_path()),

        // Collection name for physical file chunks in Qdrant.
        // `ai:ingest --docs` writes physical/documentation docs here (routed via
        // the FileProcessor `collection` option), keeping them isolated from the
        // database file_chunks collection.
        'physical_collection' => 'documentation_chunks',

        // Additional Qdrant collections that file *retrieval* should union into
        // its results, in addition to the primary file_processing.collection.
        // Set to null to use the default (search the physical_collection above so
        // local docs are retrievable). Set to an explicit array to override
        // (the primary collection is always searched and auto-excluded here).
        'search_collections' => null,

        // Maximum file references to include in response
        'max_references' => 5,

        // Minimum relevance score for file inclusion.
        // Tunable per corpus: French legalese can score low for an English query,
        // so lower it (e.g. 0.3) when the right document is retrieved but cut.
        'min_relevance_score' => env('AI_FILE_MIN_RELEVANCE', 0.4),

        // Recall safety net. When NO document clears min_relevance_score across every
        // retrieval angle, the pipeline retries once at this lower floor and grounds on
        // the strongest borderline document(s) instead of giving a generic, ungrounded
        // answer. It only fires when retrieval would otherwise be empty, so it cannot
        // dilute a confident match; the response layer still grounds or abstains.
        // Set recall_floor >= min_relevance_score (or to 0) to disable.
        'recall_floor' => env('AI_FILE_RECALL_FLOOR', 0.3),

        // Max borderline documents the recall net may admit (per angle and overall).
        'recall_limit' => env('AI_FILE_RECALL_LIMIT', 2),

        // Corpus languages for cross-lingual retrieval. When the indexed documents
        // are in language(s) other than the question, set the corpus language codes
        // here (e.g. ['fr', 'en']). The Query-Understanding layer then translates
        // the question into each — already in its single LLM call — and each
        // translation is added as a separate file-search fan-out angle so a
        // question in one language still retrieves documents written in another.
        // Empty (default) = monolingual behaviour, zero added cost.
        'corpus_languages' => [],

        // Include file snippets in response metadata
        'include_snippets' => true,

        // Maximum snippet length (characters). Raised from a single ~200-char
        // slice so the model can quote the actual clause it is grounding on.
        'snippet_length' => env('AI_FILE_SNIPPET_LENGTH', 1200),

        // How many of the best file's top chunks to stitch into one snippet
        // (within snippet_length). 1 keeps the legacy single-chunk behaviour;
        // higher values give the model more of the surrounding clause.
        'snippet_chunks' => env('AI_FILE_SNIPPET_CHUNKS', 3),

        // Context window (small-to-big / sentence-window retrieval). When the best
        // chunk of a file is chosen, also pull in its INDEX-ADJACENT neighbors
        // (+/-N by chunk_index from the same file/collection) — even neighbors the
        // vector search did not independently return — stitched in reading order so
        // the model reads the full passage (e.g. an article's rule plus the next
        // article's compensation clause), not a lone fragment. The window clamps to
        // the file's real index range, so a hit at the start/end or a single-chunk
        // file is a safe no-op. 0 disables expansion (behaviour identical to the
        // legacy single-chunk retrieval).
        'context_window' => env('AI_FILE_CONTEXT_WINDOW', 2),

        // File enumeration ("which files do I have access to?"). When a question is
        // classified by Query Understanding as a file-listing intent, list the
        // user's accessible files (ACL-respecting) instead of semantic snippets.
        'enumeration' => [
            // Enable/disable the file-listing capability.
            'enabled' => env('AI_FILE_ENUMERATION_ENABLED', true),

            // QU intent values that trigger enumeration. Model-agnostic: matched
            // against the QU result's `intent` field, NOT a hardcoded phrase.
            'intents' => ['file_listing'],

            // Maximum files listed in an enumeration answer.
            'max_files' => env('AI_FILE_ENUMERATION_MAX', 50),
        ],

        /*
        |--------------------------------------------------------------------------
        | Database File Access Configuration
        |--------------------------------------------------------------------------
        |
        | Configure how database files are accessed and filtered.
        | Single entry point: specify your File model and the scope to use.
        |
        */

        // The Eloquent File model class (your app's File model)
        'file_model' => env('AI_FILE_MODEL', 'App\\Models\\File'),

        // The scope method to call for user-accessible files
        // Will be called as: File::accessibleBy($user)->pluck('id')
        // Set to null to allow all files (when security_enabled is true but no scope)
        'access_scope' => 'accessibleBy',

        // Fallback filtering when accessibleBy scope is not available
        // These are used when the configured scope fails or doesn't exist
        'fallback_filters' => [
            // Always filter by user_id when security is enabled
            'use_user_filter' => env('AI_FILE_USE_USER_FILTER', true),

            // Also filter by team_id using safeCurrentTeamId()
            'use_team_filter' => env('AI_FILE_USE_TEAM_FILTER', true),
        ],

        // Alternative: closure-based resolver (takes precedence over scope)
        // Set in boot method: config(['ai.file_context.access_resolver' => fn($user) => [...]])
        'access_resolver' => null,

        // Log file access attempts for security auditing
        // Creates audit trail in ai_file_access_logs table
        'log_access' => env('AI_FILE_ACCESS_LOG', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat UI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the AI Chat UI components (AiChatPanel, AiChatModal,
    | AiChatDrawer, AiChatFloating).
    |
    */
    'chat' => [
        // Service class for chat functionality
        'service' => env('AI_CHAT_SERVICE', \Condoedge\Ai\Services\Chat\AiChatService::class),

        // Surface the floating button opens: 'modal' | 'drawer' | 'screen'.
        // - modal  => AiChatModal in a modal overlay (default, today's behaviour)
        // - drawer => AiChatDrawer in a slide-over
        // - screen => navigates to the routed AiChatScreen full page (ai.chat.screen)
        'surface' => env('AI_CHAT_SURFACE', 'modal'),

        // Welcome screen
        'welcome' => [
            'title' => env('AI_CHAT_WELCOME_TITLE', 'AI Assistant'),
            'message' => env('AI_CHAT_WELCOME_MESSAGE', 'Ask me anything about your data. I can help you find information, generate reports, and answer questions.'),
        ],

        // Example questions shown on welcome screen
        'example_questions' => [
            'How many records do we have?',
            'Show me recent activity',
            'What are the top items?',
            'Give me a summary',
        ],

        // Per-entity-type welcome examples for page-context awareness. When the chat is opened
        // from a page whose page_context.entity_type matches a key here, these suggestions
        // replace the global example_questions on the welcome screen (config-only, no LLM call).
        // Falls back to example_questions above when the type is absent.
        // e.g. 'Unit' => ['Show this unit\'s payment history', 'Who are the owners of this unit?'],
        'page_examples' => [
        ],

        // Conversation persistence
        'persist_history' => env('AI_CHAT_PERSIST_HISTORY', true),
        'max_messages' => env('AI_CHAT_MAX_MESSAGES', 50),
        'session_key_prefix' => env('AI_CHAT_SESSION_PREFIX', 'ai_chat_history'),

        // Conversation history for LLM context
        'max_history_messages' => env('AI_CHAT_MAX_HISTORY_MESSAGES', 10), // Messages to send to LLM
        'system_prompt' => env('AI_CHAT_SYSTEM_PROMPT', null), // Custom system prompt (null = auto-generate)

        // UI Features
        'show_timestamps' => env('AI_CHAT_SHOW_TIMESTAMPS', false),
        'show_avatars' => env('AI_CHAT_SHOW_AVATARS', true),
        'show_typing_indicator' => env('AI_CHAT_SHOW_TYPING', true),
        'show_suggestions' => env('AI_CHAT_SHOW_SUGGESTIONS', true),
        'max_suggestions' => env('AI_CHAT_MAX_SUGGESTIONS', 3),
        'show_metrics' => env('AI_CHAT_SHOW_METRICS', false),

        // Input configuration
        'input_placeholder' => env('AI_CHAT_INPUT_PLACEHOLDER', 'ai.chat.input-placeholder'),
        'auto_focus' => env('AI_CHAT_AUTO_FOCUS', true),

        // Features
        'enable_copy' => env('AI_CHAT_ENABLE_COPY', true),
        'enable_feedback' => env('AI_CHAT_ENABLE_FEEDBACK', false),
        'enable_regenerate' => env('AI_CHAT_ENABLE_REGENERATE', true),
        'enable_edit' => env('AI_CHAT_ENABLE_EDIT', true),
        'enable_markdown' => env('AI_CHAT_ENABLE_MARKDOWN', true),
        'enable_code_highlight' => env('AI_CHAT_ENABLE_CODE_HIGHLIGHT', true),
        'response_style' => env('AI_CHAT_RESPONSE_STYLE', 'friendly'),

        // Floating button defaults
        'floating' => [
            'position' => env('AI_CHAT_FLOATING_POSITION', 'bottom-right'),
            'offset_x' => env('AI_CHAT_FLOATING_OFFSET_X', 24),
            'offset_y' => env('AI_CHAT_FLOATING_OFFSET_Y', 24),
            'size' => env('AI_CHAT_FLOATING_SIZE', 'lg'),
            'style' => env('AI_CHAT_FLOATING_STYLE', 'gradient'),
        ],

        // Modal defaults
        'modal' => [
            'size' => env('AI_CHAT_MODAL_SIZE', 'xl'),
            'full_screen' => env('AI_CHAT_MODAL_FULLSCREEN', false),
        ],

        // Drawer defaults
        'drawer' => [
            'position' => env('AI_CHAT_DRAWER_POSITION', 'right'),
            'width' => env('AI_CHAT_DRAWER_WIDTH', '400px'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Theming Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the visual theme for AI chat components.
    |
    | Built-in themes: 'indigo' (default), 'green', 'config'
    |
    | Factory options:
    |   - ConfigChatThemeFactory: reads from config only (default)
    |   - UserChatThemeFactory: reads from user database settings first
    |
    */
    'ui' => [
        // Factory class to use for theme resolution
        // Options: ConfigChatThemeFactory::class, UserChatThemeFactory::class
        'factory' => env('AI_UI_FACTORY', \Condoedge\Ai\Services\UI\UserChatThemeFactory::class),

        // Default theme (used when factory is ConfigChatThemeFactory or user has no preference)
        'theme' => env('AI_UI_THEME', 'green'),

        // Color overrides (optional)
        'colors' => [
            // 'primary_gradient' => 'from-indigo-600 to-purple-600',
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Entity ID Field Names
    |--------------------------------------------------------------------------
    |
    | Field names to check when extracting entity IDs from query results.
    | Checked in order; first match wins. Add custom fields for your entities.
    |
    */
    'entity_id_fields' => [
        'id',
        '_id',
        'neo4j_id',
        'uuid',
    ],

    /*
    |--------------------------------------------------------------------------
    | Agentic Actions
    |--------------------------------------------------------------------------
    |
    | Register host actions the SAME way you register a condoedge/utils Plugin:
    | list each AiAction class-string in this flat array. On boot the registry
    | instantiates every class-string (new $class()) beside the built-ins — no
    | service-provider code, no core edit. A class implements AiActionInterface
    | (or extends a base that does); mutating actions return mutates()===true and
    | get the signed-token approval gate for free.
    |
    |   'actions' => [
    |       \App\Ai\Actions\CreateEventAction::class,
    |       \App\Ai\Actions\ExportRollCallAction::class,
    |       'token_ttl' => env('AI_ACTION_TOKEN_TTL', 900),
    |   ],
    |
    | The reserved 'token_ttl' key tunes the deferred-action approval flow and
    | coexists in this same array (it is ignored as a non-class entry). A
    | state-mutating action is never run inline: it is deferred behind a signed
    | PendingActionToken the user confirms. 'token_ttl' is the max age (seconds)
    | such a token is accepted for — past it, decode() rejects the token so a
    | captured token cannot be replayed long after issue. Single-use is enforced
    | separately (the token's nonce is consumed on confirm), so a token also
    | cannot fire a non-idempotent action twice.
    |
    | Native tool-calling is tuned by three more reserved (non-class) keys:
    |
    |   'native_tools'   (default false) — the kill switch. When true AND the
    |                    response_generation provider supports tool calling,
    |                    registered actions are offered to the model as native
    |                    provider tools instead of the markdown link grammar.
    |                    Mutating actions stay behind the SAME signed-token
    |                    approval gate on both channels.
    |   'tool_rounds'    (default 2) — max tool-enabled LLM rounds per turn;
    |                    on exhaustion one forced no-tools call closes the prose.
    |   'max_tool_calls' (default 5) — max action dispatches per turn.
    |
    */
    'actions' => [
        // \App\Ai\Actions\CreateEventAction::class,
        'token_ttl' => env('AI_ACTION_TOKEN_TTL', 900),
        'native_tools' => (bool) env('AI_ACTION_NATIVE_TOOLS', false),
        'tool_rounds' => (int) env('AI_ACTION_TOOL_ROUNDS', 2),
        'max_tool_calls' => (int) env('AI_ACTION_MAX_TOOL_CALLS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Actions
    |--------------------------------------------------------------------------
    |
    | Define actions for specific entity types. Each entity can have multiple
    | actions, each with aliases for natural language matching.
    |
    | Structure:
    | 'EntityType' => [
    |     'action_key' => [
    |         'action' => fn($id, $text = 'Default Text') => KompoElement,
    |         'aliases' => ['alias1', 'alias2'],
    |         'label' => 'Display Label', // optional, for AI context
    |     ],
    | ],
    |
    | AI uses format: [text](entity://EntityType/id/action_key)
    | The $text parameter receives the link text from markdown (e.g., "text" above).
    |
    */
    'entity_actions' => [
        // Example (applications should define their own):
        // 'Person' => [
        //     'profile' => [
        //         'action' => fn($id, $text = 'View Profile') => _Link($text)->href(route('people.show', $id)),
        //         'aliases' => ['profile link', 'profile page', 'profile', 'view person'],
        //         'label' => 'View Profile',
        //     ],
        //     'quick_view' => [
        //         'action' => fn($id, $text = 'Quick View') => _Link($text)->selfGet('personModal', ['id' => $id])->inModal(),
        //         'aliases' => ['quick view', 'preview', 'details'],
        //         'label' => 'Quick View',
        //     ],
        // ],
        //
        // An entry may ALSO be a ready-made AiActionInterface instance. The approval-gated write
        // pattern is ModelMethodMutationAction: it wraps an EXISTING domain method (the state
        // transition your UI already calls) so the assistant can offer it WITHOUT a raw write. It
        // mutates() => the dispatcher defers it behind a signed approval card and re-runs
        // authorize() server-side before executing. The entity id rides the approval token, so
        // authorize() re-resolves the exact row:
        // 'Reservation' => [
        //     'approve_reservation' => new \Condoedge\Ai\Services\Actions\ModelMethodMutationAction(
        //         key: 'approve_reservation',
        //         label: 'Approve reservation',
        //         finder: \App\Models\Reservation::class,            // resolved via ::find($id)
        //         mutator: fn ($reservation, array $input) => $reservation->approve(), // existing method
        //         authorizer: fn ($user, $reservation, array $input) => $user?->can('update', $reservation),
        //         aliases: ['approve reservation', 'confirm booking'],
        //         description: 'Approve a pending reservation.',
        //         entityType: 'Reservation',
        //     ),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Generic Actions
    |--------------------------------------------------------------------------
    |
    | Define actions not tied to specific entities. These are app-wide
    | navigation or functionality links.
    |
    | Structure:
    | 'action_key' => [
    |     'action' => fn($text = 'Default Text') => KompoElement,
    |     'aliases' => ['alias1', 'alias2'],
    |     'label' => 'Display Label',
    | ],
    |
    | AI uses format: [text](action://action_key)
    | The $text parameter receives the link text from markdown (e.g., "text" above).
    |
    */
    'generic_actions' => [
        // Example:
        // 'settings' => [
        //     'action' => fn($text = 'Settings') => _Link($text)->href(route('settings.index')),
        //     'aliases' => ['settings', 'settings page', 'preferences'],
        //     'label' => 'Settings',
        // ],
        // 'dashboard' => [
        //     'action' => fn($text = 'Dashboard') => _Link($text)->href(route('dashboard')),
        //     'aliases' => ['dashboard', 'home', 'main page'],
        //     'label' => 'Dashboard',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain Calculations (canonical formulas)
    |--------------------------------------------------------------------------
    |
    | Named metrics the host's business defines, with their EXACT aggregation —
    | rendered into the query-generation prompt (CalculationsSection) so metric
    | questions reuse the approved formula instead of inventing one.
    |
    |   'calculations' => [
    |       'team_total_owed' => [
    |           'label'       => 'Total owed to the team (receivables outstanding)',
    |           'description' => 'What customers still owe.',
    |           'formula'     => 'SUM of Invoice.calc_due_amount where calc_due_amount > 0',
    |           'cypher'      => 'MATCH (i:Invoice) WHERE i.calc_due_amount > 0 RETURN sum(i.calc_due_amount) AS total_owed',
    |           'notes'       => 'Split overdue vs not-yet-due on i.due_at < date().',
    |       ],
    |   ],
    */
    'calculations' => [],

    /*
    |--------------------------------------------------------------------------
    | Guardrails
    |--------------------------------------------------------------------------
    |
    | domain_scope: one host-specific sentence describing what IS in this
    | software's domain. Appended to the out-of-domain guardrail so the
    | assistant can name its scope when declining unrelated questions.
    | Null/empty keeps the generic project-name-based decline.
    |
    */
    'guardrails' => [
        'domain_scope' => env('AI_DOMAIN_SCOPE'),
    ],

    'query_generator_sections' => [
        \Condoedge\Ai\Services\PromptSections\ProjectContextSection::class,
        \Condoedge\Ai\Services\PromptSections\GenericContextSection::class,
        \Condoedge\Ai\Services\PromptSections\PageContextSection::class, // Priority 16: page/record the chat was opened from (zero tokens when absent), before CurrentUserContext (17)
        \Condoedge\Ai\Services\PromptSections\CurrentUserContextSection::class,
        \Condoedge\Ai\Services\PromptSections\NameResolutionSection::class,
        \Condoedge\Ai\Services\PromptSections\SchemaSection::class,
        \Condoedge\Ai\Services\PromptSections\RelationshipsSection::class,
        \Condoedge\Ai\Services\PromptSections\ExampleEntitiesSection::class,
        \Condoedge\Ai\Services\PromptSections\FileContextSection::class,
        \Condoedge\Ai\Services\PromptSections\SimilarQueriesSection::class,
        \Condoedge\Ai\Services\PromptSections\ConversationContextSection::class, // Priority 55: After SimilarQueries (50), before DetectedEntities (60)
        \Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection::class, // Priority 58: Teaches AI that "profile link" etc. are actions, not DB fields
        \Condoedge\Ai\Services\PromptSections\DetectedEntitiesSection::class,
        \Condoedge\Ai\Services\PromptSections\DetectedScopesSection::class,
        fn(SemanticPromptBuilder $promptBuilder) => new \Condoedge\Ai\Services\PromptSections\PatternLibrarySection($promptBuilder->getPatternLibrary()),
        \Condoedge\Ai\Services\PromptSections\CalculationsSection::class, // Priority 74: canonical domain formulas (config 'ai.calculations'), zero tokens when none configured
        \Condoedge\Ai\Services\PromptSections\QueryRulesSection::class,
        \Condoedge\Ai\Services\PromptSections\TenantScopingRulesSection::class, // Priority 76: per-entity $ai_team_ids predicates the acceptance gate verifies (after query_rules/75, before question/80)
        \Condoedge\Ai\Services\PromptSections\QuestionSection::class,
        \Condoedge\Ai\Services\PromptSections\TaskInstructionsSection::class,
    ],

    'response_generator_sections' => [
        \Condoedge\Ai\Services\ResponseSections\IdentitySection::class,
        \Condoedge\Ai\Services\ResponseSections\SystemPromptSection::class,
        \Condoedge\Ai\Services\ResponseSections\PrivacyAndSecurityGuidelinesSection::class,
        \Condoedge\Ai\Services\ResponseSections\ResponseGuardrailsSection::class, // Priority 1100: after the security policy; anti-over-claim + out-of-domain scoping
        \Condoedge\Ai\Services\ResponseSections\ResponseProjectContextSection::class,
        \Condoedge\Ai\Services\ResponseSections\OriginalQuestionSection::class,
        // QueryInfoSection removed from defaults: it fed the executed Cypher into the
        // response prompt, directly contradicting PrivacyAndSecurityGuidelinesSection
        // ("never reveal schema/table/property names"). The Cypher remains in metadata
        // for debugging; re-add this section (or gate it behind a 'technical' style) if needed.
        \Condoedge\Ai\Services\ResponseSections\FileContextSection::class,
        \Condoedge\Ai\Services\ResponseSections\ResponseConversationContextSection::class, // For file reference follow-ups
        \Condoedge\Ai\Services\ResponseSections\ResultsDataSection::class,
        \Condoedge\Ai\Services\ResponseSections\ResponseEntityActionsSection::class, // Priority 55: After ResultsData (50), before Statistics (60)
        \Condoedge\Ai\Services\ResponseSections\StatisticsSection::class,
        \Condoedge\Ai\Services\ResponseSections\GuidelinesSection::class,
        \Condoedge\Ai\Services\ResponseSections\ResponseTaskSection::class,
    ]
];
