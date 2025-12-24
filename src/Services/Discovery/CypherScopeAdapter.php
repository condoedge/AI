<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use ReflectionClass;
use ReflectionMethod;
use ReflectionException;
use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * CypherScopeAdapter
 *
 * Discovers Eloquent scopes in models and converts them to Cypher patterns
 * for the RAG system. Allows developers to write familiar Eloquent syntax
 * while automatically generating Neo4j query patterns.
 *
 * Usage:
 *   $adapter = new CypherScopeAdapter();
 *   $scopes = $adapter->discoverScopes(Customer::class);
 */
class CypherScopeAdapter
{
    /**
     * Query builder spy for capturing scope calls
     *
     * @var CypherQueryBuilderSpy
     */
    private CypherQueryBuilderSpy $spy;

    /**
     * Pattern generator for converting calls to Cypher
     *
     * @var CypherPatternGenerator
     */
    private CypherPatternGenerator $generator;

    /**
     * Create a new adapter instance
     *
     * @param CypherQueryBuilderSpy|null $spy Optional spy instance
     * @param CypherPatternGenerator|null $generator Optional generator instance
     */
    public function __construct(?CypherQueryBuilderSpy $spy = null, ?CypherPatternGenerator $generator = null)
    {
        $this->spy = $spy ?? new CypherQueryBuilderSpy();
        $this->generator = $generator ?? new CypherPatternGenerator();
    }

    /**
     * Discover all scopes in a model class
     *
     * @param string $modelClass Model class name
     * @return array Scope metadata for entity configuration
     * @throws ReflectionException
     */
    public function discoverScopes(string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            throw new InvalidArgumentException("Model class not found: {$modelClass}");
        }

        $reflection = new ReflectionClass($modelClass);
        $scopeMethods = $this->extractScopeMethods($reflection);

        $scopes = [];

        foreach ($scopeMethods as $method) {
            $scopeName = $this->getScopeName($method);

            try {
                $scopeData = $this->parseScope($modelClass, $scopeName, $method);
                if ($scopeData !== null) {
                    $scopes[$scopeName] = $scopeData;
                }
            } catch (\Throwable $e) {
                // Skip scopes that can't be parsed
                // In production, you might want to log these
                continue;
            }
        }

