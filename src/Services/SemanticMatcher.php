<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Illuminate\Support\Facades\Log;

/**
 * SemanticMatcher
 *
 * Provides semantic similarity matching using vector embeddings instead of
 * hardcoded string comparisons. This enables fuzzy matching and understanding
 * of synonyms, variations, and paraphrasing.
 *
 * Use Cases:
 * - Entity detection: "clients" → Customer entity
 * - Scope detection: "volunteers" → volunteer scope
 * - Template matching: "display all" → list_all template
 * - Label inference: "purchases" → Order label
 *
 * Architecture:
 * - Uses embedding provider to convert text to vectors
 * - Uses vector store to perform similarity search
 * - Configurable thresholds for precision/recall tuning
 * - Fallback to exact matching if needed
 *
 * Example Usage:
 * ```php
 * $matcher = new SemanticMatcher($embedding, $vectorStore);
 *
 * // Find best match
 * $match = $matcher->findBestMatch(
 *     query: "Show all clients",
 *     candidates: ['Customer', 'Order', 'Team'],
 *     threshold: 0.75
 * );
 * // Returns: ['key' => 'Customer', 'score' => 0.89, 'exact' => false]
 *
 * // Match entities
 * $entities = $matcher->matchEntities(
 *     question: "List all premium clients",
 *     entityConfigs: $configs,
 *     threshold: 0.75
 * );
 * // Returns: [['entity' => 'Customer', 'score' => 0.89, ...]]
 * ```
 *
 * @package Condoedge\Ai\Services
 */
class SemanticMatcher
{
    /**
     * Cache for embeddings to avoid redundant API calls
     */
    private array $embeddingCache = [];

    /**
     * Create semantic matcher instance
     *
     * @param EmbeddingProviderInterface $embedding Embedding provider for text-to-vector
     * @param VectorStoreInterface $vectorStore Vector store for similarity search
     */
    public function __construct(
        private readonly EmbeddingProviderInterface $embedding,
        private readonly VectorStoreInterface $vectorStore
    ) {
    }

    /**
     * Find the best semantic match from a list of candidates
     *
     * This is the core matching method that computes semantic similarity between
     * a query and multiple candidates, returning the best match above threshold.
     *
     * Process:
     * 1. Check for exact match first (fast path)
     * 2. Generate embedding for query
     * 3. Generate embeddings for all candidates
     * 4. Compute cosine similarities
     * 5. Return best match above threshold
     *
     * @param string $query The query text to match
     * @param array $candidates Associative array of candidates ['key' => 'text to match']
     *                          If indexed array, uses values as both key and text
     * @param float $threshold Minimum similarity score (0.0 - 1.0)
     * @param string|null $collection Optional: Use vector store collection for search
     * @return array|null Match result with keys: key, score, exact, candidate_text
     *                    Returns null if no match above threshold
     *
     * @throws \RuntimeException If embedding generation fails
     */
    public function findBestMatch(
        string $query,
        array $candidates,
        float $threshold = 0.70,
        ?string $collection = null
    ): ?array {
        // Validate inputs
        if (empty($query) || empty($candidates)) {
            return null;
        }

        // Normalize query
        $queryNormalized = $this->normalizeText($query);

        // Fast path: Check for exact matches first
        foreach ($candidates as $key => $candidateText) {
            $candidateNormalized = $this->normalizeText($candidateText);

            if ($queryNormalized === $candidateNormalized) {
                return [
                    'key' => $key,
                    'score' => 1.0,
                    'exact' => true,
                    'candidate_text' => $candidateText,
                    'method' => 'exact',
                ];
            }
        }

        // If collection provided, use vector store search
        if ($collection && $this->vectorStore->collectionExists($collection)) {
            return $this->searchInCollection($query, $collection, $threshold);
        }

        // Otherwise, compute similarities in memory
        return $this->computeBestMatch($query, $candidates, $threshold);
    }

