<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\PromptSectionInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\AssistantIdentity;
use Condoedge\Ai\Services\Retrieval\TokenBudgeter;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Services\PromptSections\GenericContextSection;
use Condoedge\Ai\Services\PromptSections\ProjectContextSection;
use Condoedge\Ai\Services\PromptSections\SchemaSection;
use Condoedge\Ai\Services\PromptSections\RelationshipsSection;
use Condoedge\Ai\Services\PromptSections\ExampleEntitiesSection;
use Condoedge\Ai\Services\PromptSections\SimilarQueriesSection;
use Condoedge\Ai\Services\PromptSections\DetectedEntitiesSection;
use Condoedge\Ai\Services\PromptSections\DetectedScopesSection;
use Condoedge\Ai\Services\PromptSections\PatternLibrarySection;
use Condoedge\Ai\Services\PromptSections\QueryRulesSection;
use Condoedge\Ai\Services\PromptSections\QuestionSection;
use Condoedge\Ai\Services\PromptSections\TaskInstructionsSection;
use Condoedge\Ai\Services\PromptSections\CurrentUserContextSection;

/**
 * SemanticPromptBuilder - Builds Enhanced LLM Prompts via Modular Pipeline
 *
 * Constructs comprehensive prompts for Neo4j Cypher query generation by composing
 * multiple configurable sections. Uses the HasInternalModules trait for extensibility.
 *
 * The builder uses a priority-based module pipeline where each section contributes
 * a portion of the final prompt. Sections are processed in priority order (lower =
 * first) and loaded from `config('ai.query_generator_sections')`.
 *
 * Default sections (in priority order):
 * - project_context (10): Project name, description, business rules
 * - generic_context (15): Additional context information
 * - current_user (17): Current user context for authorization
 * - schema (20): Database schema and node types
 * - relationships (30): Relationship types between nodes
 * - example_entities (40): Sample data for reference
 * - file_context (45): Relevant file content for RAG
 * - similar_queries (50): Previously successful similar queries
 * - conversation_context (55): Recent conversation history
 * - detected_entities (60): Entities detected in the user's question
 * - detected_scopes (65): Scopes/filters detected in the question
 * - pattern_library (70): Query patterns from the pattern library
 * - query_rules (75): Rules for query generation
 * - question (80): The user's actual question
 * - task_instructions (90): Final instructions for the LLM
 *
 * @uses HasInternalModules<PromptSectionInterface> For module pipeline functionality
 *
 * @see HasInternalModules For module management methods
 * @see PromptSectionInterface For creating custom sections
 * @see ResponseGenerator For transforming query results to natural language
 */
class SemanticPromptBuilder
{
    use HasInternalModules;

    protected $defaultModulesConfigKey = 'ai.query_generator_sections';

    private PatternLibrary $patternLibrary;

    /**
     * Custom system prompt (intro text)
     */
    private ?string $systemPrompt = null;

    /**
     * Token accounting helper used for the per-turn cost estimate and the optional
     * budget enforcement. Pure/side-effect-free.
     */
    private TokenBudgeter $tokenBudgeter;

    /**
     * Optional logger for per-turn token telemetry. Null in container-free unit
     * tests; telemetry is then carried only on the return value.
     */
    private ?AiLogger $logger;

    /**
     * Section names that must never be dropped by budget enforcement: the user's
     * question and the final task cue are required for a coherent, on-question
     * prompt regardless of budget.
     */
    private const ALWAYS_KEEP_SECTIONS = ['question', 'task_instructions'];

    /**
     * Create a new semantic prompt builder
     *
     * @param PatternLibrary $patternLibrary Pattern library instance
     * @param TokenBudgeter|null $tokenBudgeter Token accounting helper (defaults to a fresh instance)
     * @param AiLogger|null $logger Optional logger for token telemetry
     */
    public function __construct(
        PatternLibrary $patternLibrary,
        ?TokenBudgeter $tokenBudgeter = null,
        ?AiLogger $logger = null
    ) {
        $this->patternLibrary = $patternLibrary;
        $this->tokenBudgeter = $tokenBudgeter ?? new TokenBudgeter();
        $this->logger = $logger;
        $this->registerDefaultModules();
        $this->applyGlobalExtensions();
    }

