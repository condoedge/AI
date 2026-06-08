<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
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
     * @return QueryUnderstandingResult Structured result with entity types, person names, intent, etc.
     */
    public function analyze(string $question, array $entityCatalog): QueryUnderstandingResult
    {
        $this->logger->startTimer('query_understanding');

        try {
            $messages = $this->buildMessages($question, $entityCatalog);

            $response = $this->llm->chatJson($messages, [
                'max_tokens' => $this->config['max_tokens'] ?? 500,
                'temperature' => $this->config['temperature'] ?? 0.0,
            ]);

            $result = $this->normalizeResponse($response, $entityCatalog);

            $ms = $this->logger->stopTimer('query_understanding');
            $this->logger->info('query_understanding', 'Analysis complete', [
                'duration_ms' => $ms,
                'entity_types' => $result->entityTypes,
                'person_names' => $result->personNames,
                'property_concepts' => $result->propertyConcepts,
                'intent' => $result->intent,
                'question_type' => $result->questionType,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->stopTimer('query_understanding');
            $this->logger->error('query_understanding', 'Analysis failed, using empty result', [
                'error' => $e->getMessage(),
            ]);

            return QueryUnderstandingResult::empty($question);
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
- "question_type": one of "data_query" (needs database/graph lookup), "knowledge_query" (needs file/document content — rules, regulations, policies, "can I do X?"), "hybrid" (needs both data and documents)
- "clarified_question": "Usually the concrete asking of the user hides a more abstract context and intent. If the question is "How many parking spaces do I have?" the clarified_question might be "How many parking space entities are related to the person entity representing me in the graph?" This is meant to clarify the underlying data relationships and intent in a way that can help with debugging and prompt engineering. Keep it concise.
But also it could have a base significance. let's say the user asks "Can i tear down a wall in my apartment?" the clarified question might be "What are the rules and regulations regarding modifying a residential unit in my building, more specifically tearing down a wall, and how do they apply to me as a tenant?"
- "search_tags": array of EXACTLY 10 keyword tags for searching documents and files. CRITICAL: You MUST return exactly 10 tags. Include: (1) literal terms from the question, (2) domain-specific synonyms that would appear in legal/regulatory documents, (3) the broader category or domain the question belongs to (e.g., "co-ownership" for condo questions), (4) French translations of key terms, (5) related technical terms. For "Can I tear down a wall?" you MUST produce tags like: ["wall", "structural modification", "renovation", "common portions", "private portions", "co-ownership", "alteration", "building work", "mur", "copropriété"]. Think: what words would appear in a legal document, regulation, or building rule that answers this question?

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
     *
     * @param object|array $response      Raw LLM JSON response
     * @param array        $entityCatalog Catalog used to validate entity_types (see buildEntityCatalog)
     */
    private function normalizeResponse(object|array $response, array $entityCatalog = []): QueryUnderstandingResult
    {
        $data = is_object($response) ? (array) $response : $response;

        return new QueryUnderstandingResult(
            entityTypes: $this->validateEntityTypes(
                $this->ensureStringArray($data['entity_types'] ?? []),
                $entityCatalog
            ),
            personNames: $this->ensureStringArray($data['person_names'] ?? []),
            propertyConcepts: $this->ensureStringArray($data['property_concepts'] ?? []),
            searchQuery: is_string($data['search_query'] ?? null) ? $data['search_query'] : '',
            isPossessive: (bool) ($data['is_possessive'] ?? false),
            intent: $this->normalizeIntent($data['intent'] ?? 'other'),
            questionType: $this->normalizeQuestionType($data['question_type'] ?? 'hybrid'),
            clarifiedQuestion: is_string($data['clarified_question'] ?? null) ? $data['clarified_question'] : '',
            searchTags: $this->ensureStringArray($data['search_tags'] ?? []),
        );
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
     * Filter LLM-returned entity types to only labels present in the catalog.
     *
     * The downstream pipeline force-injects QU entity_types into semantic selection
     * at a high score (~0.8), so a hallucinated label would pollute retrieval. We
     * keep only labels that exist in the entity catalog and log any rejected ones
     * at debug. Matching is case-insensitive against the catalog's canonical label;
     * the canonical casing is preserved in the output.
     *
     * @param string[] $entityTypes   Labels returned by the LLM
     * @param array    $entityCatalog Catalog built from entity configs
     * @return string[] Validated labels (canonical casing)
     */
    private function validateEntityTypes(array $entityTypes, array $entityCatalog): array
    {
        // No catalog to validate against → pass through unchanged (back-compat).
        if (empty($entityCatalog)) {
            return $entityTypes;
        }

        // Map lowercase label => canonical label for case-insensitive matching.
        $allowed = [];
        foreach ($entityCatalog as $entry) {
            $label = is_array($entry) ? ($entry['label'] ?? null) : null;
            if (is_string($label) && $label !== '') {
                $allowed[strtolower($label)] = $label;
            }
        }

        $valid = [];
        $rejected = [];
        foreach ($entityTypes as $label) {
            $key = strtolower($label);
            if (isset($allowed[$key])) {
                $valid[] = $allowed[$key];
            } else {
                $rejected[] = $label;
            }
        }

        if (!empty($rejected)) {
            $this->logger->debug('query_understanding', 'Rejected entity_types not in catalog', [
                'rejected' => $rejected,
                'allowed_labels' => array_values($allowed),
            ]);
        }

        return array_values(array_unique($valid));
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

    /**
     * Normalize question type to one of the known values.
     */
    private function normalizeQuestionType(mixed $type): string
    {
        if (!is_string($type)) {
            return 'hybrid';
        }

        $valid = ['data_query', 'knowledge_query', 'hybrid'];
        $normalized = strtolower(trim($type));

        return in_array($normalized, $valid, true) ? $normalized : 'hybrid';
    }
}
