# AI-Enhanced Entity Discovery Implementation Plan

> **Status: IMPLEMENTED** (2026-03-19)

**Goal:** Zero-config entity configuration — after running `ai:discover` and `ai:enhance`, the system should understand the entire project well enough to answer any question without manual config tuning.

**Architecture — Two commands:**

1. **`ai:discover --enhance`** — Single-pass enhancement during discovery (quick, no feedback loop)
2. **`ai:enhance`** — Post-ingestion iterative improvement. The main tool for quality. Runs AFTER discover + ingest + index so the full query pipeline is available. For each entity: generates test questions → runs them through `ai:test-question` → feeds results back to LLM → repeats 3 times.

**Tech Stack:** PHP 8.1+, Laravel, existing `LlmProviderInterface`, Symfony Finder for file discovery, existing `EntityAutoDiscovery` pipeline.

---

## How it works at a glance

```
1. ai:discover --enhance  (OR ai:discover then ai:enhance separately)
   │
   ├─ Step 1: Normal discovery (existing pipeline)
   │   └─ PropertyDiscoverer, RelationshipDiscoverer, AliasGenerator, etc.
   │
   ├─ Step 2: AI Enhancement (per entity)
   │   ├─ CodeExplorer: Gathers files related to the entity
   │   │   ├─ Model class source code
   │   │   ├─ Migration file
   │   │   ├─ Kompo Form/Table/Query classes (grep for extends + model reference)
   │   │   ├─ Route definitions
   │   │   └─ Related model classes (from relationships)
   │   │
   │   ├─ DataExplorer: Queries actual database
   │   │   ├─ 3 sample records
   │   │   └─ Distinct values for enum-like fields (status, type, role)
   │   │
   │   └─ LLM Enhancement: Sends context + discovered config → gets enriched config
   │       ├─ Rich description with relationship direction + user path hints
   │       ├─ Better aliases (domain-specific, bilingual, possessive forms)
   │       ├─ Property descriptions with enum values
   │       ├─ Scope examples matching real user questions (EN + FR)
   │       └─ Security team_path validation
   │
   └─ Step 3: Merge + Write config files (with backup)

2. ai:enhance --iterations=3  (POST-INGESTION, the key step)
   │
   └─ For each entity, 3 rounds of:
       ├─ LLM generates 5 test questions (possessive, relationship, creative)
       ├─ Questions run through ai:test-question pipeline
       ├─ LLM analyzes results: what worked, what failed, WHY
       └─ LLM improves config based on evidence
```

---

## Tasks

### Task 1: Create CodeExplorer service

**Files:**
- Create: `src/Services/Discovery/CodeExplorer.php`

The CodeExplorer gathers all relevant source files for a given model class. It traces outward from the model using grep, not hardcoded paths.

**Step 1: Create the service**

```php
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
        $pattern = "create_{$table}_table";
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
```

**Step 2: Verify**

Run: `cd "C:/Users/jkend/Documents/Projects/kompo/CondoEdge" && php artisan tinker --execute="...test CodeExplorer..."`

---

### Task 2: Create DataExplorer service

**Files:**
- Create: `src/Services/Discovery/DataExplorer.php`

Queries sample data and field statistics for an entity.

