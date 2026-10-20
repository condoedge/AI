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
            // A rule about specific entities can be scoped so it ships only when one of
            // them is in the prompt's selected schema (plain strings always ship):
            // Example: ['rule' => 'Order totals are stored as strings: wrap in toFloat()', 'entities' => ['Order']],
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
    | Automatically sync Nodeable entities to the graph (Neo4j) when they are
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
        // LLM PII-minimization list — ENFORCED (Phase 8 batch A, owner decision) by
        // Services\Response\PiiMasker: values under these columns ('*' = every entity,
        // or keyed by graph label for label-specific fields; matched by column-name
        // segment, e.g. `email` also covers `member_email`) plus any entity-config
        // security.sensible_columns are replaced by per-turn tokens ([email#1]) in
        // EVERYTHING the response LLM sees; the real values are re-inserted
        // deterministically in what the user sees (rendered table, narrative, exports).
        'identifying_fields' => [
            '*' => ['date_of_birth', 'email', 'phone', 'ssn', 'medical_id'],
        ],

        // Kill switch for the masking above.
        'mask_identifying_fields' => env('AI_MASK_IDENTIFYING_FIELDS', true),
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

        // SECURITY TOGGLE (default true): constrain query generation to the
        // tenant-scopeable Cypher subset (explicit labelled bindings, per-branch UNION
        // scoping, no CALL {} subqueries...) so the execution security boundary can
        // prove every query is team-scoped. Read by SemanticPromptBuilder,
        // TenantScopingRulesSection and QueryGenerator. false drops those prompt rules
        // and raises fail-closed denials; keep it on for any multi-tenant host.
        'enforce_scopeable_subset' => true,

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
    | Ingestion (ai:ingest / ai:sync-relationships)
    |--------------------------------------------------------------------------
    */
    'ingestion' => [
        // PHP memory_limit the long-running ingestion commands raise themselves to
        // (IngestEntitiesCommand, SyncRelationshipsCommand).
        'memory_limit' => '2G',
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Inspector cache
    |--------------------------------------------------------------------------
    */
    // Seconds to cache schema lookups. Read by:
    // - SchemaInspector (ai:discover): per-table column/type lookups;
    // - GraphSchemaCache (Task 5.3): the Neo4j graph schema (key ai:schema:v1:<database>,
    //   incl. a full-graph pattern aggregation) and the per-label property-type probes
    //   behind the prompt's schema cards. ai:ingest / ai:sync-relationships / ai:setup /
    //   ai:index-* / ai:process-files flush it when they finish (AI::flushSchemaCache()).
    //   Automatic sync (observers/jobs) flushes only when a write creates a label or
    //   relationship pattern the cached schema lacks; any other change (e.g. deleting the
    //   last node of a label) is stale for at most this TTL.
    // 0 disables the graph-schema cache.
    'schema_cache_ttl' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Query Parameterization (few-shot store)
    |--------------------------------------------------------------------------
    |
    | Extra per-user/team values QueryParameterizer swaps for placeholders before a
    | generated query is stored as a few-shot example, so user A's literal ids never
    | leak into user B's prompt. Built-ins: user_id -> $currentUserId,
    | team_id -> $currentTeamId. Entry shape:
    |   ['field' => 'contact_id', 'placeholder' => '$currentContactId',
    |    'resolver' => fn () => ...]   (or a static 'value' => ... instead of 'resolver')
    |
    */
    'query_parameters' => [],

    /*
    |--------------------------------------------------------------------------
    | Module pipeline fallback
    |--------------------------------------------------------------------------
    */
    // Fallback section lists keyed by class basename for HasInternalModules users that
    // do not set $defaultModulesConfigKey (the built-in builders use the *_sections keys below).
    'modules' => [],

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
    | - follow_up_writer, document_scope, document_map_reader: focused calls that
    |   fall back to one of the roles above when not configured (see below)
    |
    */
    'llm_roles' => [
        // 'query_understanding' => ['model' => 'gpt-4o-mini', 'temperature' => 0.0],
        // 'query_generation' => null,  // uses default
        // 'response_generation' => ['model' => 'gpt-4o-mini'],
        // 'follow_up_writer' => null,  // follow-up question chips of document answers:
        //                              // uses the query_generation model (it must judge
        //                              // what a person would naturally ask next). Pointed
        //                              // at a small model, also set
        //                              // attached_actions.follow_ups.model_declines = false.
        // 'document_scope' => null,       // document map, index time: the ONE call per divided
        //                              // document saying which of its parts govern this
        //                              // application's users. Uses the query_generation model:
        //                              // the small model answered "applies" for every part.
        // 'document_map_reader' => null,  // document map, question time: the call that reads the
        //                              // question against the documents' map. Uses the
        //                              // query_understanding model (the accurate one picked no
        //                              // better at five times the cost of a turn).
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Turn Trace
    |--------------------------------------------------------------------------
    |
    | Every chat turn (AskQuestionUseCase) records a compact trace of the
    | pipeline it ran — stage timings, what Query Understanding understood,
    | retrieved file ids / chunk indexes / scores, the generated Cypher, row
    | counts, errors and LLM token usage — persisted on the assistant message
    | under metadata.trace, so a live failure can be diagnosed after the fact.
    |
    | - enabled:         record + persist the trace (default on; observation only,
    |                    it never changes an answer).
    | - capture_prompts: ALSO store the verbatim LLM prompts per stage. Off by
    |                    default — prompts carry user data and are large; turn on
    |                    only for debugging.
    | - redact_literals: store the traced Cypher and every error message with its
    |                    string / number literals masked to `?` (keeps the query
    |                    shape, drops the user values). Default on.
    | - max_bytes:       hard cap on the persisted trace JSON (measured exactly as
    |                    the Eloquent array cast stores it). Over the cap the trace
    |                    is shrunk in order: prompts truncated then dropped, long
    |                    data samples cut, stage data dropped, then a skeleton
    |                    (stage names + timings, usage, first errors). The stored
    |                    trace never exceeds the cap.
    |
    | Privacy by construction: the understanding stage keeps only routing fields
    | (question type, intent, entity types, language, search query, follow-up /
    | digest / export / clarification flags, possessive) — never person names,
    | clarifying questions or candidate readings — and error messages are capped
    | at 300 chars.
    |
    | Retention: the trace has NO separate store and no TTL of its own. It lives
    | inside the assistant message's metadata column, so it is retained exactly
    | as long as that message and is pruned with it — deleting the conversation
    | (or the message) deletes its traces. Hosts with a stricter retention policy
    | for diagnostics should disable the trace or prune conversations accordingly.
    |
    | Note: the conversation SNAPSHOT (not the trace) keeps raw PII by design:
    | last_result_sample (real rows) and last_cypher_parameters (the $pii_… query
    | parameters → real emails/phones the PII tokens were rebound to). Same
    | retention as the conversation; never sent to an LLM. See
    | docs chat/conversation-context-management ("PII in the snapshot").
    |
    */
    'trace' => [
        'enabled' => env('AI_TRACE_ENABLED', true),
        'capture_prompts' => env('AI_TRACE_CAPTURE_PROMPTS', false),
        'redact_literals' => env('AI_TRACE_REDACT_LITERALS', true),
        'max_bytes' => env('AI_TRACE_MAX_BYTES', 65536),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Provider Configuration
    |--------------------------------------------------------------------------
    */
    'embedding' => [
        'default' => env('AI_EMBEDDING_PROVIDER', 'openai'), // 'openai' (Anthropic has no embeddings API)

        // Override to force a specific dimension. If null, uses the active provider's dimensions.
        'vector_size' => env('AI_EMBEDDING_VECTOR_SIZE', null),

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'dimensions' => 1536, // text-embedding-3-small = 1536, ada-002 = 1536
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Generation Settings
    |--------------------------------------------------------------------------
    */
    'query_generation' => [
        'allow_write_operations' => env('AI_ALLOW_WRITE_OPS', false),
        'max_retries' => env('AI_QUERY_MAX_RETRIES', 3),
        'temperature' => env('AI_QUERY_TEMPERATURE', 0.1), // Low for consistency
        'max_complexity' => env('AI_QUERY_MAX_COMPLEXITY', 100),
        // Minimum cosine similarity a stored question->cypher pair must reach to be
        // served as a few-shot example (SIMILAR QUERIES block). Below it the stored
        // query answers a different question and the generator tends to copy it.
        // Only proven queries are stored (executed OK, not safe-stopped, >0 rows).
        'example_min_similarity' => env('AI_EXAMPLE_MIN_SIMILARITY', 0.80),
        // Output budget of one generation call. The reply is the JSON object
        // {"needs_query", "cypher", "reason"} (Task 5.6), ~40 tokens over the query.
        'max_tokens' => env('AI_QUERY_MAX_TOKENS', 800),
        // Check each draft against the LIVE graph schema (unknown labels, relationship
        // patterns / directions, properties that exist on no node) and feed violations
        // back within the retry budget (Task 5.4). Only provable violations are reported.
        'schema_validation' => env('AI_QUERY_SCHEMA_VALIDATION', true),
        // Check each draft for a predicate that mixes OR / XOR with AND at one nesting
        // level (`A OR B AND C`: AND binds tighter, so C filters B only) and feed it
        // back within the same retry budget, asking for explicit parentheses. A draft
        // still ambiguous when the budget is spent runs as written, recorded in the
        // trace (precedence_violations).
        'precedence_check' => env('AI_QUERY_PRECEDENCE_CHECK', true),
        // Regenerate ONCE with the Neo4j error when the generated query fails with a
        // statement (syntax / semantic) error. Never on timeout / safe-stop / infra (Task 5.5).
        'repair_on_execution_error' => env('AI_QUERY_REPAIR_ON_EXECUTION_ERROR', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Execution Settings
    |--------------------------------------------------------------------------
    */
    'query_execution' => [
        'default_timeout' => env('AI_EXEC_TIMEOUT', 30), // seconds
        'default_limit' => env('AI_EXEC_DEFAULT_LIMIT', 100),
        'read_only_mode' => env('AI_EXEC_READ_ONLY', true),
        'default_format' => env('AI_EXEC_FORMAT', 'table'), // table, graph, json
        'log_slow_queries' => env('AI_EXEC_LOG_SLOW', true),
        'slow_query_threshold_ms' => env('AI_EXEC_SLOW_THRESHOLD', 1000),
        // Fan-out collapse (one row per anchor record): max distinct values shown
        // per collected cell; a longer list is cut and ends with "…".
        'collapse_cell_limit' => (int) env('AI_EXEC_COLLAPSE_CELL_LIMIT', 10),
        // Empty-result checks (no LLM call; each runs through the scoped executor on the
        // turn's single rate-limit charge). When a generated query returns nothing:
        //  - the abstention states the scope of the query that RAN (the user's own records
        //    vs the whole team's), read from the query itself;
        //  - an own-data query (anchored on the user through ai.user_entity_paths) gets ONE
        //    count of the ownership path it traverses, so the answer says WHY ("no unit is
        //    linked to your account") — +1 execution;
        //  - otherwise, when exactly ONE ai.attention_sources entry declares the query's
        //    entity and carries a fallback_cypher + fallback_label, that source's own query
        //    is run and, if it is empty too, its fallback rows answer the turn under the
        //    fallback label — +2 executions.
        'empty_result_checks' => (bool) env('AI_EXEC_EMPTY_RESULT_CHECKS', true),
        // Single-record enrichment (no LLM call; ONE extra execution through the scoped
        // executor on the turn's single rate-limit charge). When a data turn's result is
        // exactly one record of a configured entity and carries that record's id, the row
        // gains the name of each many-to-one parent the entity config declares — a
        // graph.relationships entry with a foreign_key among the entity's own properties
        // (Meeting.union_id → Union): `union_name`. The parent's name is its
        // metadata.name_properties, else its `name`. Identity labels
        // (response_generation.identity_labels), non-askable entities and parents the query
        // already names are skipped; at most 3 parents. Zero config.
        'enrich_single_record' => (bool) env('AI_EXEC_ENRICH_SINGLE_RECORD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rate limits to prevent DoS attacks via expensive query flooding.
    | These limits apply to query execution and LLM API calls.
    |
    | ONE CHARGE PER USER ACTION: a chat turn (its main query, companion counts,
    | fan-out / export re-runs and repairs) and an attention-digest run (up to
    | ai.digest.max_sources queries + companion counts) each count as ONE charge —
    | the caller mints a rate_limit_batch key and QueryExecutor charges the first
    | execution only. See QueryExecutor::attemptRateLimit().
    |
    | queries_per_minute — PER USER, one charge per chat turn (a turn's main query,
    | companion counts, re-runs and repairs share one charge). Executions with no
    | acting user (console / system) share one budget of the same size.
    | global_queries_per_minute — the cap on ALL users together, charged once per
    | turn as well; 0 disables it.
    | Both windows are fixed 60-second windows that open at their first charge.
    | max_wait_seconds — a turn that hits a full budget WAITS IN LINE up to this long for
    | the window to reopen (the user just sees the typing indicator); a longer wait gets
    | the 'ai.error.busy_try_again' answer with the exact seconds, at no LLM cost. 0 = never wait.
    |
    */
    'rate_limits' => [
        'queries_per_minute' => env('AI_QUERIES_PER_MINUTE', 30),
        'global_queries_per_minute' => env('AI_GLOBAL_QUERIES_PER_MINUTE', 300),
        'max_wait_seconds' => env('AI_RATE_LIMIT_MAX_WAIT_SECONDS', 20),
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
    | - 'friendly': Natural conversational style, length adapted to question type (RECOMMENDED)
    | - 'detailed': Full explanation with context
    | - 'technical': Includes query details for debugging
    |
    | The hide_* options control what technical details are excluded from responses.
    | These apply to 'detailed' style. 'minimal', 'concise', and 'friendly' styles
    | automatically hide all technical details.
    |
    | length_guidance: question_type-adaptive length guidance for the answer prompt,
    | keyed by the QU question type (data_query | knowledge_query | hybrid). Values
    | here override the built-in defaults in GuidelinesSection (data questions =>
    | concise direct answer; knowledge/document questions => short structured
    | overview allowed). The global word cap (default_max_length) always applies.
    |
    */
    'response_generation' => [
        'default_format' => env('AI_RESPONSE_FORMAT', 'text'), // text, markdown, json
        'default_style' => env('AI_RESPONSE_STYLE', 'friendly'), // minimal, concise, friendly, detailed, technical
        'default_max_length' => env('AI_RESPONSE_MAX_LENGTH', 500), // words (auto-adjusted by style)
        'length_guidance' => [
            // 'data_query' => 'This is a data question: keep it concise. ...',
            // 'knowledge_query' => 'This is a knowledge/document question: a short structured overview is appropriate. ...',
            // 'hybrid' => 'Lead with the direct answer, then ...',
        ],

        // Max characters of each PREVIOUS-turn cited file excerpt re-shown in the
        // response prompt (ResponseConversationContextSection). Excerpts stay
        // unnumbered (no [N] — never citable this turn) and spotlighted as
        // untrusted document data. Truncation keeps the chunk's START, so a cap
        // below the chunk size (file_context.snippet_length, 6000) drops the
        // cited articles a deepening follow-up needs ("what does that article say
        // exactly?") — live rights t1 lost them at 1500. Default = whole chunk.
        // TODO(Phase 7 — article-level chunking): center the excerpt on the cited
        // article instead of head-truncating, then lower this.
        'previous_snippet_chars' => (int) env('AI_RESPONSE_PREVIOUS_SNIPPET_CHARS', 6000),

        // Hide technical details from responses (affects 'detailed' style)
        'hide_technical_details' => env('AI_RESPONSE_HIDE_TECHNICAL', true),
        'hide_execution_stats' => env('AI_RESPONSE_HIDE_STATS', true),
        'hide_project_info' => env('AI_RESPONSE_HIDE_PROJECT', true),

        // ANSWER VALIDATION (Phase 8 batch B): ONE post-composition pass
        // (Services\Response\AnswerValidator) checks the draft, regenerates ONCE
        // with every violation listed (real passage numbers, real figures, real
        // ids), then applies deterministic patches for what remains. Response
        // metadata carries validation = {violations_first, retried,
        // violations_final, patched[, unresolved]}. The flags below switch
        // individual checks; the former guards' metadata flags are still emitted.
        //
        // Citations (structural): every [k] must name an existing passage, literal
        // "[N]" placeholders are normalized/stripped, an "art. 1069" claim must cite
        // the passage containing 1069, and a document-grounded knowledge turn needs
        // at least one valid marker (citation_enforcement = recovered|failed).
        // Off for terse styles whose contract forbids markers.
        'citation_enforcement' => env('AI_RESPONSE_CITATION_ENFORCEMENT', true),

        // Truncation/count honesty (BLOCKING, narrative-only path): when more rows
        // exist than were rendered into the prompt sample, the answer must state the
        // exact total AND the shown count or remainder; else the localized
        // "Showing X of Y" line is appended (truncation_honesty =
        // clean|recovered|patched; 'table' on the code-rendered table path).
        'truncation_honesty' => env('AI_RESPONSE_TRUNCATION_HONESTY', true),

        // Self-identity-as-contact (structural): every line carrying the user's own
        // email needs a "that's you" marker (wordings single-sourced from
        // SelfReferenceNoticeSection + resources/lang); else the localized marker
        // is inserted after the first unmarked occurrence (self_reference_guard =
        // clean|recovered|patched). Never fires when the email is absent.
        'self_reference_guard' => env('AI_RESPONSE_SELF_REFERENCE_GUARD', true),

        // Numbers: every stated quantity (integers <= 31 and years excepted) must be
        // one of the turn's figures — real rows, plan facts/totals, counts, or a
        // number in the prompt the model saw. An unsupported AGGREGATE (larger than
        // any row) is replaced by the single known grand total; anything else is
        // recorded under validation.unresolved, never rewritten.
        'validate_numbers' => env('AI_RESPONSE_VALIDATE_NUMBERS', true),

        // Links: entity://Label/<id> ids must be ids the rows carry for THAT label
        // (and action://…?x_id= ids must be row ids); invalid links are stripped,
        // keeping their text. An action parameter is checked only when its
        // `<label>_id` prefix names a label the rows (or the seeded identity ids —
        // current user / current team) hold; identity_labels names those labels.
        'validate_links' => env('AI_RESPONSE_VALIDATE_LINKS', true),
        'identity_labels' => ['user' => 'User', 'team' => 'Team'],

        // Deterministic data answers (Phase 8 batch A): for data turns with rows the
        // model gets a compact masked pipe-table summary + facts / system-computed
        // totals and writes ONLY the narrative with a {{table}} placeholder; code
        // renders the table from ALL rows (up to table_max_rows) with an honest
        // "Showing X of Y" line. Off for terse styles and json format; false = the
        // narrative-only path (the model lists rows itself; truncation guard above).
        'deterministic_tables' => env('AI_RESPONSE_DETERMINISTIC_TABLES', true),

        // Display cap of the code-rendered chat table (rows beyond it are disclosed).
        'table_max_rows' => (int) env('AI_RESPONSE_TABLE_MAX_ROWS', 25),

        // Currency symbol of DECLARED money columns (entity metadata.money_properties,
        // see Services\Response\MoneyColumns) in code-rendered tables, FACTS and the
        // system-computed TOTAL lines the model copies — placed where the locale's own
        // currency pattern puts it ("$1,211.48" in en, "1 211,48 $" in fr). null = no
        // symbol (plain grouped decimals). Date-times in those tables are shown in
        // app.timezone (a UTC-serialized local midnight reads as its date).
        'currency_symbol' => env('AI_RESPONSE_CURRENCY_SYMBOL'),

        // Column headers tell what the column HOLDS: a result column the query projects
        // as a plain `v.prop AS alias` whose alias shares no word with the property
        // (`u.email AS contact_info`) is headed by the property ("Email") in tables,
        // record blocks, the model's data summary and export files — the row keys are
        // untouched (Services\Response\TableRenderer::label()). A property name is a
        // word of the language the graph schema is written in: set that language here
        // (ISO 639-1). Answers in any other language keep the generator's alias, the
        // only header in the user's language. null = never replace an alias.
        'property_header_language' => env('AI_RESPONSE_PROPERTY_HEADER_LANGUAGE', 'en'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Retrieval (RAG) Settings
    |--------------------------------------------------------------------------
    */
    'rag' => [
        // Per-turn token budget for the assembled query-generation prompt. When > 0,
        // SemanticPromptBuilder drops the least-important context sections (highest
        // priority number first) until the estimated prompt fits the budget; the user's
        // question and the final task cue are always kept. 0 (the default) disables
        // enforcement, leaving the assembled prompt byte-identical to the unbudgeted
        // path. The per-turn token estimate is recorded via AiLogger regardless, so
        // cost is observable even when no budget is set.
        'token_budget' => env('AI_RAG_TOKEN_BUDGET', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic Matching Configuration
    |--------------------------------------------------------------------------
    |
    | Index: php artisan ai:index-semantic --rebuild
    |
    */
    'semantic_matching' => [
        // Scope matching used by EntityMetadataBuilder: minimum similarity and max
        // scopes returned.
        'scope_threshold' => 0.7,
        'max_scopes' => 5,
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

        // Score assigned to the previous turn's entity types when the
        // AUGMENTATION ANCHOR re-seeds them into selection (a QU-flagged
        // follow-up operating on a recorded data result, e.g. "add their last
        // login to it"). Same strength as QU entity types by default, so the
        // anchored entities rank as if QU had predicted them.
        'anchor_entity_score' => (float) env('AI_SEMANTIC_CONTEXT_ANCHOR_ENTITY_SCORE', 0.8),

        // Identity labels (also used for export identity rows) so "my X" questions can
        // traverse from the current user/team. With pruning ON they are kept only when
        // Query Understanding judges the question possessive (or a seed's tenant scoping
        // traverses them); with pruning OFF they are always kept.
        'always_include_identity_labels' => ['User', 'Team'],

        // ENTITY-SELECTION PRUNING (Task 5.10). Which labels get a schema card:
        //   seed (QU entity types, follow-up anchor, page entity, focus) > matched-scope
        //   labels > identity (possessive only) > tenant-scoping hops > shortest-path
        //   closure between seeds (live patterns + configured FK joins, at most
        //   closure_depth intermediate labels) > ownership paths (possessive only);
        //   vector hits only when nothing seeded the selection (top vector_fallback_top_k).
        // OFF = the legacy union of vector hits + relationship fan-out + identity labels.
        'pruning' => (bool) env('AI_SEMANTIC_CONTEXT_PRUNING', true),
        'max_labels' => (int) env('AI_SEMANTIC_CONTEXT_MAX_LABELS', 12),
        'closure_depth' => (int) env('AI_SEMANTIC_CONTEXT_CLOSURE_DEPTH', 2),
        'vector_fallback_top_k' => (int) env('AI_SEMANTIC_CONTEXT_VECTOR_FALLBACK_TOP_K', 5),

        // Search the scopes (business concepts) of the Query-Understanding entity types
        // directly (one extra filtered vector search) and rank them ahead of cross-entity
        // scope hits, so a generic "… for this condo" example on an unrelated entity
        // cannot crowd out the seed's own concept (e.g. the current-board scope).
        'seed_scope_search' => (bool) env('AI_SEMANTIC_CONTEXT_SEED_SCOPE_SEARCH', true),
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

        // The QU output budget moved to ai.understanding.max_tokens (Task 1.17).

        // Temperature for QU LLM call (0.0 = deterministic)
        'temperature' => (float) env('AI_QUERY_UNDERSTANDING_TEMPERATURE', 0.0),

        // Entity labels that can be mistaken for own-profile wording. When QU marks
        // a request as possessive lookup/other, these labels are not force-injected
        // unless the request is clearly a list/count/aggregate/contact-record ask.
        // Override per host app, or set [] to disable.
        'own_profile_ambiguous_entity_labels' => ['Contact'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Understanding-driven Branches
    |--------------------------------------------------------------------------
    |
    | clarify_enabled (item C — clarify branch): when true (default), a question
    | that asks for something several kinds of records carry, and says neither
    | which records nor whose, is answered with ONE clarifying question (in the
    | question's language) instead of a guess — file search and query
    | generation are skipped. The judgment is ONE small focused call on the
    | query-understanding model (ClarityChecker), made only for a first message
    | (no conversation, no page) that asks for data and whose text names no
    | configured entity, no synonym of one and no person: most turns make no
    | call. Its only domain input is host config: the entities (display names +
    | aliases) and the `calculations` the question matches (label +
    | description, at calculation_matching.min_similarity) — the application's
    | named quantities, offered as the first readings. Never fires on
    | follow-up-reference or digest turns. Set false to always force-resolve
    | (and never make the call).
    |
    | max_tokens (Task 1.17): output budget of the single QU JSON call. The
    | reply carries many keys (clarify, translations, register expansions,
    | tags); 500 truncated it into unparseable JSON. A reply still cut at this
    | budget is retried once with double the budget. The legacy
    | AI_QUERY_UNDERSTANDING_MAX_TOKENS env is honoured as a fallback.
    |
    | strict_schema: when true (default), the QU call asks for its reply under a
    | strict JSON Schema — every key the prompt documents is required, optional
    | values are explicit nulls, enumerated keys hold only their allowed values.
    | Used by providers with structured outputs (OpenAI: response_format
    | json_schema); every other provider ignores it, and a model that refuses
    | the schema (HTTP 400) is answered in plain JSON mode from then on. The
    | routes of a turn hang on single fields of this reply; measured live
    | (gpt-4o-mini, stored prompts replayed): the digest flag of a combined
    | "everything needing my attention, as a file" turn came back false on 32
    | of 164 plain-JSON replies and on 0 of 143 under the schema, and 38 of 438
    | plain replies held an intent outside its allowed list (0 under the
    | schema). Cost per call: about +270 prompt tokens (the schema), -54
    | completion tokens (the reply is written compact), median latency 3.4 s ->
    | 3.0 s. Set false for the plain JSON call.
    |
    */
    'understanding' => [
        'clarify_enabled' => env('AI_UNDERSTANDING_CLARIFY_ENABLED', true),
        'max_tokens' => (int) env('AI_UNDERSTANDING_MAX_TOKENS', env('AI_QUERY_UNDERSTANDING_MAX_TOKENS', 1200)),
        'strict_schema' => (bool) env('AI_UNDERSTANDING_STRICT_SCHEMA', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Composition
    |--------------------------------------------------------------------------
    |
    | Document-mode PDF/Excel exports use clean markdown payloads, not the visible
    | chat answer. When a possessive export has no query rows, the composer may
    | build a one-row document from the current identity context using this map.
    | Host apps can replace these paths with their own profile/contact/team fields.
    |
    */
    'export' => [
        'document_row_limit' => (int) env('AI_EXPORT_DOCUMENT_ROW_LIMIT', 250),

        // Optional tighter cap for PDF documents (null = document_row_limit). The PDF
        // renderer (dompdf) lays the whole table out in memory and grows faster than
        // linearly with rows (measured, 3 columns: 523 rows ~10.6 s / 104 MB peak, 1000 rows
        // ~28 s / 194 MB); the .xlsx writer handles 2000 rows in ~1 s / ~24 MB. Lets a host
        // raise document_row_limit for full rosters in Excel without the same PDF load.
        'pdf_document_row_limit' => env('AI_EXPORT_PDF_DOCUMENT_ROW_LIMIT'),

        // An export follow-up that asks for the recorded result as it is ("export that
        // list to excel") skips query generation: the file is the recorded query
        // re-executed up to the row limit above, as before, without paying ~6-8k tokens
        // for a query that was then discarded. Proven from Query Understanding's own
        // output: same subject as the recorded result and no property concept that
        // result does not already carry; anything else generates as before.
        'skip_generation_for_recorded_result' => (bool) env('AI_EXPORT_SKIP_GENERATION_FOR_RECORDED_RESULT', true),

        // Column names stripped from generated Excel/PDF files (case-insensitive). Empty by
        // default: an export serializes a result set the user is already authorized to see and
        // explicitly asked for, so columns like email/phone are kept. This is intentionally
        // NOT the LLM PII-minimization list (access_control.identifying_fields). Internal
        // id-ish columns (neo4j_id, *_id, leading-underscore) are always stripped regardless.
        'redact_columns' => [],

        // Set false to skip export row redaction entirely (host already redacts upstream).
        'redact_rows' => true,

        // Internal id-ish column names always stripped from exports (case-insensitive).
        'internal_columns' => ['neo4j_id', 'element_id', 'elementid', '_id'],

        // Column-name regexes treated as internal id-ish keys (anything ending _id, leading _).
        'internal_patterns' => ['/_id$/i', '/^_/'],

        // Print stylesheet for PDF document exports; null = built-in (DejaVu Sans, unicode-safe).
        'pdf_document_styles' => null,

        'identity_fields' => [
            ['label' => 'User Name', 'path' => 'user.name'],
            ['label' => 'User Email', 'path' => 'user.email'],
            ['label' => 'Team Name', 'path' => ['team.team_name', 'team.name']],
        ],
        'auto_identity_fields' => [
            'enabled' => env('AI_EXPORT_AUTO_IDENTITY_FIELDS', true),
            'sources' => [
                'user' => 'User',
                'team' => 'Team',
            ],
            'exclude' => [
                'id',
                'password',
                'remember_token',
                'api_token',
                'token',
                'secret',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'created_at',
                'updated_at',
                'deleted_at',
                'email_verified_at',
            ],
        ],
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

        // Queue processing settings
        'queue' => env('AI_FILE_QUEUE', false), // Queue file processing
        'queue_connection' => env('AI_FILE_QUEUE_CONNECTION', null),
        'queue_name' => env('AI_FILE_QUEUE_NAME', 'default'),
        'queue_threshold_bytes' => env('AI_FILE_QUEUE_THRESHOLD', 5 * 1024 * 1024), // 5MB

        // Error handling
        'fail_silently' => env('AI_FILE_FAIL_SILENTLY', true),
        'log_errors' => env('AI_FILE_LOG_ERRORS', true),

        // Structure-aware chunking (markdown headings + line-start article
        // markers such as "**1069.**", "1070.1.", "Article 12"): one article or
        // heading section per chunk. Consecutive tiny articles (under the same
        // heading) / sections merge until a chunk reaches min_chunk_chars; an
        // article or section above max_chunk_chars is split within itself on
        // paragraph/sentence boundaries, every part keeping the same metadata.
        // Text with no structure keeps the legacy paragraph/sentence splitter.
        // Re-run `ai:ingest --docs --fresh` (and re-process uploaded files)
        // after changing these.
        'chunking' => [
            'min_chunk_chars' => env('AI_CHUNK_MIN_CHARS', 200),
            'max_chunk_chars' => env('AI_CHUNK_MAX_CHARS', 2000),
        ],
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

        // UPLOADED files are LINKED to the record they are filed on; PROJECT
        // files (the physical collection above) are GLOBAL.
        //
        // The link needs no configuration: at processing time every chunk of an
        // uploaded file gets the key of its owner record (`unit:309`) and of the
        // records that own it (`union:14`), derived from the File entity's morph
        // parent and declared belongs-to relationships (config/entities). Chunks
        // stored before the keys existed: `php artisan ai:files:link`.
        //
        // dedupe — the same text filed several times on ONE record is one passage
        // (grouped inside the vector search, before every limit). The same text
        // under another owner stays another passage.
        'dedupe' => env('AI_FILE_DEDUPE', true),

        // scope.current — the ONE thing a host may declare: where the user's
        // CURRENT record lives, as `entity kind => attribute of the user`
        // (plain strings: `config:cache` keeps working), e.g.
        //     'current' => ['union' => 'current_union_id'],
        // A question is searched inside the record it is about — the page it was
        // asked on, the record the conversation is about, the files the previous
        // answer cited — and, when none of those tells, inside this one. Empty:
        // such a question is searched across everything the user may access and
        // the answer says which record each document belongs to.
        'scope' => [
            'current' => [],
        ],

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
        // here (e.g. ['fr', 'en']). On turns where document search runs (never on
        // a data-only turn), ONE small focused call (DocumentProbeWriter, on the
        // query_understanding role's model) then writes up to 2 search probes per
        // language, each added as a separate file-search fan-out angle so a
        // question in one language still retrieves documents written in another.
        // The probes add to the plain angles (search query, the user's question,
        // its clarified form), which always search; the n-th probe of every
        // language counts as ONE vote when a file's passages are chosen (see
        // angle_fusion_k / probe_duplicate_similarity below). No call is made for
        // an ask the application itself answers (QU navigation_target set).
        // Empty (default, with no corpus_register either) = no such call at all.
        'corpus_languages' => [],

        // Corpus register for lay-to-domain query expansion. When the indexed
        // documents are written in a specialized register (legal, medical,
        // regulatory...), describe that register here in plain text: name the
        // register and list a few of the corpus's own characteristic terms,
        // in the shape "<field> terminology (<term>, <term>, <term>)". The
        // probe call described above (DocumentProbeWriter) writes its search
        // probes in that register's own wording; with no corpus_languages they
        // are written in the question's language. This bridges the user's casual
        // wording to the terms of art the documents actually use. The model is
        // told the list is a guide to WORDING, not a list of subjects — keep it
        // to terms that characterise the corpus as a whole. The register text is
        // HOST configuration and the only domain input of that call: the package
        // never ships domain vocabulary of its own.
        'corpus_register' => env('AI_FILE_CORPUS_REGISTER', ''),

        // How many DISTINCT chunks of the SAME file the multi-angle merge may
        // surface as separate references (each with its own snippet), so a
        // deepening question can be grounded on more than one passage of the
        // right document. 1 = legacy single-reference-per-file behaviour.
        // 5 (was 3 until eval p16): when the whole corpus is ONE long file (a law
        // text, one article per chunk), this is the entire grounding budget of a
        // knowledge turn, and 3 passages left out the definitional or the
        // consequence article on most questions. The overall reference list is
        // always bounded by max_references above — with the default 5 a file can
        // fill the list, never exceed it; when several files match, max_references
        // slots are shared between them by relevance and each file fills its slots
        // with its consensus passages.
        'max_chunks_per_file' => env('AI_FILE_MAX_CHUNKS_PER_FILE', 5),

        // Which passages represent a file: each fan-out angle ranks the file's
        // chunks; those rankings are fused by reciprocal rank (k below, the
        // standard RRF constant) so the passage EVERY phrasing ranks near the top
        // wins over the one a single phrasing scores highest. Rankings are within
        // one file, so files and angles' raw scales are never compared.
        // One vote per angle GROUP: the search query, the user's question and the
        // clarified question are one group each; the language renderings of one
        // search probe vote together. The passage the user's own wording ranks
        // first in a file always keeps a slot.
        'angle_fusion_k' => env('AI_FILE_ANGLE_FUSION_K', 60),

        // A vote from under the floor. On (default): an angle that scores no
        // passage of a file over min_relevance_score still votes in that fusion,
        // for the ONE passage it ranks first there — the floor decides which
        // passages an angle admits, not whether it has a say. A cosine depends on
        // the wording that produced it: the user's plain words score a formal text
        // 0.35–0.45, right at the floor, while a probe in the documents' register
        // scores it 0.6–0.7. Floor-bound ballots silenced the user's own wording
        // (best passage 0.38) and let QU's rewrite vote only in the runs where it
        // happened to score 0.41 instead of 0.37, so the passage order followed
        // QU's wording. The passage the user's own wording ranks first also keeps
        // its slot whenever some angle admits it. Nothing is admitted by such a
        // vote. Cost: one more vector search for each angle that admitted nothing
        // of an admitted file (no embedding: the query vector is memoized); a turn
        // whose every angle admits its files pays nothing. Off: only passages over
        // the floor are votes.
        'vote_below_floor' => env('AI_FILE_VOTE_BELOW_FLOOR', true),

        // Passage selection: ONE small call after a knowledge turn's passages are
        // chosen (PassageSelector; role `passage_selector` of ai.llm_roles, else
        // the query_understanding role's model). The search admits a passage on
        // cosine similarity, and the article stating the general rule a specific
        // question falls under can sit far from every phrasing of it. The call
        // reads the question, the selected passages and a pool of candidates the
        // search ranked too low, and names those a correct answer still needs.
        // What the search selected always stays; the call only ADDS:
        //  - pool_size: candidates shown — the chunks the question and QU's
        //    rewrites of it rank best without the relevance floor, minus what the
        //    selected passages already hold, inside the part of each document the
        //    search selected from (measured: the needed article sat at position
        //    9–22 of that pool; 20 held it on 13 of 14 turns);
        //  - excerpt_chars: characters of each candidate the model reads (at 220
        //    a long article's relevant clause was cut off and never picked);
        //  - selected_chars: characters of EACH chunk of a selected passage it reads
        //    (shown only the first lines of a passage, it added on every turn);
        //  - max_added: chunks it may add (asked for two, it named two on every
        //    turn). An addition is the ONE chunk named, without its window: joined
        //    to the selected passage it continues (the chunk right before or after
        //    one of its chunks), else a passage after the selected ones
        //    (max_references is exceeded by at most this many).
        // Never on a file-listing or data-only turn, on an ask the application
        // answers (QU navigation_target), after a recall-floor rescue, or when the
        // pool holds no more candidates than may be added. Any failure leaves the
        // selected passages as they are. Cost per knowledge turn (measured live,
        // gpt-4o-mini, 15 calls): 3.5–4.0k prompt tokens (median 3.9k), 5–7 output
        // tokens, 1.2–3.4 s (median 1.5 s); +85–190 composition tokens when a
        // chunk is added. AI_FILE_PASSAGE_SELECTION=false turns it off.
        'passage_selection' => [
            'enabled' => env('AI_FILE_PASSAGE_SELECTION', true),
            'pool_size' => env('AI_FILE_PASSAGE_SELECTION_POOL', 20),
            'excerpt_chars' => env('AI_FILE_PASSAGE_SELECTION_EXCERPT', 350),
            'selected_chars' => env('AI_FILE_PASSAGE_SELECTION_SELECTED', 110),
            'max_added' => env('AI_FILE_PASSAGE_SELECTION_MAX_ADDED', 1),
        ],

        // DOCUMENT MAP (2026-10-08). No host key is required: the feature is on for
        // the host's OWN documents. Full page: resources/docs/1.0/advanced/document-map.md.
        //
        // INDEX TIME (DocumentMapBuilder; `php artisan ai:documents:map --backfill`,
        // or the queue after an ingest when the host has a non-sync queue — in
        // slices that fit a queue `retry_after` of 90 s, one build per file at a
        // time): each stored chunk gets a plain-language GIST (small model, one
        // chunk per call, verified by code: the gist must retrieve its own chunk),
        // and a document with parallel top-level parts gets ONE scope call (role
        // `document_scope` of ai.llm_roles, else the query-generation role) saying
        // which parts govern this application's users — the project description is
        // the only domain input. Both live in the chunk's own payload (key `map`,
        // keyed by the chunk's content hash): no table, no migration. A chunk
        // whose gist could not be built records the failed ATTEMPT instead: it is
        // not paid for again by the next backfill (`--retry-failed` asks again)
        // and does not keep its file unmapped. A re-ingest of unchanged text
        // carries the records over; a scope verdict is judged again when
        // ai.project.description changes (`--rescope` on demand). Measured
        // (2026-10-07, 134 chunks, no retry): about 700k input + 65k output
        // small-model tokens and 46 minutes sequential per 1,000 chunks.
        //
        // QUESTION TIME, for the admitted documents that carry a usable map (every
        // content chunk attempted, nine in ten with a gist; any other file keeps
        // its plain-search passages, and a turn without any mapped file keeps the
        // probe + passage-selection path above, unchanged):
        //  - chunks of a part judged out of scope are never returned by the
        //    document search nor stitched into a neighbouring passage (a question
        //    naming an article by number still gets it);
        //  - reader: ONE small call (role `document_map_reader`, else the
        //    query-understanding role) made after the plain search reads the
        //    question against the map and names the passages a complete answer must
        //    rely on, each with its role. It REPLACES the probe call and the passage
        //    selection — on every turn that is not a FOLLOW-UP (those keep probes +
        //    selection, measured). Decided per turn: a follow-up is a turn Query
        //    Understanding judged one (follow_up_reference), or one whose question,
        //    in its own wording, lands first on a passage the previous answer cited.
        //    A new question asked later in a conversation is read like a first one;
        //  - gate: when a governing pick (general rule, classification, approval)
        //    is NOT among the plain-search passages, the picks lead the passages and
        //    the answer gets a QUESTION ANALYSIS block listing those rules;
        //    otherwise passages and prompt are exactly the plain search's. An open
        //    gate never drops an article the user named by number, nor the passage
        //    the question's own wording ranks first. Measured on 53 turns: gated
        //    turns 0.45 -> 0.62 answer quality with fewer ungrounded claims,
        //    ungated turns unchanged; turn 2 s faster, +16% tokens.
        //
        // THE SWITCHES — each means one thing:
        //  - enabled (AI_DOCUMENT_MAP): false = NOTHING of the feature runs or is
        //    read. No map is built or carried, the scope filter is off (every chunk
        //    is searchable again, whatever a stored map says), no reader: every turn
        //    is the probe + passage-selection path.
        //  - reader.enabled (AI_DOCUMENT_MAP_READER): false = the scope filter stays;
        //    every turn is the probe + passage-selection path.
        //    There is NO switch for the analysis block alone: the reader used as
        //    retrieval only, without the block, measured WORSE than the probe +
        //    selection path, so "no block" is "no reader".
        //  - reader.max_chunks: a HARD cap on the gist lines one reader call is
        //    shown OF EACH DOCUMENT (a long document never takes another's lines;
        //    the whole map stays under the 676 ids the reader has). Above it that
        //    document's map is cut to the sections of the plain-search hits and,
        //    when even those do not fit (a file without headings), to the chunks
        //    nearest to the hits — a rule far from every hit is then out of the
        //    reader's sight.
        //  - uploads (AI_DOCUMENT_MAP_UPLOADS, default FALSE — the one key that is
        //    off): whether files uploaded by users (the uploads collection) are
        //    mapped too — AND whether a map they already carry is read at question
        //    time. Off, only the physical collection is mapped and read: an
        //    uploaded file counts as unmapped whatever its chunks carry, so a turn
        //    that admits one keeps the probe + passage-selection path (see below).
        //
        // SECURITY — what a document can and cannot do through the map. A gist is
        // written from a document's text and read by a model; text is never
        // trusted. The package keeps the separation by construction:
        //  - every prompt of the feature shows document-derived text inside a
        //    fence its content cannot close, and says it is data, never instructions;
        //  - the reader can only answer with ids of the map it was shown (a strict
        //    schema, checked again by code) and roles of a closed list;
        //  - the QUESTION ANALYSIS block is rendered by code from those role names,
        //    article NUMBERS and passage numbers. No sentence a model wrote and no
        //    text of a document — not the reader's "issue" sentence, not a heading
        //    — is printed in it. The passages themselves stay inside the same
        //    untrusted-data fence as on every other turn.
        // WHAT REMAINS POSSIBLE: a document the user is allowed to read can word a
        // chunk so that its gist gets it picked, shown as a passage and announced
        // by its role ("the general rule … in passage [1]") — it cannot say more
        // than its own text inside the fence, and it cannot reach a file the user
        // has no access to. That is the reach any retrieved document has on an
        // answer, plus the announcement of a role; for documents the host wrote
        // it is nil, for documents anyone can upload it is the uploader's. Hence
        // `uploads`. Also unchanged by this feature: a passage header prints the
        // file name and the heading path of the passage outside the fence.
        'document_map' => [
            'enabled' => env('AI_DOCUMENT_MAP', true),
            'uploads' => env('AI_DOCUMENT_MAP_UPLOADS', false),
            'reader' => [
                'enabled' => env('AI_DOCUMENT_MAP_READER', true),
                'max_chunks' => env('AI_DOCUMENT_MAP_READER_MAX_CHUNKS', 150),
            ],
        ],

        // A search probe that only restates another angle adds no breadth: it is
        // not searched and casts no vote. Judged by the cosine similarity of the
        // angle embeddings (already computed in one batch), at or above this value:
        //  - a probe that nearly duplicates an earlier probe is dropped in every
        //    language it was written in;
        //  - a probe rendering that nearly duplicates the question (or its
        //    clarified form) is dropped; its other-language renderings stay.
        // Embedding-model dependent. Measured on text-embedding-3-small: restated
        // probes 0.82-0.92, distinct lines of attack 0.59-0.74, a probe copying
        // the question 0.80-0.90. 0 (or a value above 1) disables the check.
        'probe_duplicate_similarity' => env('AI_FILE_PROBE_DUPLICATE_SIMILARITY', 0.8),

        // Branch coherence inside one document. A structured document can title a
        // section the same way in two of its parts (a law with a "rights and
        // obligations" division in two chapters); a question about one part then
        // retrieves passages of both, and the answer serves a rule of the other
        // part as if it applied. When a file's candidate passages sit in different
        // branches of its heading tree AND those branches hold a same-titled
        // section, only the branch carrying the most vote weight (summed over the
        // search angles' vote groups) keeps its passages. This is the share of the
        // two branches' combined vote the heavier one needs: below it the vote is
        // split and both stay. Nothing is dropped when no titles collide (a
        // question may span two parts) or the file has no heading paths.
        // 0 (or a value above 1) disables the rule.
        'branch_coherence_share' => env('AI_FILE_BRANCH_COHERENCE_SHARE', 0.55),

        // QU question types for which file search is SKIPPED entirely (no
        // fan-out, no recall floor): a pure data query gains nothing from
        // document retrieval and pays ~seconds of latency plus noise chunks
        // for it. Driven by the existing QU question_type signal — never
        // phrase matching. Set to [] to always run file search.
        'skip_for_question_types' => ['data_query'],

        // Maximum snippet length (characters) — the HARD cap on the stitched
        // passage. It must fit the ±context_window stitch: with the default window
        // of 2 that is up to 5 chunks of ~1000 chars, so 6000. (The old 1200 cut
        // the multi-chunk window back to ~one chunk everywhere except hosts that
        // overrode it.)
        'snippet_length' => env('AI_FILE_SNIPPET_LENGTH', 6000),

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

        // SECURITY TOGGLE (default false = fail closed). When the host defines no file
        // access rule, FileAccessScopePlugin denies every file. Setting true opts in to the
        // coarse TEAM-grain filter instead: every file of the user's team becomes visible to
        // every team member, ignoring per-file visibility. Only enable if you knowingly
        // accept team-level granularity.
        'team_grain_unsafe' => false,

        // Alternative: closure-based resolver (takes precedence over scope)
        // Set in boot method: config(['ai.file_context.access_resolver' => fn($user) => [...]])
        'access_resolver' => null,

        // Log file access attempts for security auditing
        // Creates audit trail in ai_file_access_logs table
        'log_access' => env('AI_FILE_ACCESS_LOG', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Probe (GET /api/ai/health)
    |--------------------------------------------------------------------------
    |
    | Middleware stack guarding the health endpoint. It reports infrastructure
    | status (Neo4j / Qdrant / LLM config), so it is authenticated by default.
    | Hosts pointing an uptime checker / load balancer at it can swap in their
    | own stack (e.g. ['api', 'auth:sanctum'] or an IP-allowlist middleware).
    | The response never includes raw exception text — failures are logged.
    |
    */
    'health' => [
        'middleware' => ['api', 'auth'],
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

        // Welcome screen (read flat by UserChatSettings::welcomeTitle()/welcomeMessage())
        'welcome_title' => env('AI_CHAT_WELCOME_TITLE', 'AI Assistant'),
        'welcome_message' => env('AI_CHAT_WELCOME_MESSAGE', 'Ask me anything about your data. I can help you find information, generate reports, and answer questions.'),

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

        // Host-provided "Explain this page" narration, keyed by page_context.entity_type
        // (falling back to the route name when a screen carries only a route). When the
        // chat is opened from a page whose key is present here AND the user asks what the
        // page is / what they can do, the assistant narrates from this text WITHOUT running
        // a graph query (so it never lands in the "no results" path). Empty = feature off:
        // a page-explanation question is then answered as an ordinary turn, unchanged.
        // e.g. 'Unit' => 'This page shows a single unit — its owners, balance and payment history. From here you can review payments and download an account statement.',
        'page_descriptions' => [
        ],

        // QU intent labels that mean "what is this page / what can I do here". The
        // narration path above activates only for these intents (and only when a matching
        // page description exists). Config-driven and model-agnostic — never a hardcoded
        // phrase list — mirroring file_context.enumeration.intents.
        'page_explanation_intents' => ['page_explanation'],

        // SOFT conversation-length guard. When a conversation reaches
        // conversation_limit.max_messages (below) the messages panel shows a banner suggesting a fresh conversation (one click copies a
        // mini resume to paste there) — sending is NEVER blocked. This is a context-QUALITY
        // threshold, not a storage cap: prompt history only reaches max_history_messages back and the
        // result snapshot is one-deep, so recall degrades well before any hard limit.
        // The default is PROVISIONAL — the long-conversation degradation study will replace
        // it with the measured knee once it runs.
        'conversation_limit' => [
            'enabled' => env('AI_CHAT_CONVERSATION_LIMIT_ENABLED', true),
            'max_messages' => env('AI_CHAT_CONVERSATION_LIMIT', 30),
        ],

        // Conversation history for LLM context
        'max_history_messages' => env('AI_CHAT_MAX_HISTORY_MESSAGES', 10), // Messages to send to LLM
        // Per-message cap (chars) on each prior ASSISTANT answer replayed in that history.
        // Too short and follow-ups lose the facts/names the previous answer listed.
        'history_answer_chars' => env('AI_CHAT_HISTORY_ANSWER_CHARS', 800),

        // UI Features
        'show_timestamps' => env('AI_CHAT_SHOW_TIMESTAMPS', false),
        'show_avatars' => env('AI_CHAT_SHOW_AVATARS', true),
        'show_typing' => env('AI_CHAT_SHOW_TYPING', true),
        'show_suggestions' => env('AI_CHAT_SHOW_SUGGESTIONS', true),
        'max_suggestions' => env('AI_CHAT_MAX_SUGGESTIONS', 3),
        'show_metrics' => env('AI_CHAT_SHOW_METRICS', false),

        // Animation defaults (UserChatSettings / AiUserSetting; users can override per account)
        'enable_animations' => true,
        'animation_speed' => 'normal',      // slow | normal | fast
        'typing_animation_style' => 'dots',

        // Input configuration
        'input_placeholder' => env('AI_CHAT_INPUT_PLACEHOLDER', 'ai.chat.input-placeholder'),

        // Features
        'enable_copy' => env('AI_CHAT_ENABLE_COPY', true),
        'enable_feedback' => env('AI_CHAT_ENABLE_FEEDBACK', false),
        'enable_regenerate' => env('AI_CHAT_ENABLE_REGENERATE', true),
        'enable_edit' => env('AI_CHAT_ENABLE_EDIT', true),
        'response_style' => env('AI_CHAT_RESPONSE_STYLE', 'friendly'),
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
    | DEPLOYMENT REQUIREMENT: the nonce is claimed with Cache::add(). With more
    | than one app server, the default cache store MUST be shared AND atomic
    | (redis or database). A per-server store (file, array, local apcu) lets
    | the same token be confirmed once per server until it expires.
    |
    | (The opt-in native tool-calling keys — native_tools, tool_rounds,
    | max_tool_calls, native_query_tool — were removed 2026-10-01 with the
    | native tool loop; actions reach the model only through the link grammar.)
    |
    */
    'actions' => [
        // \App\Ai\Actions\CreateEventAction::class,
        'token_ttl' => env('AI_ACTION_TOKEN_TTL', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attached Actions — "code attaches links/actions; the AI only narrates"
    |--------------------------------------------------------------------------
    |
    | After the query runs, CODE (ActionAttacher) decides the chips under the
    | answer from structured signals — rows, entity labels, ids, QU fields,
    | counts, money columns, permissions — and persists them on the message
    | (metadata.invocations, re-authorized at every render). The response model
    | never sees a URL, an action key or an id; it may reference a row inline
    | with {{record:N}}, which code substitutes with that row's link.
    |
    |   record chips   the entity's primary action per result record (the read
    |                  action flagged 'primary' => true, else the first read
    |                  action), labelled "<name> — <homonym disambiguator>";
    |                  max_record_chips, then "+N more" → the entity's list page
    |   identity       my_profile / my_team (built-ins) — or a host action named
    |                  under identity.{profile|team}.action
    |   next steps     by answer shape (max_next_steps): empty → list + create;
    |                  one record → its other read actions; truncated → list +
    |                  Excel; money total → report (else list)
    |   follow-ups     question chips (built-in ask_follow_up): a click sends the
    |                  question as the user's next message, exactly like typing
    |                  it. Sources, each with its own switch under follow_ups:
    |                    clarify   one chip per reading of a clarifying question
    |                              (no LLM call; every reading gets its chip)
    |                    digest    "go through <section>" for each digest section
    |                              that shows only a part of its records (no LLM
    |                              call; at most max_follow_ups)
    |                    knowledge after a document-grounded answer, ONE small
    |                              call (role follow_up_writer of ai.llm_roles;
    |                              default: the query_generation model) writes a
    |                              question per piece of retrieved text the answer
    |                              did not cover, or declines it; code keeps those
    |                              it can tie to their passage (at most
    |                              max_follow_ups)
    |
    | Placement attributes on generic actions (declarative, closure-free):
    |   'entity' => 'Unit' | ['Invoice', 'Unit'],  'kind' => 'list'|'create'|'report'
    |
    */
    'attached_actions' => [
        'enabled' => (bool) env('AI_ATTACHED_ACTIONS', true),
        'max_record_chips' => 5,
        'max_next_steps' => 3,
        // Suggested follow-up questions per answer (digest sections, document answers).
        'max_follow_ups' => 3,
        'follow_ups' => [
            'enabled' => (bool) env('AI_FOLLOW_UPS', true),
            'clarify' => true,
            'knowledge' => true,
            'digest' => true,
            // Knowledge follow-ups only — WHO judges that a follow-up is on the user's
            // matter (an irrelevant chip is worse than none):
            //
            // model_declines => true (default): the MODEL. It writes a question only for
            //   an excerpt that gives a natural next question and leaves the others
            //   empty. This needs a model that can make that judgment: the writer runs on
            //   the `follow_up_writer` role of ai.llm_roles, which defaults to the
            //   query-generation role's (accurate) model. Measured live on six questions:
            //   16 of 17 questions on the user's matter, and no question at all on the
            //   turn whose retrieved passages were off the question.
            // model_declines => false: CODE, for a small model (measured: it cannot judge
            //   this — allowed to decline it drops good excerpts, or all of them). Every
            //   excerpt gets a question; an unused passage is a source only when the user's
            //   own wording retrieved it; and the floor below applies.
            'model_declines' => (bool) env('AI_FOLLOW_UPS_MODEL_DECLINES', true),
            // The least cosine similarity (embedding model) to the user's question a
            // follow-up must have; when none has it, the answer carries no follow-up
            // chip. null = automatic: no floor (and no embedding call) when the model
            // declines, 0.45 when code gates. 0 = never. The floor keeps a question on
            // the SUBJECT of the user's question; it does not single out the natural next
            // one (measured on text-embedding-3-small — the numbers are in
            // FollowUpWriter::onTopic(); re-measure for another embedding model), and a
            // model that declines scored acceptable questions as low as 0.21.
            'min_relevance' => env('AI_FOLLOW_UPS_MIN_RELEVANCE'),
        ],
        // Append `v.id AS <label>_id` to a generated query's final RETURN for every record
        // variable that lacks it (RecordIdProjection — never aggregated variables, UNION,
        // RETURN *; MATCH/WHERE untouched). The id column is internal: never displayed.
        'project_record_ids' => true,
        'identity' => [
            // route: a named route (skipped when it does not exist); action: a host
            // generic action to use INSTEAD of the built-in (no duplicate page link).
            'profile' => ['route' => 'profile.show', 'action' => null],
            // related: keys of generic actions that are the pages of the CURRENT team's
            // own portfolio (its units, meetings, reports…). Attached beside the team link
            // only on the answer that is the current team's own single record, each for
            // who may open it, at most max_next_steps. [] (default): none.
            'team' => ['route' => 'teams.show', 'parameter' => 'team', 'action' => null, 'related' => []],
        ],
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
    | Links are attached by CODE (ai.attached_actions): each result record gets its entity's
    | primary read action as a chip ('primary' => true, else the first read action), labelled
    | with the record's name; the closure's $text receives that label.
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
        // authorize() re-resolves the exact row.
        //
        // Authorization is MANDATORY and must be EXPLICIT: gate on the SAME capability the app's
        // own UI requires for this transition (`permission:` — it also decides who the action is
        // advertised to), never on the generic CRUD policy ability alone ('update' typically lets
        // the record OWNER through — a resident could approve their own booking). A mutation with
        // no authorization, or only a generic CRUD `ability:`, fails closed. Declare the state the
        // transition applies to as a `precondition:` (re-checked at proposal, approval and write):
        // 'Reservation' => [
        //     'approve_reservation' => new \Condoedge\Ai\Services\Actions\ModelMethodMutationAction(
        //         key: 'approve_reservation',
        //         label: 'Approve reservation',
        //         finder: \App\Models\Reservation::class,            // resolved via ::find($id)
        //         mutator: fn ($reservation, array $input) => $reservation->approve(), // existing method
        //         permission: fn ($user) => $user->hasPermission('reservation:manage'), // the UI's gate
        //         authorizer: fn ($user, $reservation, array $input) => $reservation->team_id === $user->current_team_id, // row tenancy
        //         precondition: 'isPending',
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
    | Placement for code-attached chips (ai.attached_actions): 'entity' => 'Unit' (or a list)
    | + 'kind' => 'list' | 'create' | 'report' marks this page as that entity's list page,
    | create form or report. The closure's $text receives the chip label.
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
    |           'notes'       => 'Split overdue vs not-yet-due on i.due_at < toString(date()) (dates are ISO strings).',
    |       ],
    |   ],
    */
    'calculations' => [],

    // Which calculation(s) a question asks for (Task 5.11): embedding similarity
    // between the question (raw + Query-Understanding rewrite) and each calculation's
    // "label — description". Matches = best similarity >= min_similarity, plus any
    // within `margin` of the best. Matched calculations are the only ones rendered and
    // their labels seed entity selection, and the detected concepts (scopes) are kept
    // only on those labels (the metric shapes the answer); no match = no calculation
    // block. Without a
    // verdict (disabled / embedding failure) the calculations over the question's
    // primary labels render instead. One cached embedding per calculation.
    'calculation_matching' => [
        'enabled' => (bool) env('AI_CALCULATION_MATCHING_ENABLED', true),
        'min_similarity' => (float) env('AI_CALCULATION_MATCHING_MIN_SIMILARITY', 0.45),
        'margin' => (float) env('AI_CALCULATION_MATCHING_MARGIN', 0.03),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attention Sources (multi-query digest registry)
    |--------------------------------------------------------------------------
    |
    | Canonical "what needs my attention?" sources for the attention digest.
    | The package ships the MECHANISM only (QU digest_request signal, the
    | deterministic DigestRunner, the one-call DigestResponseComposer); every
    | host specific — labels, enum literals, cypher, ordering semantics — lives
    | in the HOST's published copy of this key. Ships EMPTY (white-label rule).
    | Array order = render order = attention ranking.
    |
    | Schema per entry ('<source_key>' => [...]):
    |   'label'           (required) section heading, host language
    |   'description'     one sentence of intent, rendered into composition
    |   'cypher'          (required) canonical scoped query. AUTHORING RULES:
    |                     - stay inside the scopeable subset: single primary
    |                       labeled binding, plain MATCH/OPTIONAL MATCH hops,
    |                       no UNION, no CALL {} subqueries, no label-free hops
    |                       to sensitive nodes (the coverage gate safe-stops
    |                       anything it cannot PROVE scoped — rendered as a
    |                       loud per-source error, never silence);
    |                     - no parameters beyond the $ai_team_ids convention
    |                       (bound automatically from the pipeline user; NEVER
    |                       inline team ids);
    |                     - graph dates are STRINGS: compare against
    |                       toString(datetime()) / toString(date()), never raw
    |                       datetime()/date();
    |                     - NO trailing LIMIT — the runner passes the cap via
    |                       options and the executor appends it; the companion
    |                       true-total count needs the uncapped query;
    |                     - RETURN only the fields the section renders plus the
    |                       entity id; guard deleted_at IS NULL where the label
    |                       soft-deletes.
    |   'fallback_cypher' run ONLY when the primary returns 0 rows
    |   'fallback_label'  REQUIRED with fallback_cypher — fallback rows render
    |                     under THIS heading only, never as primary rows.
    |                     Also used OUTSIDE the digest: a normal question whose
    |                     query on this 'entity' returns nothing is answered
    |                     from these rows under this label, when this is the
    |                     ONLY enabled source declaring the entity and its
    |                     primary 'cypher' is empty too
    |                     (ai.query_execution.empty_result_checks)
    |   'limit'           per-source row cap (default ai.digest.default_row_cap)
    |   'action_key'      generic action for the section link:
    |                     [label](action://<action_key>)
    |   'entity'          graph label — enables per-row entity-action links
    |   'empty_text'      null => an empty source collapses into the combined
    |                     "nothing pending in: ..." line; a string always
    |                     renders as the section's note
    |   'enabled'         ship-but-hold switch (default true)
    |
    */
    'attention_sources' => [],

    /*
    |--------------------------------------------------------------------------
    | Digest Settings
    |--------------------------------------------------------------------------
    |
    | max_sources: hard cap on sources executed per digest run. Overflow is
    | never silent: skipped keys ride metadata.digest.skipped_sources and a
    | warning is logged. default_row_cap: per-source row cap when the entry
    | omits 'limit'. NB: the shared executor rate limiter
    | (ai.rate_limits.queries_per_minute) is global — hosts with concurrent
    | chat load should budget it when enabling many sources.
    |
    */
    'digest' => [
        'max_sources' => 8,
        'default_row_cap' => 5,
    ],

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
        \Condoedge\Ai\Services\PromptSections\SchemaCardsSection::class, // Priority 20: ONE compact card per selected label (typed props, enum legends, live directed edges, fk hints) — replaces SchemaSection + RelationshipsSection + DetectedEntitiesSection (Task 5.2)
        \Condoedge\Ai\Services\PromptSections\FileContextSection::class,
        \Condoedge\Ai\Services\PromptSections\SimilarQueriesSection::class,
        \Condoedge\Ai\Services\PromptSections\ConversationContextSection::class, // Priority 55: After SimilarQueries (50)
        \Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection::class, // Priority 58: Teaches AI that "profile link" etc. are actions, not DB fields
        \Condoedge\Ai\Services\PromptSections\DetectedScopesSection::class,
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
        \Condoedge\Ai\Services\ResponseSections\PageDescriptionSection::class, // Priority 22: "Explain this page" narration grounding; zero tokens unless a page description is set on the turn
        \Condoedge\Ai\Services\ResponseSections\ResponsePageContextSection::class, // Priority 24: WHERE the user asked from (route/screen/record) — situational context for referents and next-step offers; zero tokens without page context
        \Condoedge\Ai\Services\ResponseSections\ResponseCurrentUserContextSection::class, // Priority 25: WHO the user is (same identity facts as query-gen) + insider-voice rule; zero tokens when unauthenticated
        \Condoedge\Ai\Services\ResponseSections\OriginalQuestionSection::class,
        // QueryInfoSection removed from defaults: it fed the executed Cypher into the
        // response prompt, directly contradicting PrivacyAndSecurityGuidelinesSection
        // ("never reveal schema/table/property names"). The Cypher remains in metadata
        // for debugging; re-add this section (or gate it behind a 'technical' style) if needed.
        // Renders only on a gated document-map turn (ai.file_context.document_map).
        \Condoedge\Ai\Services\ResponseSections\QuestionAnalysisSection::class,
        \Condoedge\Ai\Services\ResponseSections\FileContextSection::class,
        \Condoedge\Ai\Services\ResponseSections\ResponseConversationContextSection::class, // For file reference follow-ups
        \Condoedge\Ai\Services\ResponseSections\ResultsDataSection::class,
        \Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection::class, // Priority 52: mechanism, not prose — when a result value IS the current user's own email/name, an unmissable "that's you" note lands right under the data; zero tokens otherwise
        \Condoedge\Ai\Services\ResponseSections\AttachedActionsSection::class, // Priority 55: ONE line - links/actions are attached by code (actions plan B5); the model may only write {{record:N}}
        // StatisticsSection folded into ResultsDataSection (Task 5.8): row count + gated execution time.
        \Condoedge\Ai\Services\ResponseSections\GuidelinesSection::class,
        \Condoedge\Ai\Services\ResponseSections\ResponseTaskSection::class,
    ]
];