    /**
     * Match entities in a question using semantic similarity
     *
     * Detects which entities are mentioned in the question by matching against:
     * - Entity names (e.g., "Customer", "Order")
     * - Entity aliases (e.g., "client", "purchase")
     * - Entity descriptions
     *
     * @param string $question Natural language question
     * @param array $entityConfigs Entity configurations from config/entities.php
     * @param float $threshold Minimum similarity score
     * @return array Array of matches with entity details
     */
    public function matchEntities(
        string $question,
        array $entityConfigs,
        float $threshold = 0.75
    ): array {
        $matches = [];

        foreach ($entityConfigs as $entityName => $config) {
            $metadata = $config['metadata'] ?? [];

            // Build candidate texts: name + aliases + description
            $candidateTexts = [$entityName];

            if (!empty($metadata['aliases'])) {
                $candidateTexts = array_merge($candidateTexts, $metadata['aliases']);
            }

            if (!empty($metadata['description'])) {
                $candidateTexts[] = $metadata['description'];
            }

            // Check each candidate
            foreach ($candidateTexts as $candidateText) {
                $match = $this->findBestMatch(
                    query: $question,
                    candidates: [$entityName => $candidateText],
                    threshold: $threshold
                );

                if ($match) {
                    $matches[] = [
                        'entity' => $entityName,
                        'score' => $match['score'],
                        'exact' => $match['exact'],
                        'matched_text' => $candidateText,
                        'metadata' => $metadata,
                        'config' => $config,
                    ];
                    break; // Found match for this entity, move to next
                }
            }
        }

        // Sort by score (highest first)
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        return $matches;
    }

    /**
     * Match scopes in a question using semantic similarity
     *
     * Detects which scopes are mentioned by matching against:
     * - Scope names (e.g., "volunteers", "premium_customers")
     * - Scope descriptions
     * - Scope concepts
     *
     * @param string $question Natural language question
     * @param array $scopes All scopes from all entities
     * @param float $threshold Minimum similarity score
     * @return array Array of matched scopes with details
     */
    public function matchScopes(
        string $question,
        array $scopes,
        float $threshold = 0.70
    ): array {
        $matches = [];

        foreach ($scopes as $scopeName => $scopeConfig) {
            // Build candidate texts: name + description + concept
            $candidateTexts = [$scopeName];

            if (!empty($scopeConfig['description'])) {
                $candidateTexts[] = $scopeConfig['description'];
            }

            if (!empty($scopeConfig['concept'])) {
                $candidateTexts[] = $scopeConfig['concept'];
            }

            // Check each candidate
            foreach ($candidateTexts as $candidateText) {
                $match = $this->findBestMatch(
                    query: $question,
                    candidates: [$scopeName => $candidateText],
                    threshold: $threshold
                );

                if ($match) {
                    $matches[] = [
                        'scope' => $scopeName,
                        'entity' => $scopeConfig['entity'] ?? null,
                        'score' => $match['score'],
                        'exact' => $match['exact'],
                        'matched_text' => $candidateText,
                        'config' => $scopeConfig,
                    ];
                    break;
                }
            }
        }

        // Sort by score (highest first)
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        return $matches;
    }

    /**
     * Match a label (entity type) in a question
     *
     * Finds the most relevant label by matching against:
     * - Label names
     * - Entity descriptions
     * - Relationship context
     *
     * @param string $question Natural language question
     * @param array $labels Available labels from schema
     * @param array $entityMetadata Entity metadata for context
     * @param float $threshold Minimum similarity score
     * @return array|null Best matching label or null
     */
    public function matchLabel(
        string $question,
        array $labels,
        array $entityMetadata = [],
        float $threshold = 0.70
    ): ?array {
        $candidates = [];

        foreach ($labels as $label) {
            // Use label name as default
            $candidateText = $label;

            // If metadata available, use description for better matching
            if (isset($entityMetadata[$label]['description'])) {
                $candidateText = $entityMetadata[$label]['description'];
            }

            $candidates[$label] = $candidateText;
        }

        $match = $this->findBestMatch($question, $candidates, $threshold);

        if ($match) {
            return [
                'label' => $match['key'],
                'score' => $match['score'],
                'exact' => $match['exact'],
            ];
        }

        return null;
    }

