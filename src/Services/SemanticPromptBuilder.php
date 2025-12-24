<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\PromptSectionInterface;
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
 * ## Architecture
 *
 * The builder uses a priority-based module pipeline where each section contributes
 * a portion of the final prompt. Sections are processed in priority order (lower = first).
 *
 * Default sections (in priority order):
 * - project_context (10): Project name, description, business rules
 * - generic_context (15): Additional context information
 * - schema (20): Database schema and node types
 * - relationships (30): Relationship types between nodes
 * - example_entities (40): Sample data for reference
 * - similar_queries (50): Previously successful similar queries
 * - detected_entities (60): Entities detected in the user's question
 * - detected_scopes (70): Scopes/filters detected in the question
 * - pattern_library (80): Query patterns from the pattern library
 * - query_rules (90): Rules for query generation
 * - current_user (95): Current user context
 * - question (100): The user's actual question
 * - task_instructions (110): Final instructions for the LLM
 *
 * ## Configuration
 *
 * Default sections are loaded from `config('ai.query_generator_sections')`.
 *
 * ```php
 * // config/ai.php
 * return [
 *     'query_generator_sections' => [
 *         \Condoedge\Ai\Services\PromptSections\ProjectContextSection::class,
 *         \Condoedge\Ai\Services\PromptSections\SchemaSection::class,
 *         // ... more sections
 *     ],
 * ];
 * ```
 *
 * ## Extension Methods
 *
 * ### Add a new section
 * ```php
 * $builder->addModule(new CustomSection());
 * ```
 *
 * ### Remove a section
 * ```php
 * $builder->removeModule('similar_queries');
 * ```
 *
 * ### Replace a section
 * ```php
 * $builder->replaceModule('project_context', new MyProjectContextSection());
 * ```
 *
 * ### Extend with callbacks (insert content after a section)
 * ```php
 * $builder->extendAfter('schema', function($question, $context, $options) {
 *     return "\n=== CUSTOM SECTION ===\n\nYour content here\n\n";
 * });
 * ```
 *
 * ### Global extensions (applied to all instances)
 * ```php
 * SemanticPromptBuilder::extendBuild(function($builder) {
 *     $builder->addModule(new GlobalCustomSection());
 * });
 * ```
 *
 * ## Usage Example
 *
 * ```php
 * $builder = new SemanticPromptBuilder($patternLibrary);
 *
 * // Customize for your needs
 * $builder->setProjectContext([
 *     'name' => 'My CRM',
 *     'description' => 'Customer relationship management system',
 *     'business_rules' => ['All dates are in UTC'],
 * ]);
 *
 * // Build the prompt
 * $prompt = $builder->buildPrompt(
 *     question: "How many customers do we have?",
 *     context: ['schema' => $schema, 'metadata' => $metadata],
 *     allowWrite: false
 * );
 * ```
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
     * Create a new semantic prompt builder
     *
     * @param PatternLibrary $patternLibrary Pattern library instance
     */
    public function __construct(PatternLibrary $patternLibrary)
    {
        $this->patternLibrary = $patternLibrary;
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
     * Build semantic prompt for LLM query generation
     *
     * Creates a comprehensive prompt by processing all registered sections
     * in priority order.
     *
     * @param string $question User's natural language question
     * @param array $context Context with schema, metadata, and detected scopes
     * @param bool $allowWrite Whether to allow write operations
     * @return string Complete LLM prompt
     */
    public function buildPrompt(
        string $question,
        array $context,
        bool $allowWrite = false
    ): string {
        $options = ['allowWrite' => $allowWrite];

        // Start with system prompt
        $prompt = $this->systemPrompt
            ?? "You are a Neo4j Cypher query expert who generates queries based on semantic business definitions.\n\n";

        $this->processModules(
            beforeCallbackProcess: function($callback) use (&$prompt, $question, $context, $options) {
                $prompt .= $callback($question, $context, $options);
            },
            moduleProcess: function($section) use (&$prompt, $question, $context, $options) {
                if ($section->shouldInclude($question, $context, $options)) {
                    $prompt .= $section->format($question, $context, $options);
                }
            },
            afterCallbackProcess: function($callback) use (&$prompt, $question, $context, $options) {
                $prompt .= $callback($question, $context, $options);
            },
        );

        return $prompt;
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
