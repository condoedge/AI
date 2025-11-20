<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * PropertyDiscoverer
 *
 * Discovers properties from Eloquent model attributes and enhances them
 * with database schema information. Inspects model's $fillable, $casts,
 * $dates, and other metadata to build a comprehensive property list.
 *
 * Usage:
 *   $discoverer = new PropertyDiscoverer($schemaInspector);
 *   $properties = $discoverer->discover($customer);
 *   // ['id', 'name', 'email', 'status', 'created_at', 'updated_at']
 *
 * @package Condoedge\Ai\Services\Discovery
 */
class PropertyDiscoverer
{
    /**
     * Properties to always exclude from discovery
     */
    private const EXCLUDED_PROPERTIES = [
        'password',
        'password_confirmation',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Create a new property discoverer
     *
     * @param SchemaInspector $schema Schema inspector for database hints
     */
    public function __construct(
        private SchemaInspector $schema
    ) {}

    /**
     * Discover properties from a model
     *
     * Combines model attributes with schema information to build
     * a complete list of properties suitable for storage in Neo4j.
     *
     * @param string|Model $model Model class name or instance
     * @return array List of property names
     */
    public function discover(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);

        try {
            $tableName = $modelInstance->getTable();
        } catch (\Throwable $e) {
            // If table not configured, set to null
            $tableName = null;
        }

        // Get properties from model attributes
        $properties = $this->fromModelAttributes($modelInstance);

        // Enhance with schema information (only if table exists)
        if ($tableName !== null) {
            $properties = $this->enhanceWithSchema($properties, $tableName);
        }

        // Remove excluded properties
        $properties = $this->filterExcluded($properties);

        // Always include primary key and timestamps
        $properties = $this->ensureEssentialFields($properties, $modelInstance);

        return array_values(array_unique($properties));
    }

    /**
     * Get properties with their types
     *
     * Returns an associative array of property names to their types.
     *
     * @param string|Model $model Model class name or instance
     * @return array<string, string> Property name to type mappings
     */
    public function discoverWithTypes(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);

        try {
            $tableName = $modelInstance->getTable();
        } catch (\Throwable $e) {
            // If table not configured, set to null
            $tableName = null;
        }

        $properties = $this->discover($model);

        // Get column types from schema (only if table exists)
        $columnTypes = [];
        if ($tableName !== null) {
            $columnTypes = $this->schema->getColumnTypes($tableName);
        }

        $typedProperties = [];
        foreach ($properties as $property) {
            $typedProperties[$property] = $columnTypes[$property] ?? 'string';
        }

