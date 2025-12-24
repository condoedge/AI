<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;

/**
 * ScopeSemanticMatcher
 *
 * Uses vector similarity to match user questions against scope examples and concepts.
 * This provides more accurate scope detection than simple string matching.
 *
 * Example:
 * - Question: "How many helpers do we have?"
 * - Matches: "volunteers" scope (even though "helpers" != "volunteers")
 *
 * The service:
 * 1. Indexes all scope examples/concepts as embeddings
 * 2. When a question comes in, embeds it and finds similar scope examples
 * 3. Returns matched scopes with confidence scores
 */
class ScopeSemanticMatcher
{
    private const DEFAULT_COLLECTION_NAME = 'scope_examples';
    private const DEFAULT_THRESHOLD = 0.7;
    private const DEFAULT_TOP_K = 5;

    private string $collectionName;

    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly array $config = []
    ) {
        $this->collectionName = $config['collection'] ?? self::DEFAULT_COLLECTION_NAME;
    }

    /**
     * Find scopes that semantically match the user's question
     *
     * @param string $question User's question
     * @param array $entityConfigs All entity configurations with scopes
     * @param float $threshold Minimum similarity score (0-1)
     * @param int $topK Maximum number of matches to return
     * @return array Matched scopes with scores
     */
    public function findMatchingScopes(
        string $question,
        array $entityConfigs,
        float $threshold = self::DEFAULT_THRESHOLD,
        int $topK = self::DEFAULT_TOP_K
    ): array {
        $collectionName = $this->config['collection'] ?? $this->collectionName;

        // Check if collection exists and has data
        if (!$this->collectionExists($collectionName)) {
            // Fall back to string matching if not indexed
            return $this->fallbackStringMatch($question, $entityConfigs);
        }

        // Embed the question
        $questionEmbedding = $this->embeddingProvider->embed($question);

        // Search for similar scope examples
        $results = $this->vectorStore->search(
            $collectionName,
            $questionEmbedding,
            $topK * 2, // Get extra results to filter by threshold
            [] // No metadata filter
        );

        // Filter by threshold and deduplicate by scope
        $matchedScopes = [];
        $seenScopes = [];

        foreach ($results as $result) {
            $score = $result['score'] ?? 0;

            if ($score < $threshold) {
                continue;
            }

            $scopeKey = $result['metadata']['scope_key'] ?? null;
            $entityName = $result['metadata']['entity'] ?? null;

            if (!$scopeKey || !$entityName) {
                continue;
            }

            // Deduplicate - keep highest score for each scope
            $uniqueKey = "{$entityName}:{$scopeKey}";
            if (isset($seenScopes[$uniqueKey])) {
                continue;
            }
            $seenScopes[$uniqueKey] = true;

            // Get the full scope config
            $scopeConfig = $this->getScopeConfig($entityConfigs, $entityName, $scopeKey);

            if ($scopeConfig) {
                $matchedScopes[$scopeKey] = array_merge($scopeConfig, [
                    'entity' => $entityName,
                    'scope' => $scopeKey,
                    'match_score' => $score,
                    'match_type' => 'semantic',
                    'matched_example' => $result['metadata']['text'] ?? '',
                ]);
            }

            if (count($matchedScopes) >= $topK) {
                break;
            }
        }

        // Sort by score descending
        uasort($matchedScopes, fn($a, $b) => ($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0));

        return $matchedScopes;
    }

    /**
     * Index all scope examples and concepts for semantic search
     *
     * @param array $entityConfigs All entity configurations
     * @return array Index statistics
     */
    public function indexScopes(array $entityConfigs): array
    {
        $collectionName = $this->config['collection'] ?? $this->collectionName;
        $dimension = $this->config['dimension'] ?? 1536;

        // Ensure collection exists
        $this->vectorStore->ensureCollection($collectionName, $dimension);

        // Clear existing data
        $this->vectorStore->deleteAll($collectionName);

        $indexed = 0;
        $errors = [];
        $points = [];

        foreach ($entityConfigs as $entityName => $config) {
            $scopes = $config['metadata']['scopes'] ?? [];

            foreach ($scopes as $scopeName => $scopeConfig) {
                // Skip numeric keys (malformed config)
                if (is_numeric($scopeName)) {
                    $errors[] = "Skipped numeric scope key in {$entityName}";
                    continue;
                }

                // Index the concept
                if (!empty($scopeConfig['concept'])) {
                    $points[] = $this->createPoint(
                        $entityName,
                        $scopeName,
                        $scopeConfig['concept'],
                        'concept'
                    );
                }

                // Index each example
                foreach ($scopeConfig['examples'] ?? [] as $example) {
                    $points[] = $this->createPoint(
                        $entityName,
                        $scopeName,
                        $example,
                        'example'
                    );
                }

                // Index aliases/synonyms if present
                foreach ($scopeConfig['aliases'] ?? [] as $alias) {
                    $points[] = $this->createPoint(
                        $entityName,
                        $scopeName,
                        $alias,
                        'alias'
                    );
                }
            }
        }

        // Batch embed and upsert
        if (!empty($points)) {
            $texts = array_column($points, 'text');
            $embeddings = $this->embeddingProvider->embedBatch($texts);

            $upsertPoints = [];
            foreach ($points as $i => $point) {
                $upsertPoints[] = [
                    'id' => $point['id'],
                    'vector' => $embeddings[$i],
                    'metadata' => $point['metadata'],
                ];
            }

            $this->vectorStore->upsertBatch($collectionName, $upsertPoints);
            $indexed = count($upsertPoints);
        }

        return [
            'collection' => $collectionName,
            'indexed' => $indexed,
            'errors' => $errors,
        ];
    }

    /**
     * Create a point for indexing
     */
    private function createPoint(string $entity, string $scopeName, string $text, string $type): array
    {
        $id = md5("{$entity}:{$scopeName}:{$text}");

        return [
            'id' => $id,
            'text' => $text,
            'metadata' => [
                'entity' => $entity,
                'scope_key' => $scopeName,
                'text' => $text,
                'type' => $type,
            ],
        ];
    }

    /**
     * Check if collection exists and has data
     */
    private function collectionExists(string $collectionName): bool
    {
        try {
            $info = $this->vectorStore->getCollectionInfo($collectionName);
            return ($info['points_count'] ?? 0) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get scope config from entity configs
     */
    private function getScopeConfig(array $entityConfigs, string $entityName, string $scopeKey): ?array
    {
        // Try exact entity name match
        if (isset($entityConfigs[$entityName]['metadata']['scopes'][$scopeKey])) {
            return $entityConfigs[$entityName]['metadata']['scopes'][$scopeKey];
        }

        // Search through all entities (in case entity name format differs)
        foreach ($entityConfigs as $name => $config) {
            // Check if entity name matches (case-insensitive, with/without namespace)
            $shortName = class_basename($name);
            if (strcasecmp($shortName, $entityName) === 0 || strcasecmp($name, $entityName) === 0) {
                if (isset($config['metadata']['scopes'][$scopeKey])) {
                    return $config['metadata']['scopes'][$scopeKey];
                }
            }
        }

        return null;
    }

    /**
     * Fallback to string matching when vector index is not available
     */
    private function fallbackStringMatch(string $question, array $entityConfigs): array
    {
        $questionLower = strtolower($question);
        $matchedScopes = [];

        foreach ($entityConfigs as $entityName => $config) {
            $scopes = $config['metadata']['scopes'] ?? [];

            foreach ($scopes as $scopeName => $scopeConfig) {
                if (is_numeric($scopeName)) {
                    continue;
                }

                $matched = false;
                $matchedText = '';

                // Check scope name
                if (strpos($questionLower, strtolower($scopeName)) !== false) {
                    $matched = true;
                    $matchedText = $scopeName;
                }

                // Check examples
                if (!$matched) {
                    foreach ($scopeConfig['examples'] ?? [] as $example) {
                        // Check if significant words from example appear in question
                        $exampleWords = $this->extractSignificantWords($example);
                        $questionWords = $this->extractSignificantWords($question);

                        $overlap = array_intersect($exampleWords, $questionWords);
                        if (count($overlap) >= 2 || (count($overlap) >= 1 && count($exampleWords) <= 3)) {
                            $matched = true;
                            $matchedText = $example;
                            break;
                        }
                    }
                }

                // Check concept
                if (!$matched && !empty($scopeConfig['concept'])) {
                    $conceptWords = $this->extractSignificantWords($scopeConfig['concept']);
                    $questionWords = $this->extractSignificantWords($question);

                    $overlap = array_intersect($conceptWords, $questionWords);
                    if (count($overlap) >= 2) {
                        $matched = true;
                        $matchedText = $scopeConfig['concept'];
                    }
                }

                if ($matched) {
                    $matchedScopes[$scopeName] = array_merge($scopeConfig, [
                        'entity' => class_basename($entityName),
                        'scope' => $scopeName,
                        'match_score' => 0.5, // Lower score for string match
                        'match_type' => 'string',
                        'matched_example' => $matchedText,
                    ]);
                }
            }
        }

        return $matchedScopes;
    }

    /**
     * Extract significant words from text (remove stopwords)
     */
    private function extractSignificantWords(string $text): array
    {
        $stopwords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
            'may', 'might', 'must', 'shall', 'can', 'need', 'dare', 'ought', 'used',
            'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'from', 'as', 'into',
            'through', 'during', 'before', 'after', 'above', 'below', 'between',
            'and', 'but', 'or', 'nor', 'so', 'yet', 'both', 'either', 'neither',
            'not', 'only', 'own', 'same', 'than', 'too', 'very', 'just',
            'how', 'many', 'much', 'what', 'which', 'who', 'whom', 'this', 'that',
            'these', 'those', 'am', 'there', 'all', 'any', 'each', 'every', 'show',
            'list', 'find', 'get', 'display'];

        $words = preg_split('/\W+/', strtolower($text));
        $words = array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopwords));

        return array_values($words);
    }

    /**
     * Get match explanation for debugging
     */
    public function explainMatch(string $question, array $entityConfigs): array
    {
        $matches = $this->findMatchingScopes($question, $entityConfigs, 0.5, 10);

        $explanation = [
            'question' => $question,
            'matches' => [],
        ];

        foreach ($matches as $scopeName => $match) {
            $explanation['matches'][] = [
                'scope' => $scopeName,
                'entity' => $match['entity'],
                'score' => round($match['match_score'] ?? 0, 3),
                'type' => $match['match_type'] ?? 'unknown',
                'matched_text' => $match['matched_example'] ?? '',
                'concept' => $match['concept'] ?? '',
            ];
        }

        return $explanation;
    }
}
