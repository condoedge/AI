<?php

namespace Condoedge\Ai\Services;

/**
 * HasInternalModules - Extensible Module Pipeline Trait
 *
 * Provides a flexible, priority-based module system for building extensible pipelines.
 * Used by SemanticPromptBuilder and ResponseGenerator to compose their outputs from
 * reusable, configurable sections.
 *
 * ## Core Concepts
 *
 * - **Module**: A class implementing SectionModuleInterface that contributes content
 * - **Priority**: Numeric value determining processing order (lower = earlier)
 * - **Callbacks**: Hooks that run before/after specific modules
 * - **Global Extensions**: Modifications applied to all new instances
 *
 * ## Architecture
 *
 * ```
 * ┌─────────────────────────────────────────────────────────────┐
 * │                    HasInternalModules                       │
 * ├─────────────────────────────────────────────────────────────┤
 * │  Properties:                                                │
 * │  - $sections[]        → Registered modules by name          │
 * │  - $beforeCallbacks[] → Hooks before modules                │
 * │  - $afterCallbacks[]  → Hooks after modules                 │
 * │  - $globalExtensions  → Static extensions for all instances │
 * ├─────────────────────────────────────────────────────────────┤
 * │  Pipeline Processing:                                       │
 * │  1. Sort modules by priority                                │
 * │  2. For each module:                                        │
 * │     a. Run before callbacks                                 │
 * │     b. Process module (if shouldInclude)                    │
 * │     c. Run after callbacks                                  │
 * └─────────────────────────────────────────────────────────────┘
 * ```
 *
 * ## Usage Examples
 *
 * ### Basic Usage
 * ```php
 * class MyBuilder {
 *     use HasInternalModules;
 *
 *     protected $defaultModulesConfigKey = 'myapp.builder_modules';
 *
 *     public function __construct() {
 *         $this->registerDefaultModules();
 *         $this->applyGlobalExtensions();
 *     }
 * }
 * ```
 *
 * ### Adding/Removing Modules
 * ```php
 * $builder->addModule(new CustomModule());
 * $builder->removeModule('unwanted_module');
 * $builder->replaceModule('old_module', new NewModule());
 * ```
 *
 * ### Extending with Callbacks
 * ```php
 * $builder->extendAfter('schema', function($context, $options) {
 *     return "\n=== CUSTOM SECTION ===\n";
 * });
 * ```
 *
 * ### Global Extensions
 * ```php
 * MyBuilder::extendBuild(function($builder) {
 *     $builder->addModule(new GlobalModule());
 * });
 * ```
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
     * Global extensions applied to all new instances of the using class.
     *
     * These callbacks are executed during construction via applyGlobalExtensions().
     * Useful for package-level customizations that should affect all instances.
     *
     * @var callable[]
     */
    private static array $globalExtensions = [];

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
     * Callbacks to execute BEFORE specific modules.
     *
     * Keyed by module name, each entry contains an array of callables.
     * Callbacks receive the same parameters as the module's format() method.
     *
     * @var array<string, callable[]>
     */
    private array $beforeCallbacks = [];

    /**
     * Callbacks to execute AFTER specific modules.
     *
     * Keyed by module name, each entry contains an array of callables.
     * Callbacks receive the same parameters as the module's format() method.
     *
     * @var array<string, callable[]>
     */
    private array $afterCallbacks = [];

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
     * Loads module class names from the config key specified in
     * $defaultModulesConfigKey and instantiates each one.
     *
     * Config format:
     * ```php
     * // config/ai.php
     * return [
     *     'query_generator_sections' => [
     *         \App\Sections\SchemaSection::class,
     *         \App\Sections\QueryRulesSection::class,
     *         // Or use a closure for dynamic instantiation:
     *         fn($builder) => new CustomSection($builder->getConfig()),
     *     ],
     * ];
     * ```
     *
     * @return void
     */
    public function registerDefaultModules(): void
    {
        $configKey = $this->defaultModulesConfigKey ?: ('ai.modules.' . class_basename($this));
        $sections = config($configKey, []) ?? [];

        foreach ($sections as $sectionClass) {
            // Support closure-based module instantiation
            if (gettype($sectionClass) == 'function' || $sectionClass instanceof \Closure) {
                $sectionClass = $sectionClass($this);
            }

            // Instantiate and add the module
            if (gettype($sectionClass) == 'string' && class_exists($sectionClass)) {
                $this->addModule(new $sectionClass());
            }

            if (gettype($sectionClass) == 'object') {
                $this->addModule($sectionClass);
            }
        }
    }

    /**
     * Apply all registered global extensions to this instance.
     *
     * Should be called in the constructor after registerDefaultModules().
     * Each extension callback receives this instance as its only parameter.
     *
     * @return void
     */
    public function applyGlobalExtensions(): void
    {
        foreach (self::$globalExtensions as $extension) {
            $extension($this);
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
     * Replace an existing module with a new one.
     *
     * Removes the old module by name and adds the new one.
     * The new module's name doesn't need to match the old one.
     *
     * @param string $name Name of the module to replace
     * @param T $section New module instance
     * @return self For method chaining
     *
     * @example
     * $builder->replaceModule('project_context', new CustomProjectContext());
     */
    public function replaceModule(string $name, $section): self
    {
        $this->removeModule($name);
        $this->addModule($section);
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
     * if ($schemaSection instanceof SchemaSection) {
     *     $schemaSection->setMaxDepth(3);
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
    // CALLBACK EXTENSIONS
    // =========================================================================

    /**
     * Add a callback to run AFTER a specific module.
     *
     * The callback receives the same parameters passed to processModules()
     * and should return a string to append to the output.
     *
     * Multiple callbacks can be registered for the same module and will
     * execute in registration order.
     *
     * @param string $sectionName Module name to extend after
     * @param callable $callback Callback function
     * @return self For method chaining
     *
     * @example
     * // Add custom context after the schema section
     * $builder->extendAfter('schema', function($question, $context, $options) {
     *     return "\n=== CUSTOM INFO ===\n\nAdditional context here\n\n";
     * });
     */
    public function extendAfter(string $sectionName, callable $callback): self
    {
        $this->afterCallbacks[$sectionName][] = $callback;
        return $this;
    }

    /**
     * Add a callback to run BEFORE a specific module.
     *
     * The callback receives the same parameters passed to processModules()
     * and should return a string to prepend to the output.
     *
     * Multiple callbacks can be registered for the same module and will
     * execute in registration order.
     *
     * @param string $sectionName Module name to extend before
     * @param callable $callback Callback function
     * @return self For method chaining
     *
     * @example
     * // Add hints before the question section
     * $builder->extendBefore('question', function($question, $context, $options) {
     *     return "\n=== HINTS ===\n\nSome helpful hints\n\n";
     * });
     */
    public function extendBefore(string $sectionName, callable $callback): self
    {
        $this->beforeCallbacks[$sectionName][] = $callback;
        return $this;
    }

    // =========================================================================
    // STATIC GLOBAL EXTENSIONS
    // =========================================================================

    /**
     * Register a global extension for all new instances.
     *
     * Global extensions are applied during construction when
     * applyGlobalExtensions() is called. Useful for package-level
     * or application-wide customizations.
     *
     * Extensions persist for the lifetime of the PHP process.
     * Use clearGlobalExtensions() in tests to reset state.
     *
     * @param callable $callback Function that receives the builder instance
     * @return void
     *
     * @example
     * // In a service provider boot() method:
     * SemanticPromptBuilder::extendBuild(function($builder) {
     *     $builder->addModule(new CompanySpecificSection());
     *     $builder->setSystemPrompt("You are our company's AI assistant...");
     * });
     */
    public static function extendBuild(callable $callback): void
    {
        self::$globalExtensions[] = $callback;
    }

    /**
     * Clear all global extensions.
     *
     * Primarily useful in testing to ensure a clean state between tests.
     * Does not affect already-created instances.
     *
     * @return void
     *
     * @example
     * // In PHPUnit setUp() or tearDown():
     * SemanticPromptBuilder::clearGlobalExtensions();
     */
    public static function clearGlobalExtensions(): void
    {
        self::$globalExtensions = [];
    }

    // =========================================================================
    // PIPELINE PROCESSING
    // =========================================================================

    /**
     * Process all modules through the pipeline in priority order.
     *
     * This is the core method that executes the module pipeline. It:
     * 1. Sorts modules by priority (ascending - lower values process first)
     * 2. For each module, in order:
     *    a. Executes all "before" callbacks for that module
     *    b. Executes the module itself via the moduleProcess callback
     *    c. Executes all "after" callbacks for that module
     *
     * The three callback parameters allow the using class to define how
     * each part of the pipeline contributes to the final output.
     *
     * @param callable $beforeCallbackProcess Handles before callbacks
     *                 Signature: function(callable $callback): void
     * @param callable $moduleProcess Handles the module itself
     *                 Signature: function(T $module): void
     * @param callable $afterCallbackProcess Handles after callbacks
     *                 Signature: function(callable $callback): void
     * @return void
     *
     * @example
     * In SemanticPromptBuilder::buildPrompt()
     * ```$this->processModules(
     *     beforeCallbackProcess: function($callback) use (&$prompt, $question, $context, $options) {
     *         $prompt .= $callback($question, $context, $options);
     *     },
     *     moduleProcess: function($section) use (&$prompt, $question, $context, $options) {
     *         if ($section->shouldInclude($question, $context, $options)) {
     *             $prompt .= $section->format($question, $context, $options);
     *         }
     *     },
     *     afterCallbackProcess: function($callback) use (&$prompt, $question, $context, $options) {
     *         $prompt .= $callback($question, $context, $options);
     *     },
     * );
     * ```
     */
    public function processModules(
        callable $beforeCallbackProcess,
        callable $moduleProcess,
        callable $afterCallbackProcess
    ): void {
        // Sort modules by priority (lower values = higher priority = processed first)
        $sortedSections = $this->sections;
        uasort($sortedSections, fn($a, $b) => $a->getPriority() <=> $b->getPriority());

        // Process each module with its callbacks
        foreach ($sortedSections as $section) {
            $sectionName = $section->getName();

            // Execute "before" callbacks for this module
            if (isset($this->beforeCallbacks[$sectionName])) {
                foreach ($this->beforeCallbacks[$sectionName] as $callback) {
                    $beforeCallbackProcess($callback);
                }
            }

            // Execute the module itself
            $moduleProcess($section);

            // Execute "after" callbacks for this module
            if (isset($this->afterCallbacks[$sectionName])) {
                foreach ($this->afterCallbacks[$sectionName] as $callback) {
                    $afterCallbackProcess($callback);
                }
            }
        }
    }
}
