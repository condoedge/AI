<?php

namespace Condoedge\Ai\Services;

/**
 * HasInternalModules - Priority-based Module Pipeline Trait
 *
 * Provides a priority-based module system for building pipelines.
 * Used by SemanticPromptBuilder and ResponseGenerator to compose their outputs from
 * reusable, configurable sections.
 *
 * Core concepts:
 * - Module: a class implementing SectionModuleInterface that contributes content
 * - Priority: numeric value determining processing order (lower = earlier)
 *
 * Hosts customize the pipeline through the config section lists
 * (`ai.query_generator_sections`, `ai.response_sections`).
 *
 * @template T of \Condoedge\Ai\Contracts\SectionModuleInterface
 *
 * @see \Condoedge\Ai\Services\SemanticPromptBuilder Uses this for prompt building
 * @see \Condoedge\Ai\Services\ResponseGenerator Uses this for response generation
 * @see \Condoedge\Ai\Contracts\SectionModuleInterface Module interface contract
 */
trait HasInternalModules
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * Registered modules indexed by their unique name.
     *
     * Each module must implement SectionModuleInterface and provide:
     * - getName(): Unique identifier
     * - getPriority(): Processing order (lower = earlier)
     * - shouldInclude(): Whether to include in output
     * - format(): Generate the module's content
     *
     * @var array<string, T>
     */
    private array $sections = [];

    /**
     * Config key for loading default modules.
     *
     * The using class should set this to point to a config array containing
     * module class names. Falls back to 'ai.modules.{ClassName}' if not set.
     *
     * @var string|null
     */
    // protected $defaultModulesConfigKey = null;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /**
     * Register default modules from configuration.
     *
     * Loads module class names (or closures returning a module) from the config key
     * specified in $defaultModulesConfigKey and instantiates each one.
     *
     * @return void
     */
    public function registerDefaultModules(): void
    {
        $configKey = $this->defaultModulesConfigKey ?: ('ai.modules.' . class_basename($this));
        $sections = config($configKey, []) ?? [];

        foreach ($sections as $sectionClass) {
            try {
                // Support closure-based module instantiation.
                if (gettype($sectionClass) == 'function' || $sectionClass instanceof \Closure) {
                    $sectionClass = $sectionClass($this);
                }

                if (gettype($sectionClass) == 'string' && class_exists($sectionClass)) {
                    $this->addModule(new $sectionClass());
                }

                if (gettype($sectionClass) == 'object') {
                    $this->addModule($sectionClass);
                }
            } catch (\Throwable $e) {
                // A host config may reference a section class that was removed or
                // renamed (or whose file was deleted) — skip it rather than crash the
                // whole prompt pipeline. class_exists() itself can throw when a stale
                // autoload classmap still points at a deleted file.
                try {
                    \Illuminate\Support\Facades\Log::warning(
                        'AI: skipping a prompt section that could not be loaded',
                        [
                            'section' => is_string($sectionClass) ? $sectionClass : gettype($sectionClass),
                            'error' => $e->getMessage(),
                        ]
                    );
                } catch (\Throwable $ignored) {
                    // Logging must never itself crash section registration.
                }
            }
        }
    }

    // =========================================================================
    // MODULE MANAGEMENT - CRUD OPERATIONS
    // =========================================================================

    /**
     * Add a module to the pipeline.
     *
     * The module is stored using its getName() value as the key.
     * If a module with the same name exists, it will be replaced.
     *
     * @param T $section Module instance to add
     * @return self For method chaining
     *
     * @example
     * $builder->addModule(new CustomContextSection());
     */
    public function addModule($section): self
    {
        $this->sections[$section->getName()] = $section;
        return $this;
    }

    /**
     * Remove a module from the pipeline by name.
     *
     * Safe to call even if the module doesn't exist.
     *
     * @param string $name Module name to remove
     * @return self For method chaining
     *
     * @example
     * $builder->removeModule('similar_queries');
     */
    public function removeModule(string $name): self
    {
        unset($this->sections[$name]);
        return $this;
    }

    /**
     * Get a module by its name.
     *
     * @param string $name Module name to retrieve
     * @return T|null The module instance or null if not found
     *
     * @example
     * $schemaSection = $builder->getModule('schema');
     * if ($schemaSection instanceof SchemaCardsSection) {
     *     // ...
     * }
     */
    public function getModule(string $name)
    {
        return $this->sections[$name] ?? null;
    }

    /**
     * Check if a module exists in the pipeline.
     *
     * @param string $name Module name to check
     * @return bool True if the module exists
     *
     * @example
     * if ($builder->hasModule('similar_queries')) {
     *     $builder->removeModule('similar_queries');
     * }
     */
    public function hasModule(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    /**
     * Get all registered modules.
     *
     * Returns modules in their registered order (not priority order).
     * Use processModules() for priority-sorted processing.
     *
     * @return array<string, T> All modules keyed by name
     *
     * @example
     * foreach ($builder->getModules() as $name => $module) {
     *     echo "{$name}: priority {$module->getPriority()}\n";
     * }
     */
    public function getModules(): array
    {
        return $this->sections;
    }

    // =========================================================================
    // PIPELINE PROCESSING
    // =========================================================================

    /**
     * Process all modules through the pipeline in priority order.
     *
     * Sorts modules by priority (ascending - lower values process first) and
     * hands each one to $moduleProcess.
     *
     * @param callable $moduleProcess Handles the module itself
     *                 Signature: function(T $module): void
     * @return void
     */
    public function processModules(callable $moduleProcess): void
    {
        // Sort modules by priority (lower values = higher priority = processed first).
        $sortedSections = $this->sections;
        uasort($sortedSections, fn($a, $b) => $a->getPriority() <=> $b->getPriority());

        foreach ($sortedSections as $section) {
            $moduleProcess($section);
        }
    }
}
