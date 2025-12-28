<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Illuminate\Console\Command;

/**
 * ValidateConfigCommand
 *
 * Validates entity configuration structure for the AI system.
 * Helps developers identify configuration issues before running the AI system.
 *
 * Usage:
 *   php artisan ai:config:validate
 *
 * @package Condoedge\Ai\Console\Commands
 */
class ValidateConfigCommand extends Command
{
    protected $signature = 'ai:config:validate';
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

        // Display results
        $this->displayResults();

        return empty($this->errors) ? self::SUCCESS : self::FAILURE;
    }

    private function validateEntityConfig(string $entityClass, array $config): void
    {
        $shortName = class_basename($entityClass);

        // Check required keys
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

        if (empty($config['vector'])) {
            $this->warnings[] = "{$shortName}: missing 'vector' configuration (entity won't be searchable)";
        } else {
            if (empty($config['vector']['collection'])) {
                $this->errors[] = "{$shortName}: missing vector collection name";
            }
            if (empty($config['vector']['embed_fields'])) {
                $this->warnings[] = "{$shortName}: no embed fields (entity won't be searchable by content)";
            }
        }

        // Check class exists
        if (!class_exists($entityClass)) {
            $this->errors[] = "{$shortName}: class does not exist ({$entityClass})";
        }
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