    /**
     * Compute semantic similarities between query and all candidates
     *
     * Returns similarity scores for all candidates, useful for ranking
     * or when you need multiple matches.
     *
     * @param string $query Query text
     * @param array $candidates Candidate texts to compare
     * @return array Array of similarities: ['key' => ['score' => 0.85, 'text' => '...']]
     */
    public function computeSimilarities(
        string $query,
        array $candidates
    ): array {
        try {
            // Get query embedding
            $queryEmbedding = $this->getEmbedding($query);

            // Get all candidate embeddings
            $candidateTexts = array_values($candidates);
            $candidateEmbeddings = $this->getEmbeddings($candidateTexts);

            // Compute similarities
            $similarities = [];
            $keys = array_keys($candidates);

            foreach ($candidateEmbeddings as $index => $candidateEmbedding) {
                $key = $keys[$index];
                $score = $this->cosineSimilarity($queryEmbedding, $candidateEmbedding);

                $similarities[$key] = [
                    'score' => $score,
                    'text' => $candidates[$key],
                ];
            }

            // Sort by score (highest first)
            uasort($similarities, fn($a, $b) => $b['score'] <=> $a['score']);

            return $similarities;

        } catch (\Exception $e) {
            Log::error('Failed to compute similarities', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Search for matches in a vector store collection
     *
     * Uses the vector store's native search capabilities for better performance
     * when dealing with large candidate sets.
     *
     * @param string $query Query text
     * @param string $collection Collection name
     * @param float $threshold Minimum similarity score
     * @return array|null Best match from collection
     */
    private function searchInCollection(
        string $query,
        string $collection,
        float $threshold
    ): ?array {
        try {
            // Generate query embedding
            $queryEmbedding = $this->getEmbedding($query);

            // Search vector store
            $results = $this->vectorStore->search(
                collection: $collection,
                vector: $queryEmbedding,
                limit: 1,
                filter: [],
                scoreThreshold: $threshold
            );

            if (empty($results)) {
                return null;
            }

            $result = $results[0];

            return [
                'key' => $result['payload']['key'] ?? $result['id'],
                'score' => $result['score'],
                'exact' => false,
                'candidate_text' => $result['payload']['text'] ?? '',
                'method' => 'vector_search',
                'payload' => $result['payload'],
            ];

        } catch (\Exception $e) {
            Log::error('Vector store search failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Compute best match from candidates using in-memory similarity calculation
     *
     * @param string $query Query text
     * @param array $candidates Candidate texts
     * @param float $threshold Minimum similarity score
     * @return array|null Best match or null
     */
    private function computeBestMatch(
        string $query,
        array $candidates,
        float $threshold
    ): ?array {
        try {
            $similarities = $this->computeSimilarities($query, $candidates);

            foreach ($similarities as $key => $similarity) {
                if ($similarity['score'] >= $threshold) {
                    return [
                        'key' => $key,
                        'score' => $similarity['score'],
                        'exact' => false,
                        'candidate_text' => $similarity['text'],
                        'method' => 'embedding',
                    ];
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Compute best match failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get embedding for a single text (with caching)
     *
     * @param string $text Text to embed
     * @return array Vector embedding
     */
    private function getEmbedding(string $text): array
    {
        $cacheKey = md5($text);

        if (!isset($this->embeddingCache[$cacheKey])) {
            $this->embeddingCache[$cacheKey] = $this->embedding->embed($text);
        }

        return $this->embeddingCache[$cacheKey];
    }

    /**
     * Get embeddings for multiple texts (batch processing)
     *
     * @param array $texts Array of texts
     * @return array Array of embeddings
     */
    private function getEmbeddings(array $texts): array
    {
        // Check cache first
        $uncachedTexts = [];
        $uncachedIndices = [];

        foreach ($texts as $index => $text) {
            $cacheKey = md5($text);
            if (!isset($this->embeddingCache[$cacheKey])) {
                $uncachedTexts[] = $text;
                $uncachedIndices[] = $index;
            }
        }

        // Batch generate uncached embeddings
        if (!empty($uncachedTexts)) {
            $newEmbeddings = $this->embedding->embedBatch($uncachedTexts);

            foreach ($uncachedIndices as $i => $originalIndex) {
                $cacheKey = md5($uncachedTexts[$i]);
                $this->embeddingCache[$cacheKey] = $newEmbeddings[$i];
            }
        }

        // Return all embeddings in original order
        $embeddings = [];
        foreach ($texts as $text) {
            $cacheKey = md5($text);
            $embeddings[] = $this->embeddingCache[$cacheKey];
        }

        return $embeddings;
    }

    /**
     * Compute cosine similarity between two vectors
     *
     * Cosine similarity ranges from -1 to 1:
     * - 1.0: Identical vectors
     * - 0.0: Orthogonal (no similarity)
     * - -1.0: Opposite vectors
     *
     * @param array $vector1 First vector
     * @param array $vector2 Second vector
     * @return float Similarity score (0.0 - 1.0)
     */
    private function cosineSimilarity(array $vector1, array $vector2): float
    {
        if (count($vector1) !== count($vector2)) {
            throw new \InvalidArgumentException('Vectors must have same dimensions');
        }

        $dotProduct = 0.0;
        $magnitude1 = 0.0;
        $magnitude2 = 0.0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] * $vector1[$i];
            $magnitude2 += $vector2[$i] * $vector2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0.0 || $magnitude2 == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }

    /**
     * Normalize text for comparison
     *
     * @param string $text Text to normalize
     * @return string Normalized text
     */
    private function normalizeText(string $text): string
    {
        // Lowercase
        $text = strtolower($text);

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim
        $text = trim($text);

        return $text;
    }

    /**
     * Clear embedding cache
     *
     * Useful for testing or when memory is constrained
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->embeddingCache = [];
    }
}
