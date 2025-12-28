<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * InheritanceResolver
 *
 * Detects when multiple Nodeable models share the same database table
 * (typically parent-child inheritance) and consolidates them:
 *
 * - Keeps the parent model as the canonical entity
 * - Adds child class names as aliases to the parent
 * - Extracts scopes from child models and adds them to parent's config
 *
 * Example:
 *   Person (table: persons) <- Volunteer extends Person (same table, has global scope)
 *   Result: Only Person is discovered, but "volunteer" becomes an alias and
 *           Volunteer's scopes are added to Person's configuration.
 *
 * @package Condoedge\Ai\Services\Discovery
 */
class InheritanceResolver
{
    /**
     * Resolve model inheritance and return deduplicated models
     *
     * Groups models by table, identifies parent models, and returns
     * only the canonical (parent) models with merged information from children.
     *
     * @param array<string> $modelClasses List of Nodeable model class names
     * @return array{models: array<string>, inheritance: array<string, array>}
     */
    public function resolve(array $modelClasses): array
    {
        // Group models by their database table
        $byTable = $this->groupByTable($modelClasses);

        $canonicalModels = [];
        $inheritanceMap = [];

        foreach ($byTable as $table => $classes) {
            if (count($classes) === 1) {
                // No inheritance - just one model for this table
                $canonicalModels[] = $classes[0];
                continue;
            }

            // Multiple models share the same table - find the parent
            $hierarchy = $this->resolveHierarchy($classes);

            $parent = $hierarchy['parent'];
            $children = $hierarchy['children'];

            $canonicalModels[] = $parent;

            // Store inheritance info for later merging
            $inheritanceMap[$parent] = [
                'children' => $children,
                'child_aliases' => $this->extractAliases($children),
                'child_scopes' => $this->extractScopes($children),
                'child_global_scopes' => $this->extractGlobalScopes($children),
            ];
        }

        return [
            'models' => $canonicalModels,
            'inheritance' => $inheritanceMap,
        ];
    }

    /**
     * Group model classes by their database table
     *
     * @param array<string> $modelClasses Model class names
     * @return array<string, array<string>> Table => [model classes]
     */
    private function groupByTable(array $modelClasses): array
    {
        $byTable = [];

        foreach ($modelClasses as $class) {
            try {
                $instance = new $class();
                if ($instance instanceof Model) {
                    $table = $instance->getTable();
                    $byTable[$table][] = $class;
                }
            } catch (\Throwable $e) {
                // Skip models that can't be instantiated
                continue;
            }
        }

        return $byTable;
    }

    /**
     * Resolve parent-child hierarchy for models sharing same table
     *
     * The parent is the model that is not a subclass of any other model
     * in the group. Children are all other models.
     *
     * @param array<string> $classes Model classes sharing same table
     * @return array{parent: string, children: array<string>}
     */
    private function resolveHierarchy(array $classes): array
    {
        // Find the root parent - the one that no other class in the group extends
        $parent = null;
        $children = [];

        foreach ($classes as $class) {
            $isChild = false;

            foreach ($classes as $potentialParent) {
                if ($class !== $potentialParent && is_subclass_of($class, $potentialParent)) {
                    $isChild = true;
                    break;
                }
            }

            if (!$isChild) {
                // This class is not a subclass of any other class in the group
                if ($parent === null) {
                    $parent = $class;
                } else {
                    // Multiple root classes - pick the one with shorter name (usually base)
                    if (strlen(class_basename($class)) < strlen(class_basename($parent))) {
                        $children[] = $parent;
                        $parent = $class;
                    } else {
                        $children[] = $class;
                    }
                }
            } else {
                $children[] = $class;
            }
        }

        // Fallback: if no parent found, use first class
        if ($parent === null) {
            $parent = array_shift($classes);
            $children = $classes;
        }

        return [
            'parent' => $parent,
            'children' => $children,
        ];
    }

