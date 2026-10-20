<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Services\GraphPropertyMapper;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * ValidateConfigCommand
 *
 * Validates entity configuration structure for the AI system.
 * Helps developers identify configuration issues before running the AI system.
 *
 * Usage:
 *   php artisan ai:validate-config
 *
 * @package Condoedge\Ai\Console\Commands
 */
class ValidateConfigCommand extends Command
{
    protected $signature = 'ai:validate-config';
    protected $description = 'Validate entity configuration';

    private array $errors = [];
    private array $warnings = [];

    public function handle(): int
    {
        $this->info('Validating AI configuration...');
        $this->newLine();

        $entities = config('entities', []);

        if (empty($entities)) {
            $this->error('No entities configured. Run: php artisan ai:discover');
            return self::FAILURE;
        }

        foreach ($entities as $entityClass => $config) {
            $this->validateEntityConfig($entityClass, $config);
        }

        $this->displayResults();

        return empty($this->errors) ? self::SUCCESS : self::FAILURE;
    }

    private function validateEntityConfig(string $entityClass, array $config): void
    {
        $shortName = class_basename($entityClass);

        if (empty($config['graph'])) {
            $this->errors[] = "{$shortName}: missing 'graph' configuration";
        } else {
            if (empty($config['graph']['label'])) {
                $this->errors[] = "{$shortName}: missing graph label";
            }
            if (empty($config['graph']['properties'])) {
                $this->warnings[] = "{$shortName}: no properties configured";
            }
        }

        if (!class_exists($entityClass)) {
            $this->errors[] = "{$shortName}: class does not exist ({$entityClass})";
        } elseif (is_array($config['graph'] ?? null)) {
            $this->validateComputedGraphConfig($entityClass, $shortName, $config['graph']);
        }
    }

    /**
     * graph.computed / graph.with / graph.casts: the accessor, method or relation must
     * exist on the model class, and cast types must be known. Warnings only — ingest
     * skips a failing computed value per node instead of failing.
     */
    private function validateComputedGraphConfig(string $entityClass, string $shortName, array $graph): void
    {
        foreach ((array) ($graph['computed'] ?? []) as $property => $source) {
            if (is_string($source)) {
                if (!$this->hasAccessorOrMethod($entityClass, $source)) {
                    $this->warnings[] = "{$shortName}: computed '{$property}' => '{$source}': no method or accessor "
                        . "named '{$source}' on {$entityClass} (a plain column belongs in graph.properties)";
                }
            } elseif (!is_callable($source)) {
                $this->warnings[] = "{$shortName}: computed '{$property}' must be an accessor/method name or a callable";
            }
        }

        foreach ((array) ($graph['with'] ?? []) as $relation) {
            if (!is_string($relation) || ($problem = $this->relationPathProblem($entityClass, $relation)) === null) {
                continue;
            }
            $this->warnings[] = "{$shortName}: graph.with '{$relation}': {$problem}";
        }

        $knownCasts = array_merge(GraphPropertyMapper::CAST_TYPES, array_keys(GraphPropertyMapper::CAST_ALIASES));
        foreach ((array) ($graph['casts'] ?? []) as $property => $type) {
            if (!is_string($type) || !in_array(strtolower($type), $knownCasts, true)) {
                $this->warnings[] = "{$shortName}: graph.casts '{$property}' has unknown type '"
                    . (is_string($type) ? $type : get_debug_type($type)) . "' (expected: "
                    . implode('|', GraphPropertyMapper::CAST_TYPES) . ')';
            }
        }
    }

    /**
     * A computed source is valid when the class has a method of that name, a legacy
     * accessor (getFooBarAttribute) or a new-style Attribute accessor (fooBar()).
     */
    private function hasAccessorOrMethod(string $class, string $name): bool
    {
        return method_exists($class, $name)
            || method_exists($class, 'get' . Str::studly($name) . 'Attribute')
            || method_exists($class, Str::camel($name));
    }

    /**
     * Walk a dotted eager-load path (e.g. 'invoiceDetails.taxDetails'). Returns a
     * problem description, or null when every segment is a relation (or cannot be
     * verified without a database).
     */
    private function relationPathProblem(string $class, string $path): ?string
    {
        foreach (explode('.', $path) as $segment) {
            $segment = explode(':', $segment, 2)[0]; // 'relation:col1,col2' column constraint

            if (!method_exists($class, $segment)) {
                return "no relation method '{$segment}' on {$class}";
            }

            try {
                $relation = (new $class)->{$segment}();
            } catch (\Throwable $e) {
                return null; // cannot verify deeper without a working model/DB
            }

            if (!$relation instanceof Relation) {
                return "'{$segment}' on {$class} is not an Eloquent relation";
            }

            $class = get_class($relation->getRelated());
        }

        return null;
    }

    private function displayResults(): void
    {
        if (!empty($this->errors)) {
            $this->error('Errors:');
            foreach ($this->errors as $error) {
                $this->line("  <fg=red>x</> {$error}");
            }
            $this->newLine();
        }

        if (!empty($this->warnings)) {
            $this->warn('Warnings:');
            foreach ($this->warnings as $warning) {
                $this->line("  <fg=yellow>!</> {$warning}");
            }
            $this->newLine();
        }

        if (empty($this->errors) && empty($this->warnings)) {
            $this->info('All configurations are valid!');
        }
    }
}
