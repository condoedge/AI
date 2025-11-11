<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * AliasGenerator
 *
 * Generates semantic aliases from model and table names for better
 * query matching. Creates singular, plural, and common business term
 * variations to improve entity recognition in natural language queries.
 *
 * Usage:
 *   $generator = new AliasGenerator();
 *   $aliases = $generator->generate($customer);
 *   // ['customer', 'customers', 'client', 'buyer']
 *
 * @package Condoedge\Ai\Services\Discovery
 */
class AliasGenerator
{
    /**
     * Common business term mappings
     *
     * Maps entity names to their common business synonyms.
     */
    private const BUSINESS_TERMS = [
        'customer' => ['client', 'buyer', 'patron', 'account'],
        'user' => ['member', 'person', 'individual', 'account'],
        'order' => ['purchase', 'sale', 'transaction'],
        'product' => ['item', 'good', 'merchandise', 'article'],
        'invoice' => ['bill', 'statement', 'receipt'],
        'payment' => ['transaction', 'remittance'],
        'employee' => ['worker', 'staff', 'personnel', 'team member'],
        'company' => ['organization', 'business', 'enterprise', 'firm'],
        'address' => ['location', 'place'],
        'phone' => ['telephone', 'contact number', 'mobile'],
        'email' => ['e-mail', 'electronic mail', 'contact'],
        'category' => ['type', 'class', 'group'],
        'tag' => ['label', 'keyword'],
        'comment' => ['note', 'remark', 'feedback'],
        'post' => ['article', 'entry', 'content'],
        'file' => ['document', 'attachment', 'upload'],
        'image' => ['photo', 'picture', 'graphic'],
        'team' => ['group', 'department', 'division'],
        'role' => ['position', 'title', 'function'],
        'permission' => ['access', 'right', 'privilege'],
    ];

    /**
     * Generate aliases from model or table name
     *
     * Creates variations including singular, plural, and common
     * business terms.
     *
     * @param string|Model $model Model class name or instance
     * @return array List of unique aliases
     */
    public function generate(string|Model $model): array
    {
        $modelInstance = $this->resolveModel($model);
        $tableName = $modelInstance->getTable();
        $className = class_basename($model);

        // Remove 'Test' prefix if present
        if (str_starts_with($className, 'Test')) {
            $className = substr($className, 4);
        }

        $aliases = [];

        // Add inflections from table name
        $aliases = array_merge($aliases, $this->inflections($tableName));

        // Add inflections from class name
        $classBase = Str::snake($className);
        $aliases = array_merge($aliases, $this->inflections($classBase));

        // Add business terms
        $aliases = array_merge($aliases, $this->businessTerms($tableName));
        $aliases = array_merge($aliases, $this->businessTerms($classBase));

        // Remove duplicates and return
        return array_values(array_unique($aliases));
    }

    /**
     * Generate plural/singular inflections
     *
     * @param string $word Word to inflect
     * @return array Inflected forms
     */
    private function inflections(string $word): array
    {
        $word = strtolower($word);

        // Remove common table prefixes/suffixes
        $word = $this->normalizeWord($word);

        $singular = Str::singular($word);
        $plural = Str::plural($word);

        $inflections = [$singular, $plural];

        // Add variations
        $inflections[] = str_replace('_', ' ', $singular);
        $inflections[] = str_replace('_', ' ', $plural);

        // Add StudlyCase variations
        $inflections[] = Str::lower(Str::studly($singular));
        $inflections[] = Str::lower(Str::studly($plural));

        return array_unique($inflections);
    }

    /**
     * Get business terms for a word
     *
     * Looks up common business synonyms from the mapping.
     *
     * @param string $word Word to look up
     * @return array Business term synonyms
     */
    private function businessTerms(string $word): array
    {
        $word = strtolower($word);
        $word = $this->normalizeWord($word);

        // Check both singular and plural forms
        $singular = Str::singular($word);
        $plural = Str::plural($word);

        $terms = [];

        // Check if we have business terms for singular form
        if (isset(self::BUSINESS_TERMS[$singular])) {
            $terms = array_merge($terms, self::BUSINESS_TERMS[$singular]);

            // Add pluralized versions of business terms
            foreach (self::BUSINESS_TERMS[$singular] as $term) {
                $terms[] = Str::plural($term);
            }
        }

        // Check if we have business terms for plural form
        if (isset(self::BUSINESS_TERMS[$plural])) {
            $terms = array_merge($terms, self::BUSINESS_TERMS[$plural]);
        }

        return array_unique($terms);
    }

    /**
     * Normalize word by removing common prefixes/suffixes
     *
     * @param string $word Word to normalize
     * @return string Normalized word
     */
    private function normalizeWord(string $word): string
    {
        // Remove test_ prefix
        if (str_starts_with($word, 'test_')) {
            $word = substr($word, 5);
        }

        // Remove _tbl suffix
        if (str_ends_with($word, '_tbl')) {
            $word = substr($word, 0, -4);
        }

        // Remove _table suffix
        if (str_ends_with($word, '_table')) {
            $word = substr($word, 0, -6);
        }

        return $word;
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
     * Get label from model or table name
     *
     * Returns a StudlyCase label suitable for Neo4j.
     *
     * @param string|Model $model Model class name or instance
     * @return string Label
     */
    public function generateLabel(string|Model $model): string
    {
        $modelInstance = $this->resolveModel($model);
        $className = class_basename($model);

        // Remove 'Test' prefix if present
        if (str_starts_with($className, 'Test')) {
            $className = substr($className, 4);
        }

        // If model implements Nodeable, use getNodeLabel
        if (method_exists($modelInstance, 'getNodeLabel')) {
            return $modelInstance->getNodeLabel();
        }

        return $className;
    }

    /**
     * Get collection name for vector storage
     *
     * Returns a lowercase, underscored collection name.
     *
     * @param string|Model $model Model class name or instance
     * @return string Collection name
     */
    public function generateCollectionName(string|Model $model): string
    {
        $modelInstance = $this->resolveModel($model);

        // Use table name for collection (already pluralized)
        return $modelInstance->getTable();
    }
}