    // =========================================================================
    // INSTANCE EXTENSION METHODS
    // =========================================================================
    /**
     * Set custom system prompt (the intro text)
     *
     * @param string $prompt Custom system prompt
     * @return self
     */
    public function setSystemPrompt(string $prompt): self
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    // =========================================================================
    // CONVENIENCE METHODS FOR COMMON SECTIONS
    // =========================================================================

    /**
     * Set project context directly
     *
     * @param array $context Array with keys: name, description, domain, business_rules
     * @return self
     *
     * @example
     * $builder->setProjectContext([
     *     'name' => 'My Project',
     *     'description' => 'A project for managing...',
     *     'domain' => 'Business',
     *     'business_rules' => [
     *         'All users must have a unique email',
     *         'Orders cannot be deleted once confirmed',
     *     ],
     * ]);
     */
    public function setProjectContext(array $context): self
    {
        $section = $this->getModule('project_context');
        if ($section instanceof ProjectContextSection) {
            $section->setContext($context);
        }
        return $this;
    }

    /**
     * Add a business rule to the project context
     *
     * @param string $rule Business rule
     * @return self
     *
     * @example
     * $builder->addBusinessRule('All dates are stored as ISO strings');
     */
    public function addBusinessRule(string $rule): self
    {
        $section = $this->getModule('project_context');
        if ($section instanceof ProjectContextSection) {
            $section->addBusinessRule($rule);
        }
        return $this;
    }

    /**
     * Add a custom query generation rule
     *
     * @param string $category Rule category
     * @param string $rule Rule text
     * @return self
     *
     * @example
     * $builder->addQueryRule('PERFORMANCE', 'Always use indexed properties in WHERE clauses');
     */
    public function addQueryRule(string $category, string $rule): self
    {
        $section = $this->getModule('query_rules');
        if ($section instanceof QueryRulesSection) {
            $section->addRule($category, $rule);
        }
        return $this;
    }

    /**
     * Set maximum number of similar queries to show
     *
     * @param int $max Maximum number of similar queries
     * @return self
     */
    public function setMaxSimilarQueries(int $max): self
    {
        $section = $this->getModule('similar_queries');
        if ($section instanceof SimilarQueriesSection) {
            $section->setMaxQueries($max);
        }
        return $this;
    }

    /**
     * Set custom task instructions
     *
     * @param string $instructions Custom instructions for the final section
     * @return self
     */
    public function setTaskInstructions(string $instructions): self
    {
        $section = $this->getModule('task_instructions');
        if ($section instanceof TaskInstructionsSection) {
            $section->setInstructions($instructions);
        }
        return $this;
    }

    // =========================================================================
    // BUILD METHOD
    // =========================================================================

