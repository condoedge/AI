<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Database\Eloquent\Model;

/**
 * EmbedFieldDetector
 *
 * Detects which model fields are suitable for vector embeddings.
 * Identifies text columns that contain meaningful content for semantic
 * search while excluding IDs, foreign keys, and system fields.
 *
 * Usage:
 *   $detector = new EmbedFieldDetector($schemaInspector);
 *   $embedFields = $detector->detect($customer);
 *   // ['name', 'bio', 'notes']
 *
 * @package Condoedge\Ai\Services\Discovery
 */
class EmbedFieldDetector
{
    /**
     * Field patterns that indicate embeddable text
     */
    private const TEXT_FIELD_PATTERNS = [
        'description',
        'bio',
        'notes',
        'content',
        'body',
        'summary',
        'details',
        'comment',
        'message',
        'text',
        'remarks',
        'about',
        'overview',
        'introduction',
    ];

    /**
     * Patterns to exclude from embedding
     */
    private const EXCLUDE_PATTERNS = [
        '_id',
        '_at',
        '_on',
        'password',
        'token',
        'secret',
        'key',
        'hash',
        'salt',
        'uuid',
        'guid',
    ];

    /**
     * Create a new embed field detector
     *
     * @param SchemaInspector $schema Schema inspector for column types
     */
    public function __construct(
        private SchemaInspector $schema
    ) {}

    /**
     * Detect fields suitable for embeddings
     *
     * @param string|Model $model Model class name or instance
     * @return array List of field names suitable for embeddings
     */
    public function detect(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);

        try {
            $tableName = $modelInstance->getTable();
            if ($tableName === null) {
                return [];
            }
        } catch (\Throwable $e) {
            // Return empty if table not configured
            return [];
        }

        // Get text columns from schema
        $textColumns = $this->schema->getTextColumns($tableName);

        // Get all column types
        $columnTypes = $this->schema->getColumnTypes($tableName);

        // Filter columns that are suitable for embeddings
        $embedFields = [];

        foreach ($textColumns as $column) {
            // Check if column should be embedded
            if ($this->shouldEmbed($column, $columnTypes[$column] ?? 'string')) {
                $embedFields[] = $column;
            }
        }

        // Also check string columns with text-like names
        foreach ($columnTypes as $column => $type) {
            if ($type === 'string' && !in_array($column, $embedFields)) {
                if ($this->matchesTextPattern($column)) {
                    $embedFields[] = $column;
                }
            }
        }

        return array_values(array_unique($embedFields));
    }

    /**
     * Check if a column should be embedded
     *
     * @param string $column Column name
     * @param string $type Column type
     * @return bool True if should be embedded
     */
    private function shouldEmbed(string $column, string $type): bool
    {
        $columnLower = strtolower($column);

        // Exclude by pattern
        foreach (self::EXCLUDE_PATTERNS as $pattern) {
            if (str_contains($columnLower, $pattern)) {
                return false;
            }
        }

        // Must be a text-based type
        if (!in_array($type, ['text', 'longtext', 'mediumtext', 'string'])) {
            return false;
        }

        // Include by pattern
        if ($this->matchesTextPattern($column)) {
            return true;
        }

        // Default: don't embed unless explicitly matched
        return false;
    }

    /**
     * Check if column name matches text field patterns
     *
     * @param string $column Column name
     * @return bool True if matches pattern
     */
    private function matchesTextPattern(string $column): bool
    {
        $columnLower = strtolower($column);

        foreach (self::TEXT_FIELD_PATTERNS as $pattern) {
            if (str_contains($columnLower, $pattern)) {
                return true;
            }
        }

        return false;
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
}
