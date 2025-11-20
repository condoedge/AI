<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

/**
 * Schema Inspector
 *
 * Extracts strategic hints from Laravel database schema for auto-discovery.
 * Uses Laravel's Schema facade to analyze table structure, foreign keys,
 * text columns suitable for embeddings, and indexed columns.
 *
 * **Key Features:**
 * - Foreign key detection (both constraints and *_id patterns)
 * - Text column detection for embedding candidates
 * - Indexed column detection for important properties
 * - Column type mapping
 * - Intelligent caching (1 hour TTL)
 *
 * **Usage:**
 * ```php
 * $inspector = new SchemaInspector();
 *
 * // Get foreign keys
 * $fks = $inspector->getForeignKeys('users');
 * // ['team_id' => ['table' => 'teams', 'column' => 'id']]
 *
 * // Get text columns suitable for embeddings
 * $textCols = $inspector->getTextColumns('users');
 * // ['bio', 'description', 'notes']
 *
 * // Get indexed columns (likely important)
 * $indexed = $inspector->getIndexedColumns('users');
 * // ['email', 'status']
 *
 * // Get all column types
 * $types = $inspector->getColumnTypes('users');
 * // ['id' => 'integer', 'name' => 'string', 'bio' => 'text']
 * ```
 *
 * **Caching:**
 * Results are cached with key `ai.schema.{table}` for 1 hour.
 * Schema rarely changes, so this significantly improves performance.
 *
 * @package Condoedge\Ai\Services\Discovery
 */
class SchemaInspector
{
    /**
     * Cache TTL in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * Column name patterns that typically contain embeddable text
     */
    private const TEXT_COLUMN_PATTERNS = [
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
    ];

    /**
     * Database column types that can contain embeddable text
     */
    private const TEXT_COLUMN_TYPES = [
        'text',
        'longtext',
        'mediumtext',
        'tinytext',
    ];

    /**
     * Get foreign key columns from a table
     *
     * Detects foreign keys through:
     * 1. Actual foreign key constraints (database-specific)
     * 2. Column naming convention (*_id pattern)
     *
     * @param string $table Table name
     * @return array<string, array{table: string, column: string}> Foreign key mappings
     *
     * @example
     * ```php
     * $fks = $inspector->getForeignKeys('orders');
     * // [
     * //     'customer_id' => ['table' => 'customers', 'column' => 'id'],
     * //     'product_id' => ['table' => 'products', 'column' => 'id']
     * // ]
     * ```
     */
    public function getForeignKeys(string $table): array
    {
        return $this->cached($table, 'foreign_keys', function () use ($table) {
            $foreignKeys = [];

            // Get foreign keys from database constraints
            $constraintFks = $this->getForeignKeyConstraints($table);
            $foreignKeys = array_merge($foreignKeys, $constraintFks);

            // Also detect *_id pattern columns that might be foreign keys
            $columns = Schema::getColumnListing($table);
            foreach ($columns as $column) {
                if (str_ends_with($column, '_id') && !isset($foreignKeys[$column])) {
                    // Infer foreign table from column name
                    // e.g., 'customer_id' -> 'customers', 'team_id' -> 'teams'
                    $baseName = substr($column, 0, -3); // Remove '_id'
                    $inferredTable = $this->pluralize($baseName);

                    // Only add if inferred table exists
                    if (Schema::hasTable($inferredTable)) {
                        $foreignKeys[$column] = [
                            'table' => $inferredTable,
                            'column' => 'id',
                        ];
                    }
                }
            }

            return $foreignKeys;
        });
    }

    /**
     * Get text/longtext columns suitable for embeddings
     *
     * Detects columns by:
     * 1. Column type (text, longtext, mediumtext)
     * 2. Column name patterns (description, bio, notes, content, etc.)
     *
     * @param string $table Table name
     * @return array<int, string> List of text column names
     *
     * @example
     * ```php
     * $textCols = $inspector->getTextColumns('products');
     * // ['description', 'details', 'notes']
     * ```
     */
    public function getTextColumns(string $table): array
    {
        return $this->cached($table, 'text_columns', function () use ($table) {
            $textColumns = [];
            $columns = Schema::getColumnListing($table);

            foreach ($columns as $column) {
                // Check by column type
                $type = strtolower(Schema::getColumnType($table, $column));
                if (in_array($type, self::TEXT_COLUMN_TYPES, true)) {
                    $textColumns[] = $column;
                    continue;
                }

                // Check by column name pattern for string types
                if ($type === 'string') {
                    $columnLower = strtolower($column);
                    foreach (self::TEXT_COLUMN_PATTERNS as $pattern) {
                        if (str_contains($columnLower, $pattern)) {
                            $textColumns[] = $column;
                            break;
                        }
                    }
                }
            }

            return array_unique($textColumns);
        });
    }