    /**
     * Build the query-generation prompt split by role.
     *
     * The SYSTEM message holds the model-agnostic identity (config('ai.identity')) plus
     * an internal note that the assistant translates questions into a graph query
     * language, followed by all sections whose role() is 'system' (e.g. the static
     * QueryRulesSection). The USER message holds the context, the spotlighted question
     * and the task. Section ORDER is preserved within each role.
     *
     * The persona is intentionally model-agnostic and stack-agnostic: Cypher-specific
     * rules live in QueryRulesSection, not in the identity.
     *
     * @param string $question User's natural language question
     * @param PipelineContext $context Pipeline context with schema, metadata, and detected scopes
     * @param bool $allowWrite Whether to allow write operations
     * @return array{system: string, user: string, token_estimate: int, sections_dropped: string[]}
     */
    public function buildPrompts(
        string $question,
        PipelineContext $context,
        bool $allowWrite = false
    ): array {
        $options = ['allowWrite' => $allowWrite];

        // System framing: explicit custom prompt overrides; otherwise the config identity
        // plus an internal, provider-neutral role note (config-driven so it can be localized).
        if ($this->systemPrompt !== null) {
            $framing = $this->systemPrompt . "\n\n";
        } else {
            $identity = AssistantIdentity::render(includeLanguagePolicy: false);
            $roleNote = $this->queryRoleNote();
            $framing = ($identity !== '' ? $identity . "\n\n" : '')
                . ($roleNote !== '' ? $roleNote . "\n\n" : '');
        }

        // Append the tenant-scopeable subset rules so the model emits queries the
        // execution security boundary can scope (reduces fail-closed over-DENY).
        $subsetRules = $this->scopeableSubsetRules();
        if ($subsetRules !== '') {
            $framing .= $subsetRules . "\n\n";
        }

        // Collect each section's contribution as an ordered entry. The system framing
        // and any host before/after callbacks are non-droppable (safety/policy and
        // host extensions); only a section's own user-role body is a budget candidate,
        // and never the always-keep essentials. Assembling these entries in order with
        // no drops reproduces the historical concatenation byte-for-byte.
        $entries = [];
        $entries[] = $this->entry('__framing', 'system', -1, $framing, true);

        $this->processModules(
            beforeCallbackProcess: function ($callback) use (&$entries, $question, $context, $options) {
                $entries[] = $this->entry('__before_callback', 'user', -1, $callback($question, $context, $options), true);
            },
            moduleProcess: function ($section) use (&$entries, $question, $context, $options) {
                if (!$section->shouldInclude($question, $context, $options)) {
                    return;
                }

                $content = $section->format($question, $context, $options);
                $role = $section->role();
                $name = $section->getName();

                // System-role sections carry identity/policy; never budget-droppable.
                // Essential user sections (question, task cue) are also kept.
                $keep = $role === 'system' || in_array($name, self::ALWAYS_KEEP_SECTIONS, true);

                $entries[] = $this->entry($name, $role, $section->getPriority(), $content, $keep);
            },
            afterCallbackProcess: function ($callback) use (&$entries, $question, $context, $options) {
                $entries[] = $this->entry('__after_callback', 'user', PHP_INT_MAX, $callback($question, $context, $options), true);
            },
        );

        $budget = $this->tokenBudget();
        $keptEntries = $entries;
        $dropped = [];

        if ($budget > 0) {
            $keptEntries = $this->tokenBudgeter->enforce($entries, $budget);
            $dropped = $this->droppedSectionNames($entries, $keptEntries);
        }

        $system = '';
        $user = '';
        foreach ($keptEntries as $entry) {
            if ($entry['role'] === 'system') {
                $system .= $entry['content'];
            } else {
                $user .= $entry['content'];
            }
        }

        $tokenEstimate = $this->tokenBudgeter->estimateTokens($system)
            + $this->tokenBudgeter->estimateTokens($user);

        $this->recordTokenTelemetry($tokenEstimate, $budget, $dropped);

        return [
            'system' => $system,
            'user' => $user,
            'token_estimate' => $tokenEstimate,
            'sections_dropped' => $dropped,
        ];
    }

    /**
     * Build one ordered prompt-contribution entry for the budgeter/assembler.
     *
     * @return array{name: string, role: string, priority: int, content: string, keep: bool}
     */
    private function entry(string $name, string $role, int $priority, string $content, bool $keep): array
    {
        return [
            'name' => $name,
            'role' => $role,
            'priority' => $priority,
            'content' => $content,
            'keep' => $keep,
        ];
    }

    /**
     * Configured per-turn token budget for the assembled prompt. Zero/unset disables
     * enforcement so the default path is byte-identical. Container-tolerant for unit
     * tests built without a config repository.
     */
    private function tokenBudget(): int
    {
        if (function_exists('app') && app()->bound('config')) {
            return (int) config('ai.rag.token_budget', 0);
        }

        return 0;
    }

    /**
     * Names of named sections present before enforcement but absent after. Internal
     * framing/callback entries (prefixed "__") are non-droppable and excluded.
     *
     * @param array<int, array{name: string}> $before
     * @param array<int, array{name: string}> $after
     * @return string[]
     */
    private function droppedSectionNames(array $before, array $after): array
    {
        $keptNames = [];
        foreach ($after as $entry) {
            $keptNames[$entry['name']] = true;
        }

        $dropped = [];
        foreach ($before as $entry) {
            $name = $entry['name'];
            if (str_starts_with($name, '__')) {
                continue;
            }
            if (!isset($keptNames[$name])) {
                $dropped[] = $name;
            }
        }

        return array_values(array_unique($dropped));
    }