        return $scopes;
    }

    /**
     * Parse a single scope and convert to Cypher metadata
     *
     * @param string $modelClass Model class name
     * @param string $scopeName Scope name (without 'scope' prefix)
     * @param ReflectionMethod|null $method Optional reflection method
     * @return array|null Scope metadata or null if parsing fails
     */
    public function parseScope(string $modelClass, string $scopeName, ?ReflectionMethod $method = null): ?array
    {
        if ($method === null) {
            $methodName = 'scope' . Str::studly($scopeName);
            $reflection = new ReflectionClass($modelClass);

            if (!$reflection->hasMethod($methodName)) {
                throw new InvalidArgumentException("Scope method not found: {$methodName}");
            }

            $method = $reflection->getMethod($methodName);
        }

        // Execute scope with spy to capture calls
        $spy = $this->executeScopeWithSpy($modelClass, $method);

        if (!$spy->hasCalls()) {
            // Scope doesn't make any query builder calls
            return null;
        }

        $calls = $spy->getCalls();

        // Determine scope type based on calls
        $scopeType = $this->determineScopeType($calls);

        // Generate metadata based on type
        return match ($scopeType) {
            'property_filter' => $this->generatePropertyFilterScope($scopeName, $calls, $modelClass),
            'relationship_traversal' => $this->generateRelationshipScope($scopeName, $calls, $modelClass),
            default => $this->generateGenericScope($scopeName, $calls, $modelClass),
        };
    }

    /**
     * Extract all scope methods from a reflection class
     *
     * @param ReflectionClass $reflection Class reflection
     * @return array Array of ReflectionMethod objects
     */
    private function extractScopeMethods(ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Check if method name starts with 'scope' and is not the base 'scope' method
            if (str_starts_with($method->getName(), 'scope') && $method->getName() !== 'scope') {
                // Check that it's defined in this class, not inherited
                if ($method->getDeclaringClass()->getName() === $reflection->getName()) {
                    $methods[] = $method;
                }
            }
        }

        return $methods;
    }

    /**
     * Execute a scope method with the query builder spy
     *
     * @param string $modelClass Model class name
     * @param ReflectionMethod $method Scope method
     * @return CypherQueryBuilderSpy Spy with recorded calls
     */
    private function executeScopeWithSpy(string $modelClass, ReflectionMethod $method): CypherQueryBuilderSpy
    {
        $spy = new CypherQueryBuilderSpy($modelClass);

        try {
            // Create a mock instance of the model if needed
            $modelInstance = $this->createModelInstance($modelClass);

            // Get parameters count (excluding the first $query parameter)
            $params = $method->getParameters();
            $additionalParams = array_slice($params, 1);

            // Prepare arguments - spy first, then default values for other params
            $args = [$spy];
            foreach ($additionalParams as $param) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    // Provide sensible defaults based on type
                    $args[] = $this->getDefaultValueForType($param);
                }
            }

            // Invoke the scope method
            $method->invoke($modelInstance, ...$args);
        } catch (\Throwable $e) {
            // If execution fails, return empty spy
            // In production, you might want to log this
        }

        return $spy;
    }

    /**
     * Create a model instance for scope execution
     *
     * @param string $modelClass Model class name
     * @return object Model instance
     */
    private function createModelInstance(string $modelClass): object
    {
        try {
            // Try to create instance without constructor
            $reflection = new ReflectionClass($modelClass);
            return $reflection->newInstanceWithoutConstructor();
        } catch (\Throwable $e) {
            // Fallback to regular instantiation
            return new $modelClass();
        }
    }

    /**
     * Get default value for parameter type
     *
     * @param \ReflectionParameter $param Parameter reflection
     * @return mixed Default value
     */
    private function getDefaultValueForType(\ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if ($type === null) {
            return null;
        }

        $typeName = $type->getName();

        return match ($typeName) {
            'string' => 'example',
            'int' => 1,
            'float' => 1.0,
            'bool' => true,
            'array' => ['example1', 'example2'],
            default => null,
        };
    }

    /**
     * Get scope name from method (remove 'scope' prefix)
     *
     * @param ReflectionMethod $method Scope method
     * @return string Scope name
     */
    private function getScopeName(ReflectionMethod $method): string
    {
        $methodName = $method->getName();

        // Remove 'scope' prefix and convert to snake_case
        $name = substr($methodName, 5); // Remove 'scope'
        return Str::snake($name);
    }

    /**
     * Determine scope type from recorded calls
     *
     * @param array $calls Recorded calls
     * @return string Scope type
     */
    private function determineScopeType(array $calls): string
    {
        // Check if any call is a relationship query
        foreach ($calls as $call) {
            if (($call['method'] ?? '') === 'whereHas') {
                return 'relationship_traversal';
            }
        }

        // Default to property filter
        return 'property_filter';
    }

    /**
     * Generate property filter scope metadata
     *
     * @param string $scopeName Scope name
     * @param array $calls Recorded calls
     * @param string $modelClass Model class name
     * @return array Scope metadata
     */
    private function generatePropertyFilterScope(string $scopeName, array $calls, string $modelClass): array
    {
        $cypherPattern = $this->generator->generate($calls, 'n');
        $entityName = $this->getEntityName($modelClass);

        // Extract filter information
        $filter = $this->extractFilterFromCalls($calls);

        // Generate examples
        $examples = $this->generateExamples($scopeName, $entityName, 'property_filter');

        return [
            'specification_type' => 'property_filter',
            'concept' => $this->generateConcept($scopeName, $entityName),
            'cypher_pattern' => $cypherPattern,
            'filter' => $filter,
            'examples' => $examples,
        ];
    }

    /**
     * Generate relationship traversal scope metadata
     *
     * @param string $scopeName Scope name
     * @param array $calls Recorded calls
     * @param string $modelClass Model class name
     * @return array Scope metadata
     */
    private function generateRelationshipScope(string $scopeName, array $calls, string $modelClass): array
    {
        $entityName = $this->getEntityName($modelClass);

        // Parse relationship structure
        $parsedStructure = $this->parseRelationshipStructure($calls, $modelClass);

        // Generate Cypher pattern for relationship
        $cypherPattern = $this->generator->generateFullQuery($parsedStructure);

        // Generate examples (with relationship type for better examples)
        $examples = $this->generateExamples($scopeName, $entityName, 'relationship_traversal');

        // Extract the filter value if it's a role-type scope
        $roleValue = $this->extractRoleValueFromCalls($calls);

        return [
            'specification_type' => 'relationship_traversal',
            'concept' => $this->generateConcept($scopeName, $entityName, 'relationship'),
            'cypher_pattern' => $cypherPattern,
            'parsed_structure' => $parsedStructure,
            'role_value' => $roleValue, // e.g., 'volunteer', 'scout'
            'examples' => $examples,
        ];
    }

    /**
     * Extract role value from whereHas calls (for scopes like volunteers, scouts)
     *
     * @param array $calls Recorded calls
     * @return string|null The role value if found
     */
    private function extractRoleValueFromCalls(array $calls): ?string
    {
        foreach ($calls as $call) {
            if (($call['method'] ?? '') === 'whereHas') {
                $nestedCalls = $call['nested_calls'] ?? [];

                foreach ($nestedCalls as $nestedCall) {
                    if (($nestedCall['method'] ?? '') === 'where') {
                        $column = $nestedCall['column'] ?? '';
                        $value = $nestedCall['value'] ?? '';

                        // Check if it's a role/type field
                        if (in_array($column, ['role_type', 'type', 'role', 'status'])) {
                            return $value;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Generate generic scope metadata (fallback)
     *
     * @param string $scopeName Scope name
     * @param array $calls Recorded calls
     * @param string $modelClass Model class name
     * @return array Scope metadata
     */
    private function generateGenericScope(string $scopeName, array $calls, string $modelClass): array
    {
        $cypherPattern = $this->generator->generate($calls, 'n');
        $entityName = $this->getEntityName($modelClass);
        $examples = $this->generateExamples($scopeName, $entityName, 'generic');

        return [
            'specification_type' => 'generic',
            'concept' => $this->generateConcept($scopeName, $entityName),
            'cypher_pattern' => $cypherPattern,
            'examples' => $examples,
        ];
    }

    /**
     * Parse relationship structure from whereHas calls
     *
     * @param array $calls Recorded calls
     * @param string $modelClass Model class name
     * @return array Parsed structure
     */
    private function parseRelationshipStructure(array $calls, string $modelClass): array
    {
        $entityName = $this->getEntityName($modelClass);
        $relationships = [];
        $conditions = [];

        foreach ($calls as $call) {
            if (($call['method'] ?? '') === 'whereHas') {
                $relation = $call['relation'] ?? '';
                $nestedCalls = $call['nested_calls'] ?? [];

                // Determine target entity from relationship name
                $targetEntity = Str::studly(Str::singular($relation));

                $relationships[] = [
                    'type' => 'HAS_' . strtoupper(Str::snake($relation)),
                    'target' => $targetEntity,
                ];

                // Parse nested conditions
                foreach ($nestedCalls as $nestedCall) {
                    if (($nestedCall['method'] ?? '') === 'where') {
                        $conditions[] = [
                            'entity' => strtolower(substr($targetEntity, 0, 1)),
                            'field' => $nestedCall['column'] ?? '',
                            'op' => $nestedCall['operator'] ?? '=',
                            'value' => $nestedCall['value'] ?? '',
                        ];
                    }
                }
            }
        }

        return [
            'entity' => $entityName,
            'relationships' => $relationships,
            'conditions' => $conditions,
        ];
    }

    /**
     * Extract filter information from calls (for simple property filters)
     *
     * @param array $calls Recorded calls
     * @return array Filter data
     */
    private function extractFilterFromCalls(array $calls): array
    {
        $filter = [];

        foreach ($calls as $call) {
            $method = $call['method'] ?? '';

            if ($method === 'where' && ($call['type'] ?? '') === 'basic') {
                $column = $call['column'] ?? '';
                $value = $call['value'] ?? '';

                if ($column && $call['operator'] === '=') {
                    $filter[$column] = $value;
                }
            }
        }

        return $filter;
    }

    /**
     * Generate human-readable concept from scope name
     *
     * @param string $scopeName Scope name
     * @param string $entityName Entity name
     * @param string $type Scope type
     * @return string Concept description
     */
    private function generateConcept(string $scopeName, string $entityName, string $type = 'filter'): string
    {
        // Convert snake_case to title case
        $readableName = Str::title(str_replace('_', ' ', $scopeName));
        $pluralEntity = Str::plural($entityName);
        $lowerReadable = strtolower($readableName);

        if ($type === 'relationship') {
            // Check for role-like scope names
            $roleNames = ['volunteers', 'scouts', 'leaders', 'admins', 'members', 'managers', 'staff', 'customers'];
            if (in_array($scopeName, $roleNames)) {
                // e.g., "People who are volunteers" or just "Volunteers"
                return "{$pluralEntity} who are {$lowerReadable}";
            }

            return "{$pluralEntity} that have {$lowerReadable}";
        }

        // For property filters, make more natural
        if (in_array($scopeName, ['active', 'inactive', 'pending', 'approved', 'completed'])) {
            return "{$readableName} {$pluralEntity}";
        }

        return "{$pluralEntity} with {$lowerReadable} status";
    }

    /**
     * Generate example queries for scope
     *
     * @param string $scopeName Scope name
     * @param string $entityName Entity name
     * @param string $scopeType Type of scope (property_filter, relationship_traversal)
     * @return array Example queries
     */
    private function generateExamples(string $scopeName, string $entityName, string $scopeType = 'property_filter'): array
    {
        $readableName = str_replace('_', ' ', $scopeName);
        $lowerEntity = strtolower(Str::plural($entityName));
        $singularEntity = strtolower($entityName);

        // For relationship-based scopes like "volunteers", the scope name IS the role
        // so we want "Show volunteers" not "Show volunteers people"
        if ($scopeType === 'relationship_traversal') {
            // Check if scope name is a role-like word (ends with 's' or is a noun)
            $isRoleName = in_array($scopeName, ['volunteers', 'scouts', 'leaders', 'admins', 'members', 'managers']);

            if ($isRoleName) {
                return [
                    "Show all {$readableName}",
                    "List {$readableName}",
                    "Find {$readableName}",
                    "How many {$readableName} are there?",
                    "Who are the {$readableName}?",
                    "Show {$lowerEntity} who are {$readableName}",
                ];
            }

            // For other relationship traversals
            return [
                "Show {$lowerEntity} with {$readableName}",
                "List {$lowerEntity} that have {$readableName}",
                "Find {$lowerEntity} with {$readableName}",
                "How many {$lowerEntity} have {$readableName}?",
            ];
        }

        // For simple property filters
        return [
            "Show {$readableName} {$lowerEntity}",
            "List {$readableName} {$lowerEntity}",
            "Find all {$readableName} {$lowerEntity}",
            "How many {$readableName} {$lowerEntity} are there?",
        ];
    }

    /**
     * Get entity name from model class
     *
     * @param string $modelClass Model class name
     * @return string Entity name
     */
    private function getEntityName(string $modelClass): string
    {
        // Get the base class name without namespace
        $parts = explode('\\', $modelClass);
        $className = end($parts);

        // Remove 'Test' prefix if present (for test fixtures)
        if (str_starts_with($className, 'Test')) {
            $className = substr($className, 4);
        }

        return $className;
    }

    /**
     * Get the query builder spy instance
     *
     * @return CypherQueryBuilderSpy
     */
    public function getSpy(): CypherQueryBuilderSpy
    {
        return $this->spy;
    }

    /**
     * Get the pattern generator instance
     *
     * @return CypherPatternGenerator
     */
    public function getGenerator(): CypherPatternGenerator
    {
        return $this->generator;
    }
}