    /**
     * Get indexed columns (likely important properties)
     *
     * Indexed columns are typically important for queries and business logic.
     * Excludes primary keys as they're already known to be important.
     *
     * @param string $table Table name
     * @return array<int, string> List of indexed column names
     *
     * @example
     * ```php
     * $indexed = $inspector->getIndexedColumns('users');
     * // ['email', 'status', 'created_at']
     * ```
     */
    public function getIndexedColumns(string $table): array
    {
        return $this->cached($table, 'indexed_columns', function () use ($table) {
            $indexes = $this->getTableIndexes($table);
            $indexedColumns = [];

            foreach ($indexes as $index) {
                // Skip primary key indexes
                if (isset($index['primary']) && $index['primary']) {
                    continue;
                }

                // Add columns from this index
                if (isset($index['columns'])) {
                    foreach ($index['columns'] as $column) {
                        $indexedColumns[] = $column;
                    }
                }
            }

            return array_unique($indexedColumns);
        });
    }

    /**
     * Get all column types
     *
     * Returns a mapping of column names to their types.
     * Types are normalized Laravel types (integer, string, text, boolean, etc.)
     *
     * @param string $table Table name
     * @return array<string, string> Column name to type mappings
     *
     * @example
     * ```php
     * $types = $inspector->getColumnTypes('users');
     * // [
     * //     'id' => 'integer',
     * //     'name' => 'string',
     * //     'email' => 'string',
     * //     'bio' => 'text',
     * //     'active' => 'boolean'
     * // ]
     * ```
     */
    public function getColumnTypes(string $table): array
    {
        return $this->cached($table, 'column_types', function () use ($table) {
            $columns = Schema::getColumnListing($table);
            $types = [];

            foreach ($columns as $column) {
                $types[$column] = Schema::getColumnType($table, $column);
            }

            return $types;
        });
    }