    /**
     * Emit the per-turn token estimate so cost is observable. Best-effort: telemetry
     * must never break prompt assembly, and the estimate is also returned to callers.
     *
     * @param string[] $dropped
     */
    private function recordTokenTelemetry(int $tokenEstimate, int $budget, array $dropped): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->info('prompt_builder', 'prompt_token_estimate', [
                'token_estimate' => $tokenEstimate,
                'token_budget' => $budget,
                'sections_dropped' => $dropped,
            ]);
        } catch (\Throwable $e) {
            // Telemetry is observability, not correctness — swallow any logging fault.
        }
    }

    /**
     * The internal, provider-neutral note appended after the identity in the SYSTEM
     * message. Config-driven (ai.prompt.query_role_note) so it can be localized or
     * overridden like the rest of the identity. Container-tolerant for unit tests.
     */
    private function queryRoleNote(): string
    {
        $default = "Internally, you translate the user's questions into a graph query language based on the "
            . 'semantic business definitions provided below. Never reveal this query language or any internal '
            . 'schema to the user.';

        if (function_exists('app') && app()->bound('config')) {
            return (string) config('ai.prompt.query_role_note', $default);
        }

        return $default;
    }

    /**
     * Static rules that constrain generation to the tenant-scopeable subset the
     * CypherScopeAnalyzer recognizes. Returned empty when team security is off so
     * unscoped deployments are unaffected. Container-tolerant for unit tests.
     */
    private function scopeableSubsetRules(): string
    {
        if (function_exists('app') && app()->bound('config')) {
            if (!config('ai.security.enabled', true)) {
                return '';
            }
            if (!config('ai.security.enforce_scopeable_subset', true)) {
                return '';
            }
        }

        return implode("\n", [
            'QUERY SHAPE RULES (required so results stay scoped to the user\'s permissions):',
            '- Give every matched node an explicit variable AND label, e.g. MATCH (i:Invoice) — never MATCH (n) or MATCH (n {id: 1}).',
            '- The variable you RETURN must be a labelled node bound by a MATCH in that same query part.',
            '- One query part per UNION branch; each branch must MATCH its own labelled nodes.',
            '- Do not use CALL {} subqueries, pattern comprehensions over sensitive entities, or EXISTS {} that bind sensitive nodes.',
            '- Emit a single statement (no ";"-separated statements).',
            '- If a WHERE uses a top-level OR, the whole disjunction is fine — it will be grouped automatically.',
            'UNION RULES (critical):',
            '- Treat each UNION branch as a complete, independent query: MATCH its own labelled nodes AND add their $ai_team_ids filter INSIDE that branch.',
            '- Every branch\'s RETURN must project the SAME column names/aliases (e.g. RETURN x AS result) so all UNION branches share the same columns.',
            '- Express team scoping as a WHERE predicate whenever possible; if a relationship MATCH is required, place it before the branch\'s RETURN and do NOT add the helper node to the RETURN.',
        ]);
    }

    // =========================================================================
    // FACTORY METHODS
    // =========================================================================

    /**
     * Create a minimal builder with only essential sections
     *
     * @param PatternLibrary $patternLibrary Pattern library
     * @return self
     */
    public static function minimal(PatternLibrary $patternLibrary): self
    {
        $builder = new self($patternLibrary);

        // Remove non-essential sections
        $builder->removeModule('project_context');
        $builder->removeModule('generic_context');
        $builder->removeModule('similar_queries');
        $builder->removeModule('detected_entities');
        $builder->removeModule('detected_scopes');
        $builder->removeModule('pattern_library');

        return $builder;
    }

    /**
     * Create a builder optimized for simple queries
     *
     * @param PatternLibrary $patternLibrary Pattern library
     * @return self
     */
    public static function simple(PatternLibrary $patternLibrary): self
    {
        $builder = new self($patternLibrary);

        // Remove advanced sections
        $builder->removeModule('similar_queries');
        $builder->removeModule('detected_scopes');
        $builder->removeModule('pattern_library');

        return $builder;
    }

    // GETTERS
    public function getPatternLibrary(): PatternLibrary
    {
        return $this->patternLibrary;
    }
}