        return $typedProperties;
    }

    /**
     * Get properties from model attributes
     *
     * Extracts properties from $fillable, $casts, $dates, and other
     * model metadata.
     *
     * @param Model $model Model instance
     * @return array List of property names
     */
    private function fromModelAttributes(Model $model): array
    {
        $properties = [];

        // Get fillable attributes
        try {
            $fillable = $model->getFillable();
            if (!empty($fillable)) {
                $properties = array_merge($properties, $fillable);
            }
        } catch (\Throwable $e) {
            // Skip if model doesn't support fillable
        }

        // Get casted attributes
        try {
            $casts = $model->getCasts();
            if (!empty($casts)) {
                $properties = array_merge($properties, array_keys($casts));
            }
        } catch (\Throwable $e) {
            // Skip if model doesn't support casts
        }

        // Get date attributes
        try {
            $dates = $model->getDates();
            if (!empty($dates)) {
                $properties = array_merge($properties, $dates);
            }
        } catch (\Throwable $e) {
            // Skip if model doesn't support dates
        }

        // Get attributes from model instance (if any have been set)
        try {
            $attributes = $model->getAttributes();
            if (!empty($attributes)) {
                $properties = array_merge($properties, array_keys($attributes));
            }
        } catch (\Throwable $e) {
            // Skip if model doesn't support attributes
        }

        return array_unique($properties);
    }

    /**
     * Enhance properties with schema hints
     *
     * Adds additional properties from database schema that might not
     * be explicitly declared in the model.
     *
     * @param array $properties Current properties
     * @param string $table Table name
     * @return array Enhanced properties
     */
    private function enhanceWithSchema(array $properties, string $table): array
    {
        // Get indexed columns (likely important)
        $indexedColumns = $this->schema->getIndexedColumns($table);
        $properties = array_merge($properties, $indexedColumns);

        // Get foreign key columns
        $foreignKeys = $this->schema->getForeignKeys($table);
        $properties = array_merge($properties, array_keys($foreignKeys));

        return array_unique($properties);
    }

    /**
     * Filter out excluded properties
     *
     * Removes sensitive fields and properties that shouldn't be stored
     * in the graph database.
     *
     * @param array $properties Properties to filter
     * @return array Filtered properties
     */
    private function filterExcluded(array $properties): array
    {
        return array_filter($properties, function ($property) {
            // Check against excluded list
            if (in_array($property, self::EXCLUDED_PROPERTIES, true)) {
                return false;
            }

            // Exclude any property containing 'password' or 'secret'
            $lower = strtolower($property);
            if (str_contains($lower, 'password') || str_contains($lower, 'secret')) {
                return false;
            }

            return true;
        });
    }

    /**
     * Ensure essential fields are included
     *
     * Always include primary key and timestamp fields.
     *
     * @param array $properties Current properties
     * @param Model $model Model instance
     * @return array Properties with essential fields
     */
    private function ensureEssentialFields(array $properties, Model $model): array
    {
        $essential = [];

        // Include primary key
        try {
            $primaryKey = $model->getKeyName();
            if ($primaryKey) {
                $essential[] = $primaryKey;
            }
        } catch (\Throwable $e) {
            // Skip if model doesn't support getKeyName
        }

        // Include timestamps if enabled
        try {
            if ($model->usesTimestamps()) {
                $essential[] = $model::CREATED_AT;
                $essential[] = $model::UPDATED_AT;
            }
        } catch (\Throwable $e) {
            // Skip if model doesn't support timestamps
        }

        return array_unique(array_merge($essential, $properties));
    }

    /**
     * Resolve model to instance
     *
     * @param string|Model $model Model class name or instance
     * @return Model Model instance
     */
    private function resolveModel(string|Model $model): Model
    {
        if (is_string($model)) {
            return new $model();
        }

        return $model;
    }

    /**
     * Get property descriptions
     *
     * Generates human-readable descriptions for properties.
     *
     * @param string|Model $model Model class name or instance
     * @return array<string, string> Property descriptions
     */
    public function discoverDescriptions(string|Model $model): array
    {
        $properties = $this->discover($model);
        $descriptions = [];

        foreach ($properties as $property) {
            $descriptions[$property] = $this->generateDescription($property);
        }

        return $descriptions;
    }

    /**
     * Generate a description for a property
     *
     * Creates a human-readable description based on property name.
     *
     * @param string $property Property name
     * @return string Description
     */
    private function generateDescription(string $property): string
    {
        // Common property mappings
        $commonDescriptions = [
            'id' => 'Unique identifier',
            'created_at' => 'Creation timestamp',
            'updated_at' => 'Last update timestamp',
            'deleted_at' => 'Deletion timestamp (soft deletes)',
            'email' => 'Email address',
            'name' => 'Name',
            'status' => 'Status',
            'type' => 'Type',
            'description' => 'Description',
            'notes' => 'Notes',
            'total' => 'Total amount',
            'quantity' => 'Quantity',
            'price' => 'Price',
            'amount' => 'Amount',
            'date' => 'Date',
            'timestamp' => 'Timestamp',
        ];

        // Check common descriptions first
        if (isset($commonDescriptions[$property])) {
            return $commonDescriptions[$property];
        }

        // Handle foreign keys
        if (str_ends_with($property, '_id')) {
            $baseName = substr($property, 0, -3);
            $readableName = Str::title(str_replace('_', ' ', $baseName));
            return "Foreign key to {$readableName}";
        }

        // Handle _at suffixes (timestamps)
        if (str_ends_with($property, '_at')) {
            $baseName = substr($property, 0, -3);
            $readableName = Str::title(str_replace('_', ' ', $baseName));
            return "{$readableName} timestamp";
        }

        // Handle _on suffixes (dates)
        if (str_ends_with($property, '_on')) {
            $baseName = substr($property, 0, -3);
            $readableName = Str::title(str_replace('_', ' ', $baseName));
            return "{$readableName} date";
        }

        // Handle _count suffixes
        if (str_ends_with($property, '_count')) {
            $baseName = substr($property, 0, -6);
            $readableName = Str::title(str_replace('_', ' ', $baseName));
            return "Count of {$readableName}";
        }

        // Handle is_ prefix (boolean flags)
        if (str_starts_with($property, 'is_')) {
            $baseName = substr($property, 3);
            $readableName = Str::title(str_replace('_', ' ', $baseName));
            return "Flag indicating if {$readableName}";
        }

        // Handle has_ prefix (boolean flags)
        if (str_starts_with($property, 'has_')) {
            $baseName = substr($property, 4);
            $readableName = Str::title(str_replace('_', ' ', $baseName));
            return "Flag indicating has {$readableName}";
        }

        // Default: convert to title case
        return Str::title(str_replace('_', ' ', $property));
    }
}
