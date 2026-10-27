<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Support\DomainProfile;

/**
 * Enhances auto-discovered entity configs using LLM analysis.
 *
 * Two modes:
 * 1. Single-pass (ai:discover --enhance): Quick enhancement from code+data context
 * 2. Iterative (ai:enhance): Post-ingestion loop that generates test questions,
 *    runs them through the pipeline, feeds results back for 3 improvement rounds
 */
class AiConfigEnhancer
{
    public function __construct(
        private readonly LlmProviderInterface $llm
    ) {}

    /**
     * Single-pass enhancement from code+data context.
     */
    public function enhance(
        string $entityClass,
        array $discoveredConfig,
        array $codeContext,
        array $dataContext,
        array $langContext = []
    ): array {
        $prompt = $this->buildPrompt($entityClass, $discoveredConfig, $codeContext, $dataContext, $langContext);

        $enhanced = $this->llmChatJson([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'temperature' => 0.1,
            'max_tokens' => 4000,
        ]);

        return $this->mergeEnhanced($discoveredConfig, $enhanced);
    }

    /**
     * Iterative enhancement with test question feedback loop.
     * Runs AFTER ingestion so the full pipeline is available.
     *
     * @param callable $testRunner fn(string $question): string — runs a question and returns pipeline output
     * @param int $iterations Number of improve cycles (default 3)
     */
    public function enhanceIteratively(
        string $entityClass,
        array $currentConfig,
        array $codeContext,
        array $dataContext,
        callable $testRunner,
        int $iterations = 3,
        array $langContext = []
    ): array {
        $config = $this->enhance($entityClass, $currentConfig, $codeContext, $dataContext, $langContext);

        for ($i = 1; $i <= $iterations; $i++) {
            // Step 1: LLM generates test questions for this entity
            $questions = $this->generateTestQuestions($entityClass, $config, $i);

            if (empty($questions)) {
                continue;
            }

            // Step 2: Run each question through the pipeline
            $results = [];
            foreach ($questions as $question) {
                $results[] = [
                    'question' => $question,
                    'output' => $testRunner($question),
                ];
            }

            // Step 3: Feed results back to LLM for improvement
            $config = $this->improveFromFeedback($entityClass, $config, $results, $i, $iterations);
        }

        return $config;
    }

    /**
     * Ask LLM to generate test questions that would exercise this entity's config.
     *
     * @return string[] Array of natural language questions
     */
    public function generateTestQuestions(string $entityClass, array $config, int $round = 1): array
    {
        $label = $config['graph']['label'] ?? class_basename($entityClass);
        $aliases = implode(', ', array_slice($config['metadata']['aliases'] ?? [], 0, 5));
        $relationships = array_map(
            fn($r) => ($r['label'] ?? '') . ' → ' . class_basename($r['related_model'] ?? ''),
            $config['graph']['relationships'] ?? []
        );
        $relSummary = implode(', ', array_slice($relationships, 0, 8));
        $scopes = array_keys($config['metadata']['scopes'] ?? []);
        $scopeSummary = implode(', ', array_slice($scopes, 0, 5));

        $prompt = <<<PROMPT
Generate 5 test questions for the entity "{$label}" (aliases: {$aliases}).
Relationships: {$relSummary}
Scopes: {$scopeSummary}
Round {$round}: Focus on different question types each round.

Round 1: Basic queries — counts, lists, simple filters. MUST include one possessive query ("my {$label}", "mes ...") to verify user→entity linking works. MUST include one team-scoped query to verify team security works.
Round 2: Relationship traversals — "who belongs to ...", "give me the X of Y", cross-entity queries. Test that the entity links correctly to related entities. Include a question that requires following a chain of 2+ relationships.
Round 3: Creative/edge cases — bilingual (French), aggregations, negation ("which X have no Y"), multi-hop traversals. Test the hardest scenarios that real users would ask.

IMPORTANT: Every round MUST include at least:
- 1 possessive query ("my ...", "mes ...") — tests user→entity path
- 1 query referencing a related entity — tests relationship linking
- Mix English and French

Return a JSON array of 5 question strings. Return ONLY the JSON array.
PROMPT;

        $questions = $this->llmChatJson([
            ['role' => 'system', 'content' => 'You generate realistic test questions for ' . $this->appBrief() . '. Return ONLY a JSON array of strings.'],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'temperature' => 0.7,
            'max_tokens' => 500,
        ]);

        // Handle both indexed array and wrapped response
        if (isset($questions['questions'])) {
            $questions = $questions['questions'];
        }

        return is_array($questions) ? array_values(array_filter($questions, 'is_string')) : [];
    }