**Step 1: Create the service**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Database\Eloquent\Model;
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
```

---

### Task 3: Create AiConfigEnhancer service

**Files:**
- Create: `src/Services/Discovery/AiConfigEnhancer.php`

The core service. Takes discovered config + code context + data context → sends to LLM → returns enriched config.

**Step 1: Create the service**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Condoedge\Ai\Contracts\LlmProviderInterface;

/**
 * Enhances auto-discovered entity configs using LLM analysis.
 *
 * For each entity, it receives:
 * - The raw discovered config (properties, relationships, scopes)
 * - Source code context (model, Kompo forms, migration, routes)
 * - Data context (sample records, enum values, null rates)
 *
 * And produces an enriched config with:
 * - Rich description with relationship direction hints and JOIN guidance
 * - Better aliases (domain-specific, bilingual EN/FR)
 * - Property descriptions with enum value documentation
 * - Scope examples matching real user questions
 * - Security team_path validation notes
 */
class AiConfigEnhancer
{
    public function __construct(
        private readonly LlmProviderInterface $llm
    ) {}

    /**
     * Enhance a single entity's config.
     *
     * @param string $entityClass FQCN
     * @param array $discoveredConfig Raw discovered config
     * @param array $codeContext From CodeExplorer
     * @param array $dataContext From DataExplorer
     * @return array Enhanced config (same structure, richer content)
     */
    public function enhance(
        string $entityClass,
        array $discoveredConfig,
        array $codeContext,
        array $dataContext
    ): array {
        $prompt = $this->buildPrompt($entityClass, $discoveredConfig, $codeContext, $dataContext);

        $response = $this->llm->chatJson([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'temperature' => 0.1,
            'max_tokens' => 4000,
        ]);

        $enhanced = is_object($response) ? (array) $response : $response;

        return $this->mergeEnhanced($discoveredConfig, $enhanced);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an expert at configuring AI-powered natural language query systems for Laravel applications. You analyze source code deeply and generate configuration that helps an LLM convert user questions into Neo4j Cypher queries.

You will receive:
1. A model's discovered configuration (properties, relationships, scopes)
2. The model's PHP source code and related files (Kompo forms/tables, migrations, routes)
3. Sample data and field statistics

Generate a JSON object with these keys:
- "description": A rich 2-3 sentence description explaining what this entity is, how it relates to other entities, and CRITICAL: include relationship direction hints for Neo4j traversal. Mention key JOINs needed to get human-readable data.
- "aliases": Array of 10-15 aliases in both English and French. Include singular, plural, common abbreviations, and domain-specific terms found in the UI labels.
- "common_properties": Object mapping property names to descriptions. For enum fields, ALWAYS document the values: "status: Status (1=Draft, 2=Approved, 3=Sent)". For date fields stored as strings, note: "stored as string, compare with string operators". For FK fields, explain what they point to.
- "scopes": Object of scope definitions. For each scope, include: specification_type, concept, cypher_pattern (MUST use correct Neo4j syntax — dates as strings use toString(date())), filter, and 3-5 examples as natural questions users would ask in BOTH English and French.
- "security_notes": Any notes about team_path issues (e.g., "team_id column is always null, should use union.team_id instead").

Rules:
- Read the Kompo form labels to understand what users call each field
- Read the model's enum/constant methods to document exact values
- Read relationships to understand Neo4j direction (belongsTo = outgoing from child)
- Scope cypher_patterns MUST use valid Neo4j syntax: string dates use toString(date()), integers are NOT quoted
- Aliases should include terms from Kompo form labels and route names
- Return ONLY valid JSON
PROMPT;
    }

    private function buildPrompt(
        string $entityClass,
        array $discoveredConfig,
        array $codeContext,
        array $dataContext
    ): string {
        $label = $discoveredConfig['graph']['label'] ?? class_basename($entityClass);
        $prompt = "# Entity: {$label} ({$entityClass})\n\n";

        // Discovered config
        $prompt .= "## Discovered Configuration\n";
        $prompt .= "```json\n" . json_encode($discoveredConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```\n\n";

        // Model source
        if (!empty($codeContext['model'])) {
            $prompt .= "## Model Source Code\n```php\n" . $this->truncate($codeContext['model'], 3000) . "\n```\n\n";
        }

        // Migration
        if (!empty($codeContext['migration'])) {
            $prompt .= "## Migration\n```php\n" . $this->truncate($codeContext['migration'], 2000) . "\n```\n\n";
        }

        // Kompo forms (most important — contains UI labels)
        foreach ($codeContext['forms'] ?? [] as $form) {
            $prompt .= "## Kompo Component: {$form['path']}\n```php\n" . $this->truncate($form['content'], 2000) . "\n```\n\n";
        }

        // Routes
        if (!empty($codeContext['routes'])) {
            $prompt .= "## Routes\n```\n{$codeContext['routes']}\n```\n\n";
        }

        // Sample data
        if (!empty($dataContext['samples'])) {
            $prompt .= "## Sample Data (3 records)\n```json\n" . json_encode(array_slice($dataContext['samples'], 0, 3), JSON_PRETTY_PRINT) . "\n```\n\n";
        }

        // Enum fields
        if (!empty($dataContext['enum_fields'])) {
            $prompt .= "## Enum Field Values (from DB)\n```json\n" . json_encode($dataContext['enum_fields'], JSON_PRETTY_PRINT) . "\n```\n\n";
        }

        // Null rate warnings
        if (!empty($dataContext['null_fields'])) {
            $prompt .= "## WARNING: These FK columns are >90% null in DB\n";
            foreach ($dataContext['null_fields'] as $col => $pct) {
                $prompt .= "- {$col}: {$pct}% null\n";
            }
            $prompt .= "\n";
        }

        // Related model summaries (short)
        foreach ($codeContext['related_models'] ?? [] as $relClass => $source) {
            if (!empty($source)) {
                $prompt .= "## Related Model: " . class_basename($relClass) . "\n```php\n" . $this->truncate($source, 1000) . "\n```\n\n";
            }
        }

        return $prompt;
    }

    /**
     * Merge LLM-enhanced config back into discovered config.
     * LLM values override discovered values for metadata fields.
     * Graph/vector/security structure is preserved from discovery.
     */
    private function mergeEnhanced(array $discovered, array $enhanced): array
    {
        // Only override metadata fields from LLM
        if (!empty($enhanced['description'])) {
            $discovered['metadata']['description'] = $enhanced['description'];
        }
        if (!empty($enhanced['aliases'])) {
            $discovered['metadata']['aliases'] = $enhanced['aliases'];
        }
        if (!empty($enhanced['common_properties'])) {
            $discovered['metadata']['common_properties'] = $enhanced['common_properties'];
        }
        if (!empty($enhanced['scopes'])) {
            // Merge: keep discovered scopes, add/override with LLM scopes
            $discovered['metadata']['scopes'] = array_merge(
                $discovered['metadata']['scopes'] ?? [],
                $enhanced['scopes']
            );
        }

        return $discovered;
    }

    private function truncate(string $text, int $maxChars): string
    {
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, $maxChars) . "\n// ... truncated ...";
    }
}
```

---

### Task 4: Add `--enhance` flag to DiscoverEntitiesCommand

**Files:**
- Modify: `src/Console/Commands/DiscoverEntitiesCommand.php`

**Step 1: Add the flag and enhancement loop**

Add `{--enhance : Use AI to generate rich descriptions, aliases, and scopes}` to the signature.

After the existing discovery loop (line ~160), add:

```php
if ($this->option('enhance')) {
    $this->newLine();
    $this->info('Running AI enhancement...');

    $enhancer = app(AiConfigEnhancer::class);
    $codeExplorer = new CodeExplorer();
    $dataExplorer = new DataExplorer();

    $bar = $this->output->createProgressBar(count($configurations));

    foreach ($configurations as $modelClass => &$config) {
        $bar->setMessage("Enhancing: " . class_basename($modelClass));
        $bar->advance();

        try {
            // Gather code context
            $codeContext = $codeExplorer->explore($modelClass, base_path());

            // Add related model sources
            foreach ($config['graph']['relationships'] ?? [] as $rel) {
                $relatedModel = $rel['related_model'] ?? null;
                if ($relatedModel && class_exists($relatedModel)) {
                    $codeContext['related_models'][$relatedModel] =
                        $codeExplorer->readRelatedModel($relatedModel);
                }
            }

            // Gather data context
            $dataContext = $dataExplorer->explore($modelClass);

            // Enhance with AI
            $config = $enhancer->enhance($modelClass, $config, $codeContext, $dataContext);

            $this->line(" <fg=green>✓</> " . class_basename($modelClass));
        } catch (\Throwable $e) {
            $this->line(" <fg=red>✗</> " . class_basename($modelClass) . ": " . $e->getMessage());
            $errors[$modelClass] = 'Enhancement failed: ' . $e->getMessage();
        }
    }
    unset($config); // break reference

    $bar->finish();
    $this->newLine(2);
}
```

---

### Task 5: Register services in AiServiceProvider

**Files:**
- Modify: `src/AiServiceProvider.php`

Register `AiConfigEnhancer`:

```php
$this->app->singleton(AiConfigEnhancer::class, function ($app) {
    return new AiConfigEnhancer(
        llm: $app->make(LlmProviderInterface::class)
    );
});
```

---

### Task 6: Add validation test questions to ai:discover --enhance

**Files:**
- Modify: `src/Console/Commands/DiscoverEntitiesCommand.php`

After enhancement, optionally run a few test questions to validate quality. Add `{--validate : Run test questions after enhancement}` flag.

```php
if ($this->option('validate')) {
    $this->newLine();
    $this->info('Validating with test questions...');

    // Generate 5 questions covering different entity types
    $testQuestions = [
        'How many units are there?',
        'Show me the board members',
        'List all suppliers',
        'Who lives in unit 3?',
        'How many open tasks?',
    ];

    foreach ($testQuestions as $question) {
        $this->call('ai:test-question', [
            'question' => $question,
            '--user' => 1, // First user
        ]);
        $this->newLine();
    }
}
```

---

### Task 7: End-to-end test

Run the full pipeline:

```bash
# Step 1: Discover with AI enhancement
php artisan ai:discover --enhance --force

# Step 2: Re-index
php artisan ai:index-context
php artisan ai:index-scopes

# Step 3: Re-ingest (if needed)
php artisan ai:ingest --fresh

# Step 4: Validate
php artisan ai:test-question "Can I get details about my unit?" --user=302
php artisan ai:test-question "Is Bassem in my team?" --user=1512
php artisan ai:test-question "Show me unpaid invoices" --user=13
php artisan ai:test-question "Who is the president of the board?" --user=13
php artisan ai:test-question "Combien d'unités avons-nous?" --user=13
```

Verify:
- Descriptions are rich and include relationship hints
- Aliases include bilingual terms from Kompo labels
- Enum values are documented in property descriptions
- Scope examples are natural user questions
- team_path issues are flagged in security_notes
- All test questions return meaningful answers