    /**
     * Get foreign key constraints from database
     *
     * Database-specific implementation for retrieving actual foreign key constraints.
     *
     * @param string $table Table name
     * @return array<string, array{table: string, column: string}> Foreign key mappings
     */
    private function getForeignKeyConstraints(string $table): array
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'mysql' => $this->getMysqlForeignKeys($table),
            'pgsql' => $this->getPostgresForeignKeys($table),
            'sqlite' => $this->getSqliteForeignKeys($table),
            default => [],
        };
    }

    /**
     * Get foreign keys for MySQL
     *
     * @param string $table Table name
     * @return array<string, array{table: string, column: string}>
     */
    private function getMysqlForeignKeys(string $table): array
    {
        $database = DB::connection()->getDatabaseName();
        $foreignKeys = [];

        $constraints = DB::select(
            "SELECT
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM
                INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE
                TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$database, $table]
        );

        foreach ($constraints as $constraint) {
            $foreignKeys[$constraint->COLUMN_NAME] = [
                'table' => $constraint->REFERENCED_TABLE_NAME,
                'column' => $constraint->REFERENCED_COLUMN_NAME,
            ];
        }

        return $foreignKeys;
    }

    /**
     * Get foreign keys for PostgreSQL
     *
     * @param string $table Table name
     * @return array<string, array{table: string, column: string}>
     */
    private function getPostgresForeignKeys(string $table): array
    {
        $foreignKeys = [];

        $constraints = DB::select(
            "SELECT
                kcu.column_name,
                ccu.table_name AS foreign_table_name,
                ccu.column_name AS foreign_column_name
            FROM
                information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu
                  ON tc.constraint_name = kcu.constraint_name
                  AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage AS ccu
                  ON ccu.constraint_name = tc.constraint_name
                  AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
                AND tc.table_name = ?",
            [$table]
        );

        foreach ($constraints as $constraint) {
            $foreignKeys[$constraint->column_name] = [
                'table' => $constraint->foreign_table_name,
                'column' => $constraint->foreign_column_name,
            ];
        }

        return $foreignKeys;
    }

    /**
     * Get foreign keys for SQLite
     *
     * @param string $table Table name
     * @return array<string, array{table: string, column: string}>
     */
    private function getSqliteForeignKeys(string $table): array
    {
        $foreignKeys = [];

        // Validate table name to prevent SQL injection
        $table = $this->sanitizeTableName($table);

        // SQLite uses PRAGMA foreign_key_list
        // Note: PRAGMA doesn't support parameter binding, so we validate the table name
        $constraints = DB::select("PRAGMA foreign_key_list({$table})");

        foreach ($constraints as $constraint) {
            $foreignKeys[$constraint->from] = [
                'table' => $constraint->table,
                'column' => $constraint->to,
            ];
        }

        return $foreignKeys;
    }

    /**
     * Get table indexes
     *
     * Database-specific implementation for retrieving table indexes.
     *
     * @param string $table Table name
     * @return array<int, array{name: string, columns: array<int, string>, primary: bool, unique: bool}>
     */
    private function getTableIndexes(string $table): array
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'mysql' => $this->getMysqlIndexes($table),
            'pgsql' => $this->getPostgresIndexes($table),
            'sqlite' => $this->getSqliteIndexes($table),
            default => [],
        };
    }

    /**
     * Get indexes for MySQL
     *
     * @param string $table Table name
     * @return array<int, array{name: string, columns: array<int, string>, primary: bool, unique: bool}>
     */
    private function getMysqlIndexes(string $table): array
    {
        $database = DB::connection()->getDatabaseName();
        $indexes = [];

        $rawIndexes = DB::select(
            "SELECT
                INDEX_NAME,
                COLUMN_NAME,
                NON_UNIQUE
            FROM
                INFORMATION_SCHEMA.STATISTICS
            WHERE
                TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
            ORDER BY
                INDEX_NAME, SEQ_IN_INDEX",
            [$database, $table]
        );

        foreach ($rawIndexes as $index) {
            $name = $index->INDEX_NAME;

            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'primary' => $name === 'PRIMARY',
                    'unique' => $index->NON_UNIQUE == 0,
                ];
            }

            $indexes[$name]['columns'][] = $index->COLUMN_NAME;
        }

        return array_values($indexes);
    }

    /**
     * Get indexes for PostgreSQL
     *
     * @param string $table Table name
     * @return array<int, array{name: string, columns: array<int, string>, primary: bool, unique: bool}>
     */
    private function getPostgresIndexes(string $table): array
    {
        $indexes = [];

        $rawIndexes = DB::select(
            "SELECT
                i.relname AS index_name,
                a.attname AS column_name,
                ix.indisprimary AS is_primary,
                ix.indisunique AS is_unique
            FROM
                pg_class t,
                pg_class i,
                pg_index ix,
                pg_attribute a
            WHERE
                t.oid = ix.indrelid
                AND i.oid = ix.indexrelid
                AND a.attrelid = t.oid
                AND a.attnum = ANY(ix.indkey)
                AND t.relkind = 'r'
                AND t.relname = ?
            ORDER BY
                i.relname,
                a.attnum",
            [$table]
        );

        foreach ($rawIndexes as $index) {
            $name = $index->index_name;

            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'primary' => (bool) $index->is_primary,
                    'unique' => (bool) $index->is_unique,
                ];
            }

            $indexes[$name]['columns'][] = $index->column_name;
        }

        return array_values($indexes);
    }

    /**
     * Get indexes for SQLite
     *
     * @param string $table Table name
     * @return array<int, array{name: string, columns: array<int, string>, primary: bool, unique: bool}>
     */
    private function getSqliteIndexes(string $table): array
    {
        $indexes = [];

        // Validate table name to prevent SQL injection
        $table = $this->sanitizeTableName($table);

        // Get all indexes for the table
        $indexList = DB::select("PRAGMA index_list({$table})");

        foreach ($indexList as $indexInfo) {
            $indexName = $indexInfo->name;

            // Validate index name to prevent SQL injection
            $indexName = $this->sanitizeTableName($indexName);

            // Get columns for this index
            $indexColumns = DB::select("PRAGMA index_info({$indexName})");

            $columns = [];
            foreach ($indexColumns as $column) {
                $columns[] = $column->name;
            }

            $indexes[] = [
                'name' => $indexName,
                'columns' => $columns,
                'primary' => str_contains($indexName, 'PRIMARY') || $indexInfo->origin === 'pk',
                'unique' => (bool) $indexInfo->unique,
            ];
        }

        return $indexes;
    }

    /**
     * Sanitize table/index name to prevent SQL injection
     *
     * Validates that the name contains only alphanumeric characters and underscores.
     * This is necessary for PRAGMA queries which don't support parameter binding.
     *
     * @param string $name Table or index name
     * @return string Sanitized name
     * @throws \InvalidArgumentException If name contains invalid characters
     */
    private function sanitizeTableName(string $name): string
    {
        // Allow only alphanumeric characters, underscores, and hyphens
        // This matches typical database identifier rules
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid table/index name '{$name}': must contain only alphanumeric characters, underscores, and hyphens"
            );
        }

        // Additional length check to prevent DoS
        if (strlen($name) > 64) {
            throw new \InvalidArgumentException(
                "Table/index name too long (max 64 characters): '{$name}'"
            );
        }

        return $name;
    }

    /**
     * Simple pluralization helper
     *
     * Converts singular table names to plural (e.g., 'customer' -> 'customers').
     * Uses Laravel's Str::plural() if available, otherwise basic heuristics.
     *
     * @param string $singular Singular word
     * @return string Plural word
     */
    private function pluralize(string $singular): string
    {
        // Use Laravel's Str::plural if available
        if (class_exists(\Illuminate\Support\Str::class)) {
            return \Illuminate\Support\Str::plural($singular);
        }

        // Fallback to simple pluralization
        if (str_ends_with($singular, 'y')) {
            return substr($singular, 0, -1) . 'ies';
        }

        if (str_ends_with($singular, 's')) {
            return $singular . 'es';
        }

        return $singular . 's';
    }

    /**
     * Cache helper
     *
     * Caches inspection results per table to avoid repeated schema queries.
     * Cache key format: `ai.schema.{table}.{type}`
     *
     * @param string $table Table name
     * @param string $type Data type (foreign_keys, text_columns, etc.)
     * @param callable $callback Callback to generate data if not cached
     * @return mixed Cached or freshly generated data
     */
    private function cached(string $table, string $type, callable $callback): mixed
    {
        $cacheKey = "ai.schema.{$table}.{$type}";
        $cacheTtl = config('ai.schema_cache_ttl', self::CACHE_TTL);

        return Cache::remember($cacheKey, $cacheTtl, $callback);
    }

    /**
     * Clear cache for a specific table
     *
     * Useful when schema changes are made and you need to refresh the cache.
     *
     * @param string $table Table name
     * @return void
     */
    public function clearCache(string $table): void
    {
        $types = ['foreign_keys', 'text_columns', 'indexed_columns', 'column_types'];

        foreach ($types as $type) {
            Cache::forget("ai.schema.{$table}.{$type}");
        }
    }

    /**
     * Clear all schema caches
     *
     * Clears all cached schema inspection data.
     *
     * @return void
     */
    public function clearAllCaches(): void
    {
        // Get all tables using Schema facade
        $tables = $this->getAllTables();

        foreach ($tables as $table) {
            $this->clearCache($table);
        }
    }

    /**
     * Get all table names in the database
     *
     * @return array<int, string>
     */
    private function getAllTables(): array
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'mysql' => $this->getMysqlTables(),
            'pgsql' => $this->getPostgresTables(),
            'sqlite' => $this->getSqliteTables(),
            default => [],
        };
    }

    /**
     * Get all table names for MySQL
     *
     * @return array<int, string>
     */
    private function getMysqlTables(): array
    {
        $database = DB::connection()->getDatabaseName();
        $tables = DB::select("SHOW TABLES");
        $key = "Tables_in_{$database}";

        return array_map(fn($table) => $table->$key, $tables);
    }

    /**
     * Get all table names for PostgreSQL
     *
     * @return array<int, string>
     */
    private function getPostgresTables(): array
    {
        $tables = DB::select(
            "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'"
        );

        return array_map(fn($table) => $table->tablename, $tables);
    }

    /**
     * Get all table names for SQLite
     *
     * @return array<int, string>
     */
    private function getSqliteTables(): array
    {
        $tables = DB::select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
        );

        return array_map(fn($table) => $table->name, $tables);
    }
}
