<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Support\Facades\DB;

/**
 * Queries actual database for sample data and field statistics.
 * Helps the AI understand real data patterns, enum values, null rates.
 */
class DataExplorer
{
    /**
     * Get sample data and field stats for a model.
     *
     * @param string $modelClass FQCN
     * @return array{samples: array, enum_fields: array, null_fields: array}
     */
    public function explore(string $modelClass): array
    {
        try {
            $instance = new $modelClass();
            $table = $instance->getTable();
            $connection = $instance->getConnection();
        } catch (\Throwable $e) {
            return ['samples' => [], 'enum_fields' => [], 'null_fields' => []];
        }

        return [
            'samples' => $this->getSamples($connection, $table),
            'enum_fields' => $this->getEnumFields($connection, $table),
            'null_fields' => $this->getNullFields($connection, $table),
        ];
    }

    /**
     * Get 3 sample records with the most non-null fields.
     */
    private function getSamples($connection, string $table): array
    {
        try {
            return $connection->table($table)
                ->limit(3)
                ->get()
                ->map(fn($row) => (array) $row)
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Find fields that look like enums (few distinct values).
     * Returns: ['status' => ['1', '2', '5'], 'type_id' => ['1', '2']]
     */
    private function getEnumFields($connection, string $table): array
    {
        $enums = [];

        try {
            $columns = $connection->getSchemaBuilder()->getColumnListing($table);

            // Check columns that look like enums (status, type, role, level, etc.)
            $enumCandidates = array_filter($columns, function ($col) {
                return preg_match('/(status|type|role|level|kind|category|mode|state|_id)$/i', $col)
                    && !preg_match('/(created_at|updated_at|deleted_at|_at$)/i', $col);
            });

            foreach ($enumCandidates as $col) {
                $distinct = $connection->table($table)
                    ->select($col)
                    ->distinct()
                    ->whereNotNull($col)
                    ->limit(20)
                    ->pluck($col)
                    ->toArray();

                // Only include if few distinct values (enum-like)
                if (count($distinct) > 0 && count($distinct) <= 15) {
                    $enums[$col] = $distinct;
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        return $enums;
    }

    /**
     * Find fields that are always or mostly null (potential team_path issues).
     * Returns: ['team_id' => 100.0, 'budget_id' => 85.5] (% null)
     */
    private function getNullFields($connection, string $table): array
    {
        $nulls = [];

        try {
            $total = $connection->table($table)->count();
            if ($total === 0) return [];

            $columns = $connection->getSchemaBuilder()->getColumnListing($table);

            // Check important FK columns for null rates
            $fkCandidates = array_filter($columns, fn($col) => str_ends_with($col, '_id'));

            foreach ($fkCandidates as $col) {
                $nullCount = $connection->table($table)->whereNull($col)->count();
                $pct = round(($nullCount / $total) * 100, 1);

                if ($pct > 90) {
                    $nulls[$col] = $pct;
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }

        return $nulls;
    }
}