    /**
     * Extract aliases from child models
     *
     * Uses class basename in lowercase and plural forms.
     *
     * @param array<string> $children Child model classes
     * @return array<string> Aliases
     */
    private function extractAliases(array $children): array
    {
        $aliases = [];

        foreach ($children as $class) {
            $basename = class_basename($class);

            // Add singular lowercase
            $aliases[] = strtolower($basename);

            // Add plural lowercase
            $aliases[] = strtolower($basename) . 's';

            // Add snake_case
            $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $basename));
            if (!in_array($snakeCase, $aliases)) {
                $aliases[] = $snakeCase;
            }
        }

        return array_unique($aliases);
    }

    /**
     * Extract query scopes from child models
     *
     * Finds all scope* methods and returns them as scope definitions.
     *
     * @param array<string> $children Child model classes
     * @return array<string, array> Scope definitions
     */
    private function extractScopes(array $children): array
    {
        $scopes = [];

        foreach ($children as $class) {
            try {
                $reflection = new \ReflectionClass($class);

                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    $name = $method->getName();

                    // Skip if not a scope method
                    if (!str_starts_with($name, 'scope') || $name === 'scopeSecurityForTeams') {
                        continue;
                    }

                    // Skip if defined in parent (only get child-specific scopes)
                    if ($method->getDeclaringClass()->getName() !== $class) {
                        continue;
                    }

                    $scopeName = lcfirst(substr($name, 5)); // Remove 'scope' prefix

                    $scopes[$scopeName] = [
                        'name' => $scopeName,
                        'source_model' => $class,
                        'description' => "Scope from " . class_basename($class),
                    ];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $scopes;
    }

    /**
     * Extract global scopes from child models and convert to Cypher patterns
     *
     * Supports two patterns:
     * 1. Models with getGlobalScopes() method returning scope closures
     * 2. Models with booted() method that adds global scopes
     *
     * @param array<string> $children Child model classes
     * @return array<string, array> Global scope definitions with cypher_pattern
     */
    private function extractGlobalScopes(array $children): array
    {
        $globalScopes = [];
        $spy = new CypherQueryBuilderSpy();
        $generator = new CypherPatternGenerator();

        foreach ($children as $class) {
            try {
                $scopeName = lcfirst(class_basename($class));
                $reflection = new \ReflectionClass($class);

                // Try to get global scopes via getGlobalScopes() method first
                if ($reflection->hasMethod('getGlobalScopes')) {
                    $method = $reflection->getMethod('getGlobalScopes');

                    if ($method->getDeclaringClass()->getName() === $class) {
                        $instance = $reflection->newInstanceWithoutConstructor();
                        $scopes = $instance->getGlobalScopes();

                        foreach ($scopes as $name => $closure) {
                            $scopeData = $this->executeScopeAndGenerateCypher(
                                $closure,
                                $name,
                                $class,
                                $spy,
                                $generator
                            );

                            if ($scopeData !== null) {
                                $globalScopes[$name] = $scopeData;
                            }
                        }

                        continue;
                    }
                }

                // Fallback: Check for booted() method with global scopes
                if ($reflection->hasMethod('booted')) {
                    $method = $reflection->getMethod('booted');

                    if ($method->getDeclaringClass()->getName() === $class) {
                        // Try to extract scope by analyzing the booted method
                        $scopeData = $this->extractScopeFromBooted($class, $scopeName, $spy, $generator);

                        if ($scopeData !== null) {
                            $globalScopes[$scopeName] = $scopeData;
                        }
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $globalScopes;
    }

    /**
     * Execute a scope closure with the spy and generate Cypher pattern
     *
     * @param callable $closure Scope closure
     * @param string $scopeName Scope name
     * @param string $sourceClass Source model class
     * @param CypherQueryBuilderSpy $spy Query builder spy
     * @param CypherPatternGenerator $generator Pattern generator
     * @return array|null Scope data with cypher_pattern
     */
    private function executeScopeAndGenerateCypher(
        callable $closure,
        string $scopeName,
        string $sourceClass,
        CypherQueryBuilderSpy $spy,
        CypherPatternGenerator $generator
    ): ?array {
        try {
            // Reset spy for fresh capture
            $freshSpy = new CypherQueryBuilderSpy($sourceClass);

            // Execute the scope closure
            $closure($freshSpy);

            if (!$freshSpy->hasCalls()) {
                return null;
            }

            $calls = $freshSpy->getCalls();
            $cypherPattern = $generator->generate($calls, 'n');

            // Generate human-readable concept
            $entityName = class_basename($sourceClass);
            $readableName = Str::title(str_replace('_', ' ', $scopeName));

            return [
                'name' => $scopeName,
                'type' => 'global_scope',
                'specification_type' => 'property_filter',
                'source_model' => $sourceClass,
                'cypher_pattern' => $cypherPattern,
                'concept' => "{$readableName} (subset of " . Str::plural($entityName) . ")",
                'description' => "Global scope from " . class_basename($sourceClass),
                'examples' => [
                    "Show {$scopeName}",
                    "List all {$scopeName}",
                    "How many {$scopeName} are there?",
                ],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Try to extract scope data from booted() method
     *
     * For models that use static::addGlobalScope() in booted()
     *
     * @param string $class Model class
     * @param string $scopeName Default scope name
     * @param CypherQueryBuilderSpy $spy Query builder spy
     * @param CypherPatternGenerator $generator Pattern generator
     * @return array|null Scope data or null if extraction fails
     */
    private function extractScopeFromBooted(
        string $class,
        string $scopeName,
        CypherQueryBuilderSpy $spy,
        CypherPatternGenerator $generator
    ): ?array {
        try {
            // Create instance and try to get global scopes from the model
            $reflection = new \ReflectionClass($class);
            $instance = $reflection->newInstanceWithoutConstructor();

            // Laravel stores global scopes - try to access them
            if (method_exists($instance, 'getGlobalScopes')) {
                $scopes = $instance->getGlobalScopes();

                foreach ($scopes as $name => $scope) {
                    if (is_callable($scope)) {
                        return $this->executeScopeAndGenerateCypher(
                            $scope,
                            is_string($name) ? $name : $scopeName,
                            $class,
                            $spy,
                            $generator
                        );
                    }
                }
            }

            // If we can't extract the actual closure, return basic info
            return [
                'name' => $scopeName,
                'type' => 'global_scope',
                'source_model' => $class,
                'description' => "Global scope from " . class_basename($class) . " - filters to specific subset",
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Merge child information into parent configuration
     *
     * @param array $parentConfig Parent's discovered configuration
     * @param array $inheritanceInfo Inheritance info from resolve()
     * @return array Merged configuration
     */
    public function mergeInheritanceInfo(array $parentConfig, array $inheritanceInfo): array
    {
        if (empty($inheritanceInfo)) {
            return $parentConfig;
        }

        // Merge child aliases
        if (!empty($inheritanceInfo['child_aliases'])) {
            $existingAliases = $parentConfig['metadata']['aliases'] ?? [];
            $parentConfig['metadata']['aliases'] = array_unique(
                array_merge($existingAliases, $inheritanceInfo['child_aliases'])
            );
        }

        // Merge child scopes
        if (!empty($inheritanceInfo['child_scopes'])) {
            $existingScopes = $parentConfig['metadata']['scopes'] ?? [];
            $parentConfig['metadata']['scopes'] = array_merge(
                $existingScopes,
                $inheritanceInfo['child_scopes']
            );
        }

        // Merge global scopes as special scope type
        if (!empty($inheritanceInfo['child_global_scopes'])) {
            $existingScopes = $parentConfig['metadata']['scopes'] ?? [];
            $parentConfig['metadata']['scopes'] = array_merge(
                $existingScopes,
                $inheritanceInfo['child_global_scopes']
            );
        }

        // Store child classes for reference
        if (!empty($inheritanceInfo['children'])) {
            $parentConfig['metadata']['child_models'] = $inheritanceInfo['children'];
        }

        return $parentConfig;
    }
}
