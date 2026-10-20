<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * Gathers source code context for a model by tracing references
 * across the project: model class, migrations, Kompo forms/tables,
 * routes, and related models.
 */
class CodeExplorer
{
    /**
     * Gather all relevant source code for a model.
     *
     * @param string $modelClass FQCN of the model
     * @param string $basePath Project base path
     * @return array{model: string, migration: string, forms: array, routes: string, related_models: array}
     */
    public function explore(string $modelClass, string $basePath): array
    {
        $shortName = class_basename($modelClass);

        return [
            'model' => $this->readModelSource($modelClass),
            'migration' => $this->findMigration($modelClass, $basePath),
            'forms' => $this->findKompoComponents($modelClass, $basePath),
            'routes' => $this->findRoutes($shortName, $basePath),
            'related_models' => [], // Filled later by caller with relationship targets
        ];
    }

    /**
     * Read the model class source code.
     */
    private function readModelSource(string $modelClass): string
    {
        try {
            $reflection = new \ReflectionClass($modelClass);
            $filePath = $reflection->getFileName();
            if ($filePath && File::exists($filePath)) {
                return File::get($filePath);
            }
        } catch (\Throwable $e) {
            // Class may not be reflectable
        }

        return '';
    }

    /**
     * Find the migration file for this model's table.
     */
    private function findMigration(string $modelClass, string $basePath): string
    {
        try {
            $instance = new $modelClass();
            $table = $instance->getTable();
        } catch (\Throwable $e) {
            return '';
        }

        $migrationsPath = $basePath . '/database/migrations';
        if (!File::isDirectory($migrationsPath)) {
            return '';
        }

        // Search for migration creating this table
        $finder = new Finder();
        $finder->files()->in($migrationsPath)->name("*{$table}*")->sortByName();

        foreach ($finder as $file) {
            $content = $file->getContents();
            if (str_contains($content, "'{$table}'") || str_contains($content, "\"{$table}\"")) {
                return $content;
            }
        }

        return '';
    }

    /**
     * Find Kompo Form/Table/Query/Modal classes that reference this model.
     * Project-agnostic: searches by class extension pattern, not directory.
     */
    private function findKompoComponents(string $modelClass, string $basePath): array
    {
        $shortName = class_basename($modelClass);
        $appPath = $basePath . '/app';

        if (!File::isDirectory($appPath)) {
            return [];
        }

        $components = [];

        try {
            $finder = new Finder();
            $finder->files()->in($appPath)->name('*.php')->contains($shortName);

            foreach ($finder as $file) {
                $content = $file->getContents();

                // Must extend a Kompo base class
                $isKompo = preg_match('/extends\s+(Form|Table|Query|Modal|Menu)\b/i', $content);
                if (!$isKompo) {
                    continue;
                }

                // Must reference this model (class name or ::class)
                $referencesModel = str_contains($content, "{$shortName}::class")
                    || str_contains($content, "\\{$shortName}")
                    || str_contains($content, "\$model = {$shortName}");

                if (!$referencesModel) {
                    continue;
                }

                $components[] = [
                    'path' => $file->getRelativePathname(),
                    'content' => $content,
                ];

                // Limit to 5 most relevant forms to keep prompt size reasonable
                if (count($components) >= 5) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Finder errors are non-fatal
        }

        return $components;
    }

    /**
     * Find route definitions that reference this model or its components.
     */
    private function findRoutes(string $shortName, string $basePath): string
    {
        $routesPath = $basePath . '/routes';
        if (!File::isDirectory($routesPath)) {
            return '';
        }

        $relevantLines = [];

        $finder = new Finder();
        $finder->files()->in($routesPath)->name('*.php');

        foreach ($finder as $file) {
            $lines = explode("\n", $file->getContents());
            $nameLower = strtolower($shortName);

            foreach ($lines as $line) {
                if (str_contains(strtolower($line), $nameLower)) {
                    $relevantLines[] = trim($line);
                }
            }
        }

        return implode("\n", array_slice($relevantLines, 0, 20));
    }

    /**
     * Read a related model's source code (for relationship context).
     */
    public function readRelatedModel(string $modelClass): string
    {
        return $this->readModelSource($modelClass);
    }
}
