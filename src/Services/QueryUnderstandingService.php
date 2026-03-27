<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Support\AiLogger;

/**
 * QueryUnderstandingService
 *
 * Pre-processes natural language questions before semantic context selection
 * to improve entity matching. Uses a fast LLM call to identify:
 * - Entity types mentioned or implied in the question
 * - Person names that need fuzzy search
 * - Property concepts the query is about
 * - A rewritten search query optimized for semantic matching
 * - The user's intent (count, list, lookup, aggregate, etc.)
 *
 * This layer runs BEFORE SemanticContextSelector and helps resolve ambiguous
 * references like "John Smith" -> Person entity, "rent" -> financial property,
 * "volunteers" -> PersonTeam with role filter.
 */
class QueryUnderstandingService
{
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly AiLogger $logger,
        private readonly array $config = []
    ) {
    }

    /**
     * Analyze a question to extract structured understanding.
     *
     * @param string $question  The user's natural language question
     * @param array  $entityCatalog  Compact catalog built from entity configs (see buildEntityCatalog)
     * @return array Structured result with keys:
     *               - entity_types: string[] entity labels likely relevant
     *               - person_names: string[] person/contact names mentioned
     *               - property_concepts: string[] property concepts the query is about
     *               - search_query: string rewritten query for better embedding search
     *               - intent: string one of count|list|lookup|aggregate|compare|other
     */
    public function analyze(string $question, array $entityCatalog): array
    {
        $empty = self::emptyResult();

        if (!($this->config['enabled'] ?? true)) {
            $this->logger->debug('query_understanding', 'Skipped (disabled by config)');
            return $empty;
        }

        $this->logger->startTimer('query_understanding');

        try {
            $messages = $this->buildMessages($question, $entityCatalog);

            $response = $this->llm->chatJson($messages, [
                'max_tokens' => $this->config['max_tokens'] ?? 500,
                'temperature' => $this->config['temperature'] ?? 0.0,
            ]);

            $result = $this->normalizeResponse($response);

            $ms = $this->logger->stopTimer('query_understanding');
            $this->logger->info('query_understanding', 'Analysis complete', [
                'duration_ms' => $ms,
                'entity_types' => $result['entity_types'],
                'person_names' => $result['person_names'],
                'property_concepts' => $result['property_concepts'],
                'intent' => $result['intent'],
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->stopTimer('query_understanding');
            $this->logger->error('query_understanding', 'Analysis failed, using empty result', [
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }
    }

    /**
     * Build a compact entity catalog from full entity configs.
     *
     * The catalog is designed to be token-efficient: only labels, top aliases,
     * and short descriptions are included. Property groups are NOT included
     * (too many tokens); property concepts should be inferred by the LLM.
     *
     * @param array $entityConfigs Full entity configs keyed by FQCN
     * @return array Compact catalog: [ ['label' => 'Contact', 'aliases' => [...], 'description' => '...'], ... ]
     */
    public static function buildEntityCatalog(array $entityConfigs): array
    {
        $catalog = [];

        foreach ($entityConfigs as $fqcn => $config) {
            $metadata = $config['metadata'] ?? [];
            $label = $config['graph']['label'] ?? class_basename($fqcn);
            $aliases = array_slice($metadata['aliases'] ?? [], 0, 5);
            $description = $metadata['description'] ?? '';

            // Truncate description to keep prompt compact
            if (strlen($description) > 120) {
                $description = substr($description, 0, 117) . '...';
            }

            $catalog[] = [
                'label' => $label,
                'aliases' => $aliases,
                'description' => $description,
            ];
        }

        return $catalog;
    }

    /**
     * Return an empty/default result for graceful degradation.
     *
     * @return array
     */
    /**
     * Empty result for when QU doesn't run.
     * Uses a minimal possessive fallback so user_entity_paths still work.
     */
    public static function emptyResult(string $question = ''): array
    {
        return [
            'entity_types' => [],
            'person_names' => [],
            'property_concepts' => [],
            'search_query' => '',
            'is_possessive' => $question !== '' && self::looksLikePossessive($question),
            'intent' => 'other',
        ];
    }

    /**
     * Minimal possessive check — fallback only when QU LLM doesn't run.
     */
    private static function looksLikePossessive(string $question): bool
    {
        return (bool) preg_match('/\b(my|mine|i\s+have|i\s+own|belong.*to\s+me)\b/i', $question);
    }

    /**
     * Build the chat messages for the LLM call.
     */
    private function buildMessages(string $question, array $entityCatalog): array
    {
        $catalogText = $this->formatCatalog($entityCatalog);

        $system = <<<PROMPT
You are a query understanding assistant for a graph database system. Your job is to analyze a natural language question and extract structured information to help find the right data.

AVAILABLE ENTITY TYPES:
{$catalogText}

TASK: Given the user's question, return a JSON object with exactly these keys:
- "entity_types": array of entity label strings from the list above that are relevant to the question. Include entities that are explicitly mentioned AND entities that are implicitly needed (e.g., if asking about "rent payments", include both the payment entity and the unit/property entity).
- "person_names": array of person/contact name strings mentioned in the question (e.g., "John Smith", "Maria"). Empty array if no names mentioned.
- "property_concepts": array of SHORT single-word or two-word property concept strings (e.g., "insurance", "email", "balance", "birthday", "parking"). Keep each concept as short as possible — one word preferred. These are abstract data concepts that may be stored as properties on entities, NOT entity types themselves.
- "search_query": a rewritten version of the question optimized for semantic search against entity descriptions. Remove filler words, expand abbreviations, use domain terminology.
- "is_possessive": boolean, true if the user is asking about THEIR OWN data ("my unit", "my invoices", "do I have", "am I", etc.)
- "intent": one of: "count", "list", "lookup", "aggregate", "compare", "other"
- "clarified_question": "Usually the concrete asking of the user hides a more abstract context and intent. If the question is "How many parking spaces do I have?" the clarified_question might be "How many parking space entities are related to the person entity representing me in the graph?" This is meant to clarify the underlying data relationships and intent in a way that can help with debugging and prompt engineering. Keep it concise.
But also it could have a base significance. let's say the user asks "Can i tear down a wall in my apartment?" the clarified question might be "What are the rules and regulations regarding modifying a residential unit in my building, more specifically tearing down a wall, and how do they apply to me as a tenant?"

Rules:
- Only use entity labels from the AVAILABLE ENTITY TYPES list.
- For person names, extract the actual name strings, not entity types.
- Property concepts should be abstract data concepts, not Neo4j property names.
- Keep arrays empty rather than guessing if uncertain.
- Return ONLY valid JSON, no markdown fences or explanation.
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $question],
        ];
    }

    /**
     * Format the entity catalog as a compact text block for the prompt.
     */
    private function formatCatalog(array $catalog): string
    {
        $lines = [];

        foreach ($catalog as $entry) {
            $label = $entry['label'] ?? '';
            $aliases = $entry['aliases'] ?? [];
            $description = $entry['description'] ?? '';

            $line = "- {$label}";
            if (!empty($aliases)) {
                $line .= ' (aka: ' . implode(', ', $aliases) . ')';
            }
            if (!empty($description)) {
                $line .= ": {$description}";
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Normalize the LLM response into the expected structure.
     */
    private function normalizeResponse(object|array $response): array
    {
        $data = is_object($response) ? (array) $response : $response;

        return [
            'entity_types' => $this->ensureStringArray($data['entity_types'] ?? []),
            'person_names' => $this->ensureStringArray($data['person_names'] ?? []),
            'property_concepts' => $this->ensureStringArray($data['property_concepts'] ?? []),
            'search_query' => is_string($data['search_query'] ?? null) ? $data['search_query'] : '',
            'is_possessive' => (bool) ($data['is_possessive'] ?? false),
            'intent' => $this->normalizeIntent($data['intent'] ?? 'other'),
        ];
    }

    /**
     * Ensure a value is an array of strings.
     */
    private function ensureStringArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn($v) => is_string($v) ? $v : null, $value)
        ));
    }

    /**
     * Normalize intent to one of the known values.
     */
    private function normalizeIntent(mixed $intent): string
    {
        if (!is_string($intent)) {
            return 'other';
        }

        $valid = ['count', 'list', 'lookup', 'aggregate', 'compare', 'other'];

        $normalized = strtolower(trim($intent));

        return in_array($normalized, $valid, true) ? $normalized : 'other';
    }
}