    /**
     * Feed test results back to LLM and get improved config.
     */
    public function improveFromFeedback(
        string $entityClass,
        array $currentConfig,
        array $testResults,
        int $round,
        int $totalRounds
    ): array {
        $label = $currentConfig['graph']['label'] ?? class_basename($entityClass);

        $resultsText = '';
        foreach ($testResults as $i => $result) {
            $num = $i + 1;
            $resultsText .= "### Question {$num}: {$result['question']}\n";
            $resultsText .= "```\n" . $this->truncate($result['output'], 1500) . "\n```\n\n";
        }

        $configJson = json_encode($currentConfig['metadata'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $personaExamples = DomainProfile::text('discovery.persona_examples');

        $prompt = <<<PROMPT
# Improvement Round {$round}/{$totalRounds} for "{$label}"

## Current Metadata Config
```json
{$configJson}
```

## Test Question Results
{$resultsText}

## Your Task

You are reviewing the output of a natural language → Cypher query pipeline. For each test question, the system used this entity's config (description, aliases, scopes, common_properties) to decide what to query and how.

Think critically about what went right and what went wrong. The config is the ONLY information the query-generating LLM has about this entity. If the config is vague, misleading, or missing context, the query will be wrong — even if the underlying data is correct.

For each test result, reason through:
1. **Did the system even find this entity?** Look at which entities were selected. If this entity was missed, its aliases or description may not match what the user asked for.
2. **Was the generated Cypher query correct?** If the query returned wrong or no results, trace WHY. Was a relationship direction wrong? Was a scope pattern incorrect? Was a property name wrong?
3. **Did the answer make sense?** Even if the query ran, did the final answer actually answer the user's question? Sometimes the right entity is found but the wrong relationship path is followed, returning sibling data instead of the requested data.
4. **Would a different user's perspective change the result?** Think about possessive queries ("my ..."), team-scoped queries, and role-based distinctions ({$personaExamples}). The config must guide the LLM to disambiguate these correctly.

Now improve the config. Your improvements must be grounded in the evidence from the test results — not hypothetical. Fix what actually failed. Strengthen what was ambiguous. Keep what worked.

Return an improved JSON object with keys: description, aliases, examples, common_properties, scopes, security_notes.
Never remove working aliases, examples, or scopes. Only add, improve, or clarify. Return ONLY valid JSON.
PROMPT;

        $improved = $this->llmChatJson([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'temperature' => 0.1,
            'max_tokens' => 4000,
        ]);

        return $this->mergeEnhanced($currentConfig, $improved);
    }

    /** What the generated questions are for: the host's brief, else its project description. */
    private function appBrief(): string
    {
        $brief = DomainProfile::text('discovery.app_brief');

        return $brief !== '' ? $brief : 'an AI assistant for this application: ' . rtrim(trim((string) config('ai.project.description', '')), '.');
    }

    private function systemPrompt(): string
    {
        $possessivePath = DomainProfile::text('discovery.possessive_path_example');
        $possessiveAliases = DomainProfile::text('discovery.possessive_alias_examples');
        $questionExamples = DomainProfile::text('discovery.question_examples');
        $relationshipExamples = DomainProfile::text('discovery.relationship_examples');
        $junctionExample = DomainProfile::text('discovery.junction_example');
        $possessiveChain = DomainProfile::text('discovery.possessive_chain_example');

        return <<<PROMPT
You are an expert at configuring AI-powered natural language query systems for Laravel applications. You analyze source code deeply and generate configuration that helps an LLM convert user questions into Neo4j Cypher queries.

You will receive:
1. A model's discovered configuration (properties, relationships, scopes)
2. The model's PHP source code and related files (Kompo forms/tables, migrations, routes)
3. Sample data and field statistics

Generate a JSON object with these keys:
- "description": A rich 2-3 sentence description explaining what this entity is, how it relates to other entities, and CRITICAL: include relationship direction hints for Neo4j traversal. Mention key JOINs needed to get human-readable data. IMPORTANT: Document the user-to-entity path for possessive queries ({$possessivePath}). Note which entities are link/junction tables vs. real entities.
- "aliases": Array of 10-15 aliases in both English and French. Include singular, plural, common abbreviations, domain-specific terms from UI labels, and possessive forms where applicable ({$possessiveAliases}).
- "examples": Array of 5-8 natural language questions users would ask about this entity. These are entity-level questions (not scope-specific). Mix English and French. Include counts, lists, possessive, and relationship questions. E.g., {$questionExamples}.
- "common_properties": Object mapping property names to descriptions. For enum fields, ALWAYS document the values: "status: Status (1=Draft, 2=Approved, 3=Sent)". For date fields stored as strings, note: "stored as string, compare with string operators". For FK fields, explain what they point to and how they link entities.
- "scopes": Object of scope definitions. For each scope, include: specification_type, concept, cypher_pattern (MUST use correct Neo4j syntax — dates as strings use toString(date())), filter, and 3-5 examples as natural questions users would ask in BOTH English and French.
- "relationship_descriptions": Object mapping relationship type names to plain-English descriptions of what the relationship means. E.g., {$relationshipExamples}. Focus on business meaning, not technical details.
- "security_notes": Notes about team_path issues. Check: Is team_id always null? Should security traverse through a relationship instead? Document the correct team resolution path. Also note if this entity is a junction table (e.g., {$junctionExample}) that shouldn't be queried directly by users but is critical for traversal.

Rules:
- Read the Kompo form labels to understand what users call each field
- Read the model's enum/constant methods to document exact values
- Read relationships to understand Neo4j direction (belongsTo = outgoing from child)
- Scope cypher_patterns MUST use valid Neo4j syntax: string dates use toString(date()), integers are NOT quoted
- Aliases should include terms from Kompo form labels and route names
- For relationship descriptions, always specify: "To find X from Y, traverse (Y)-[:REL]->(Z)-[:REL]->(X)"
- For "my ..." queries, document the full chain: {$possessiveChain}, etc.
- Return ONLY valid JSON
PROMPT;
    }

    private function buildPrompt(
        string $entityClass,
        array $discoveredConfig,
        array $codeContext,
        array $dataContext,
        array $langContext = []
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

        // UI Labels from lang files (bilingual)
        if (!empty($langContext)) {
            $langExplorer = new LangExplorer();
            $formatted = $langExplorer->formatForPrompt($langContext);
            if (!empty($formatted)) {
                $prompt .= "## UI Labels (from translation files — this is what users see)\n{$formatted}\n\n";
            }
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
        // Description: replace (LLM description should be richer)
        if (!empty($enhanced['description'])) {
            $discovered['metadata']['description'] = $enhanced['description'];
        }
        // Aliases: merge and deduplicate (never lose existing aliases)
        if (!empty($enhanced['aliases'])) {
            $existing = $discovered['metadata']['aliases'] ?? [];
            $discovered['metadata']['aliases'] = array_values(array_unique(
                array_merge($existing, $enhanced['aliases'])
            ));
        }
        // Examples: merge and deduplicate (never lose existing examples)
        if (!empty($enhanced['examples'])) {
            $existing = $discovered['metadata']['examples'] ?? [];
            $discovered['metadata']['examples'] = array_values(array_unique(
                array_merge($existing, $enhanced['examples'])
            ));
        }
        // Common properties: merge (LLM descriptions override, but existing keys preserved)
        if (!empty($enhanced['common_properties'])) {
            $discovered['metadata']['common_properties'] = array_merge(
                $discovered['metadata']['common_properties'] ?? [],
                $enhanced['common_properties']
            );
        }
        // Scopes: merge (existing preserved, new added, same key overridden)
        if (!empty($enhanced['scopes'])) {
            $discovered['metadata']['scopes'] = array_merge(
                $discovered['metadata']['scopes'] ?? [],
                $enhanced['scopes']
            );
        }
        if (!empty($enhanced['security_notes'])) {
            $discovered['metadata']['security_notes'] = $enhanced['security_notes'];
        }

        // Relationship descriptions — merge into existing graph.relationships
        if (!empty($enhanced['relationship_descriptions']) && !empty($discovered['graph']['relationships'])) {
            foreach ($discovered['graph']['relationships'] as &$rel) {
                $type = $rel['type'] ?? null;
                if ($type && isset($enhanced['relationship_descriptions'][$type])) {
                    $rel['description'] = $enhanced['relationship_descriptions'][$type];
                }
            }
            unset($rel);
        }

        return $discovered;
    }

    /**
     * Call LLM with retry on rate limit errors.
     */
    private function llmChatJson(array $messages, array $options = []): array
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->llm->chatJson($messages, $options);
                return $this->toArray($response);
            } catch (\Throwable $e) {
                if ($attempt < $maxRetries && str_contains(strtolower($e->getMessage()), 'rate limit')) {
                    $wait = $attempt * 15;
                    sleep($wait);
                    continue;
                }
                throw $e;
            }
        }

        return []; // Unreachable, but satisfies return type
    }

    /**
     * Recursively convert stdClass objects to arrays.
     */
    private function toArray(mixed $data): array
    {
        return json_decode(json_encode($data), true) ?? [];
    }

    private function truncate(string $text, int $maxChars): string
    {
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, $maxChars) . "\n// ... truncated ...";
    }
}
