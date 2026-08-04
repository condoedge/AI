<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Contracts\FileContextEnricherInterface;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\Retrieval\NullReranker;
use Condoedge\Ai\Services\Retrieval\RerankerInterface;
use Illuminate\Support\Facades\Log;

/**
 * File Context Provider
 *
 * Provides unified file search across both physical documentation files
 * and database-backed files. Applies access control filtering and
 * transforms results into a standard format for AI context building.
 *
 * Key features:
 * - Searches both physical (documentation) and database file collections
 * - Detects explicit filename references in queries for targeted search
 * - Combines filename and semantic content search results
 * - Applies access filtering (physical files always pass through)
 * - Truncates snippets to configured length
 * - Sorts by relevance score descending
 *
 * @package Condoedge\Ai\Services\Context
 */
class FileContextProvider
{
    /**
     * Threshold for partial filename matches (0.3 is low to allow more results)
     */
    private const FILENAME_PARTIAL_THRESHOLD = 0.3;

    /**
     * The filename extractor instance
     */
    private readonly FilenameExtractor $filenameExtractor;

    /**
     * The file context enricher instance
     */
    private readonly FileContextEnricherInterface $enricher;

    /**
     * The reranker applied to the over-fetched candidate pool before truncation.
     */
    private readonly RerankerInterface $reranker;

    /**
     * Chunk store used to fetch a hit's index-adjacent neighbors for context-window
     * expansion. Optional: when null (e.g. in pure unit tests), the window is a no-op
     * and the snippet is assembled only from the chunks retrieval already returned.
     */
    private readonly ?ChunkStoreInterface $chunkStore;

    /**
     * Create a new FileContextProvider instance
     *
     * @param FileSearchService $searchService Service for searching file content
     * @param FileAccessResolverInterface $accessResolver Service for resolving file access
     * @param FilenameExtractor|null $filenameExtractor Service for extracting filenames from queries
     * @param FileContextEnricherInterface|null $enricher Service for enriching file results with priority/annotations
     * @param RerankerInterface|null $reranker Precision reranker; defaults to the container-bound
     *        reranker (NullReranker when none is bound), so reranking is always a single path.
     * @param ChunkStoreInterface|null $chunkStore Chunk store for context-window expansion;
     *        null disables the window (snippet assembled only from retrieved chunks).
     */
    public function __construct(
        private readonly FileSearchService $searchService,
        private readonly FileAccessResolverInterface $accessResolver,
        ?FilenameExtractor $filenameExtractor = null,
        ?FileContextEnricherInterface $enricher = null,
        ?RerankerInterface $reranker = null,
        ?ChunkStoreInterface $chunkStore = null
    ) {
        $this->filenameExtractor = $filenameExtractor ?? new FilenameExtractor();
        $this->enricher = $enricher ?? new DefaultFileContextEnricher();
        $this->reranker = $reranker ?? $this->resolveBoundReranker();
        $this->chunkStore = $chunkStore;
    }

    /**
     * Search for relevant files based on a question
     *
     * Searches across physical documentation and database file collections using
     * both filename-based and semantic content-based search. Combines results with
     * filename matches prioritized. Filters by access control and returns
     * standardized file references.
     *
     * Search strategy:
     * 1. Extract explicit filename references from query (e.g., "bariloche.txt")
     * 2. If filenames found, search by filename (high priority)
     * 3. Also search by content (semantic search)
     * 4. Combine results with filename matches first
     * 5. Apply different thresholds based on match type
     *
     * @param string $question The search query/question
     * @param mixed $user The user to check access for (typically Authenticatable)
     * @param array $options Additional search options:
     *   - collections: Array of collection names to search (default: both physical + database)
     *   - limit: Override max references limit
     *   - min_score: Override minimum relevance score
     * @return array Array of file references with standardized format
     * @throws \RuntimeException When security is enabled and user is null
     */
    public function searchRelevantFiles(string $question, mixed $user, array $options = []): array
    {
        // SECURITY: Require user when security is enabled
        $this->validateUserForSecurity($user);

        // Get configuration values
        $minScore = $options['min_score'] ?? config('ai.file_context.min_relevance_score', 0.4);
        $maxReferences = $options['limit'] ?? config('ai.file_context.max_references', 5);
        // Snippet budget (characters) and how many top chunks of the best file to
        // stitch into that snippet. A larger budget plus multiple chunks lets the
        // model quote the actual clause instead of a single ~200-char slice.
        $snippetLength = (int) config('ai.file_context.snippet_length', 1200);
        $snippetChunks = (int) config('ai.file_context.snippet_chunks', 3);

        // Step 1: Extract explicit filename references
        $extractedFilenames = $this->filenameExtractor->extract($question);

        // Step 2: Search by filename (if references found)
        // Note: Sequential searches are acceptable for typical use (1-2 filenames per query).
        // For queries with many filenames, consider adding batch search to FileSearchService.
        //
        // Each filename sub-search is wrapped independently so a filename-search failure
        // degrades to content-only rather than aborting the whole method.
        $filenameResults = [];
        foreach ($extractedFilenames as $filename) {
            try {
                $results = $this->searchService->searchByFilename($filename, [
                    'limit' => $maxReferences,
                    'include_relationships' => false,
                ]);
                $filenameResults = array_merge($filenameResults, $results);
            } catch (\Throwable $e) {
                Log::debug('FileContextProvider: filename sub-search failed, continuing', [
                    'filename' => $filename,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Step 3: Enhance query with conversation context for follow-up questions
        $conversationContext = $options['conversation_context'] ?? [];
        $enhancedQuery = $this->enhanceQueryWithContext($question, $conversationContext);

        // Step 4: Search by content (semantic search)
        //
        // The content sub-search is wrapped independently so a content-search failure
        // degrades to filename-only rather than aborting. min_score is forwarded so weak
        // chunks are cut early in Qdrant.
        $contentResults = [];
        try {
            $contentResults = $this->searchService->searchByContent($enhancedQuery, [
                'limit' => $maxReferences * 3,
                'min_score' => $minScore,
                'include_relationships' => false,
            ]);
        } catch (\Throwable $e) {
            Log::debug('FileContextProvider: content sub-search failed, continuing', [
                'error' => $e->getMessage(),
            ]);
        }

        // Step 5: Combine results, filename matches first
        $combinedResults = $this->combineSearchResults($filenameResults, $contentResults);

        if (empty($combinedResults)) {
            return [];
        }

        // Step 6: Apply access control
        $fileIds = array_column($combinedResults, 'file_id');
        $accessibleFileIds = $this->accessResolver->filterAccessibleFileIds($fileIds, $user);

        // Step 7: Filter by access and apply score thresholds
        $filteredResults = [];
        foreach ($combinedResults as $result) {
            $fileId = $result['file_id'];

            // Check if file is accessible
            if (!in_array($fileId, $accessibleFileIds, true)) {
                continue;
            }

            $isFilenameMatch = $result['match_type'] === 'filename';
            $score = $result['score'];

            if ($isFilenameMatch) {
                // Exact filename match (1.0) always included
                // Partial filename match (0.85) uses lower threshold
                $isExactMatch = $score >= 1.0;
                if (!$isExactMatch && $score < self::FILENAME_PARTIAL_THRESHOLD) {
                    continue;
                }
            } else {
                // Content match uses standard threshold
                if ($score < $minScore) {
                    continue;
                }
            }

            $filteredResults[] = $result;
        }

        // Sort by the cross-collection rank-fusion score when FileSearchService
        // merged collections (fusion_score present), otherwise by raw similarity.
        // The relevance threshold above still gates on raw 'score', so ordering by
        // rank fusion never lets a sub-threshold match through.
        usort($filteredResults, function ($a, $b) {
            $aRank = $a['fusion_score'] ?? $a['score'];
            $bRank = $b['fusion_score'] ?? $b['score'];
            return $bRank <=> $aRank;
        });

        // Re-rank the over-fetched, access-filtered pool for precision BEFORE the
        // final limit. Retrieval over-fetches (content candidates at limit*3 plus
        // filename matches), so the genuinely-best document is often present but
        // ranked below the top-$maxReferences by raw cosine alone. The reranker
        // re-orders by (query, snippet) relevance and returns the top-$maxReferences.
        //
        // Single path: the bound reranker is always invoked. Its contract guarantees
        // it never throws and degrades to input order, and the default binding is a
        // NullReranker, so when reranking is disabled/unavailable this reduces to the
        // score-sorted top-$maxReferences.
        $filteredResults = $this->rerankResults($question, $filteredResults, $maxReferences);

        // Context window (small-to-big): how many index-adjacent neighbor chunks to
        // stitch around the hit (per side). 0 disables expansion (identical to the
        // legacy behaviour). Read once so the per-result map below is uniform.
        $contextWindow = (int) config('ai.file_context.context_window', 2);

        // The window only expands anything when a chunk store is available to fetch
        // neighbors from; without one (e.g. before DI wiring, or a deployment with no
        // store bound) expansion is a no-op and snippet_chunks must be honoured exactly.
        $windowActive = $contextWindow > 0 && $this->chunkStore !== null;

        // Transform to standard format
        $transformedResults = array_map(function ($result) use ($snippetLength, $snippetChunks, $contextWindow, $windowActive) {
            $chunk = $result['best_chunk'];
            $fileId = $result['file_id'];
            $source = $this->accessResolver->isPhysicalFile($fileId) ? 'physical' : 'database';

            // Pull the hit's index-adjacent neighbors (+/-N by chunk_index) from the
            // same file/collection — even neighbors the vector search did not return
            // — so the model reads the full passage, not a lone fragment.
            $result = $this->expandWithContextWindow($result, $contextWindow);

            // When the window actually pulled neighbors in, widen the stitch budget to
            // at least the window span (2N+1) so those neighbors are not capped out by
            // a smaller snippet_chunks setting. snippet_length is still the hard
            // character cap. When no window was applied, snippet_chunks is honoured
            // unchanged so the legacy single-path behaviour is identical.
            $stitchChunks = $windowActive
                ? max($snippetChunks, 2 * $contextWindow + 1)
                : $snippetChunks;

            // Assemble the snippet from the best file's top chunks (up to the
            // budget) so the model sees the actual clause, not one ~200-char slice.
            $snippet = $this->assembleSnippet($result, $snippetLength, $stitchChunks);

            return [
                'file_id' => $fileId,
                'filename' => $chunk->fileName,
                'snippet' => $snippet,
                'relevance' => $result['score'],
                'chunk_index' => $chunk->chunkIndex,
                'source' => $source,
                'match_type' => $result['match_type'] ?? 'content',
            ];
        }, $filteredResults);

        // Apply project-specific enrichment (priority tiers, annotations, etc.)
        $transformedResults = $this->enricher->enrich($transformedResults, $question, $user);

        // Re-sort by priority_score if enricher added it, otherwise keep relevance order
        usort($transformedResults, function ($a, $b) {
            $aPriority = $a['priority_score'] ?? $a['relevance'];
            $bPriority = $b['priority_score'] ?? $b['relevance'];
            return $bPriority <=> $aPriority;
        });

        return $transformedResults;
    }

    /**
     * Enumerate the user's accessible files as a real, ACL-respecting answer.
     *
     * This is the file-listing capability ("which files do I have access to?"):
     * instead of semantic snippets, it returns the user's accessible files
     * (id, name, type) so the assistant can list them. Access is resolved by the
     * same FileAccessResolver path as every other file operation, so a user can
     * only ever see files they may access; team scope is enforced inside the
     * resolver. Returns descriptors in the same shape as searchRelevantFiles so
     * the response/enricher layers can consume them uniformly.
     *
     * @param mixed $user The user to enumerate accessible files for (typically Authenticatable)
     * @param array $options Additional options:
     *   - limit: Override max files returned (default: file_context.enumeration.max_files)
     * @return array Array of file references (id, name/filename, type, source)
     * @throws \RuntimeException When security is enabled and user is null
     */
    public function enumerateAccessibleFiles(mixed $user, array $options = []): array
    {
        // SECURITY: Require user when security is enabled (same gate as search).
        $this->validateUserForSecurity($user);

        $limit = (int) ($options['limit']
            ?? config('ai.file_context.enumeration.max_files', 50));
        $limit = $limit > 0 ? $limit : null;

        $descriptors = $this->accessResolver->enumerateAccessibleFiles($user, $limit);

        if (empty($descriptors)) {
            return [];
        }

        return array_map(function (array $descriptor) {
            $fileId = $descriptor['file_id'];

            return [
                'file_id' => $fileId,
                'filename' => $descriptor['name'] ?? '',
                'type' => $descriptor['type'] ?? '',
                'snippet' => '',
                // HONEST SCORING: enumeration is an ACL listing, not retrieval —
                // no similarity was ever computed, so no relevance is claimed.
                // A fabricated 1.0 here previously rendered dozens of unrelated
                // files as "relevance: 100%" with empty snippets in the prompt.
                'relevance' => null,
                // Listing entries are names/types only and must NEVER be treated
                // as content grounding by prompt sections or enforcement logic.
                'listing_only' => true,
                'chunk_index' => 0,
                'source' => $this->accessResolver->isPhysicalFile($fileId) ? 'physical' : 'database',
                'match_type' => 'enumeration',
            ];
        }, $descriptors);
    }

    /**
     * Get file-enumeration context for prompt building.
     *
     * Mirrors getFileContext() but lists the user's accessible files rather than
     * running semantic retrieval, for file-listing intents. The returned shape is
     * identical so the downstream prompt/response layers are unchanged.
     *
     * @param mixed $user The user to enumerate accessible files for
     * @param array $options Additional options (see enumerateAccessibleFiles)
     * @return array Context array with: relevant_files, file_count, has_physical, has_database, enumeration
     * @throws \RuntimeException When security is enabled and user is null
     */
    public function getFileEnumerationContext(mixed $user, array $options = []): array
    {
        $files = $this->enumerateAccessibleFiles($user, $options);

        $hasPhysical = false;
        $hasDatabase = false;
        foreach ($files as $file) {
            if (($file['source'] ?? '') === 'physical') {
                $hasPhysical = true;
            } else {
                $hasDatabase = true;
            }
            if ($hasPhysical && $hasDatabase) {
                break;
            }
        }

        return [
            'relevant_files' => $files,
            'file_count' => count($files),
            'has_physical' => $hasPhysical,
            'has_database' => $hasDatabase,
            'enumeration' => true,
        ];
    }

    /**
     * Build a single file reference for response metadata
     *
     * @param int $refNumber Reference number for citation
     * @param int|string $fileId The file ID
     * @param string $fileName The file name
     * @param string $snippet Content snippet
     * @param float $relevanceScore Relevance score
     * @param int $chunkIndex Chunk index within the file
     * @param string $source Source type ('physical' or 'database')
     * @return array Standardized file reference array
     */
    public function buildFileReference(
        int $refNumber,
        int|string $fileId,
        string $fileName,
        string $snippet,
        float $relevanceScore,
        int $chunkIndex,
        string $source
    ): array {
        return [
            'ref_number' => $refNumber,
            'file_id' => $fileId,
            'filename' => $fileName,
            'snippet' => $snippet,
            'relevance' => $relevanceScore,
            'chunk_index' => $chunkIndex,
            'source' => $source,
        ];
    }

    /**
     * Get file context for prompt building
     *
     * Returns a structured context array suitable for injecting into AI prompts,
     * with metadata about the types of files found.
     *
     * @param string $question The search query/question
     * @param mixed $user The user to check access for
     * @return array Context array with: relevant_files, file_count, has_physical, has_database
     * @throws \RuntimeException When security is enabled and user is null
     */
    public function getFileContext(string $question, mixed $user, array $options = []): array
    {
        // SECURITY: Require user when security is enabled
        $this->validateUserForSecurity($user);

        $relevantFiles = $this->searchRelevantFiles($question, $user, $options);

        // Analyze sources
        $hasPhysical = false;
        $hasDatabase = false;

        foreach ($relevantFiles as $file) {
            if ($file['source'] === 'physical') {
                $hasPhysical = true;
            } else {
                $hasDatabase = true;
            }

            // Early exit if we've found both types
            if ($hasPhysical && $hasDatabase) {
                break;
            }
        }

        return [
            'relevant_files' => $relevantFiles,
            'file_count' => count($relevantFiles),
            'has_physical' => $hasPhysical,
            'has_database' => $hasDatabase,
        ];
    }

    /**
     * Re-rank the candidate pool for precision and return the top $limit.
     *
     * Always delegates to the bound reranker — a single path. The RerankerInterface
     * contract guarantees the reranker never throws and degrades to the input order,
     * and the default binding is a NullReranker, so when reranking is unavailable
     * this is equivalent to the score-sorted top-$limit.
     *
     * Candidate text for scoring is the best chunk's content (the same snippet
     * surfaced to the user), falling back to the filename.
     *
     * @param string $query   The user's question used as the reranking query.
     * @param array  $results The access-filtered, score-sorted candidate pool.
     * @param int    $limit   Maximum references to keep.
     * @return array Top-$limit results.
     */
    private function rerankResults(string $query, array $results, int $limit): array
    {
        if (empty($results)) {
            return [];
        }

        $textOf = function (array $result): string {
            $chunk = $result['best_chunk'] ?? null;
            $content = is_object($chunk) && isset($chunk->content) ? (string) $chunk->content : '';
            if ($content === '') {
                $content = (string) ($result['filename'] ?? ($chunk->fileName ?? ''));
            }
            return $content;
        };

        return $this->reranker->rerank($query, $results, $textOf, $limit);
    }

    /**
     * Resolve the container-bound reranker, defaulting to a NullReranker when no
     * concrete RerankerInterface is bound (safe before DI wiring exists, e.g. in
     * pure unit tests). Used only when no reranker is injected via the constructor.
     */
    private function resolveBoundReranker(): RerankerInterface
    {
        try {
            if (function_exists('app') && app()->bound(RerankerInterface::class)) {
                $resolved = app(RerankerInterface::class);
                if ($resolved instanceof RerankerInterface) {
                    return $resolved;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the null reranker.
        }

        return new NullReranker();
    }

    /**
     * Combine filename and content search results.
     *
     * Deduplicates by file_id, keeping filename match if exists (higher priority).
     * Filename matches are preserved with their match_type for threshold decisions.
     *
     * @param array $filenameResults Results from filename-based search
     * @param array $contentResults Results from content-based search
     * @return array Combined results with match_type annotations
     */
    private function combineSearchResults(array $filenameResults, array $contentResults): array
    {
        $combined = [];

        // Add filename results first (they have priority)
        foreach ($filenameResults as $result) {
            $fileId = $result['file_id'];
            $combined[$fileId] = array_merge($result, ['match_type' => 'filename']);
        }

        // Add content results, but don't override filename matches
        foreach ($contentResults as $result) {
            $fileId = $result['file_id'];
            if (!isset($combined[$fileId])) {
                $combined[$fileId] = array_merge($result, ['match_type' => 'content']);
            }
        }

        return array_values($combined);
    }

    /**
     * Context-window ("small-to-big" / sentence-window) expansion.
     *
     * Vector search returns isolated hit chunks; a lone fragment rarely carries the
     * whole operative passage (e.g. art. 19's rule and art. 20's compensation clause
     * span consecutive chunks). This pulls the hit's INDEX-ADJACENT neighbors
     * (+/-$window by chunk_index, from the SAME file/collection) — even neighbors the
     * search did not independently return — and merges them into the result's
     * chunks[] so the snippet assembly stitches the full window in reading order.
     *
     * Edge cases handled here: $window <= 0 or no chunk store -> no-op; single-chunk
     * file or hit at index 0/last -> the window clamps to the file's real range; a
     * getFileChunks failure -> degrade to the already-retrieved chunks (retrieval is
     * never broken). The best chunk's own content is preserved (it is always among
     * the merged chunks and assembleSnippet admits it first).
     *
     * @param array $result A single search result (best_chunk + chunks[] + collection).
     * @param int   $window Neighbors to include per side (chunk_index distance).
     * @return array The result with chunks[] augmented by the windowed neighbors.
     */
    private function expandWithContextWindow(array $result, int $window): array
    {
        if ($window <= 0 || $this->chunkStore === null) {
            return $result;
        }

        $bestChunk = $result['best_chunk'] ?? null;
        if (!is_object($bestChunk) || !isset($bestChunk->chunkIndex)) {
            return $result;
        }
        $bestIndex = (int) $bestChunk->chunkIndex;

        $fileId = $result['file_id'] ?? null;
        if ($fileId === null) {
            return $result;
        }

        // The result's source collection (physical docs vs db files). The sentinel
        // primary key means "the chunk store's bound collection" -> pass null.
        $collection = $this->resolveResultCollection($result);

        try {
            $fileChunks = $this->chunkStore->getFileChunks($fileId, $collection);
        } catch (\Throwable $e) {
            Log::debug('FileContextProvider: context-window fetch failed, using retrieved chunks', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return $result;
        }

        if (count($fileChunks) <= 1) {
            return $result;
        }

        // Index the whole file by chunk_index so neighbors can be looked up and the
        // window can be clamped to the file's real index range.
        $byIndex = [];
        foreach ($fileChunks as $fc) {
            $byIndex[self::chunkIndexOf($fc)] = $fc;
        }

        $wantedIndices = $this->windowIndices($bestIndex, $window, array_keys($byIndex));

        // Merge the windowed neighbors into the already-retrieved chunks, deduped by
        // chunk_index (retrieved chunks win, preserving any per-chunk data they carry).
        $merged = is_array($result['chunks'] ?? null) ? $result['chunks'] : [];
        $presentIndices = [];
        foreach ($merged as $c) {
            $presentIndices[self::chunkIndexOf($c)] = true;
        }
        foreach ($wantedIndices as $idx) {
            if (!isset($presentIndices[$idx]) && isset($byIndex[$idx])) {
                $merged[] = $byIndex[$idx];
                $presentIndices[$idx] = true;
            }
        }

        $result['chunks'] = $merged;

        return $result;
    }

    /**
     * Select the chunk indices in [best-$window .. best+$window] that actually exist
     * in the file, sorted in reading order. Pure and side-effect free: it intersects
     * the symmetric window with the file's real index set, so it naturally clamps at
     * index 0 / the last index and reduces to just [$bestIndex] for a single-chunk
     * file or $window === 0.
     *
     * @param int        $bestIndex        The hit chunk's index.
     * @param int        $window           Neighbors to include per side.
     * @param array<int> $availableIndices Every chunk_index that exists in the file.
     * @return array<int> The selected indices in ascending order.
     */
    private function windowIndices(int $bestIndex, int $window, array $availableIndices): array
    {
        $low = $bestIndex - $window;
        $high = $bestIndex + $window;

        $selected = array_filter(
            $availableIndices,
            static fn ($idx) => $idx >= $low && $idx <= $high
        );

        $selected = array_map('intval', $selected);
        sort($selected);

        return array_values($selected);
    }

    /**
     * Map a search result's source collection to the chunk-store collection argument.
     *
     * FileSearchService tags each result with the collection it came from; its
     * sentinel for the chunk store's bound (primary) collection is searched without
     * an explicit name, so it resolves to null here. An explicit additional
     * collection (e.g. 'documentation_chunks' for physical docs) is passed through so
     * neighbors are fetched from the SAME collection as the hit.
     */
    private function resolveResultCollection(array $result): ?string
    {
        $collection = $result['collection'] ?? null;

        // Sentinel for the bound/primary collection in FileSearchService.
        if (!is_string($collection) || $collection === '' || $collection === '__primary__') {
            return null;
        }

        return $collection;
    }

    /**
     * Assemble the snippet for a result from its best file's top chunks.
     *
     * Grounding quality: a single ~200-char slice of one chunk is rarely enough
     * for the model to quote an actual clause. Instead, CENTER the snippet on the
     * most-relevant (best) chunk: include it first, then fill the remaining slots
     * from its reading-order NEIGHBORS (smallest |index − bestIndex| first), and
     * finally sort the selected chunks back into reading order for a coherent read.
     *
     * Centering — not "fill from the globally-lowest-index chunks" — is what keeps
     * a deep operative clause (e.g. art. 19 at chunk index 24) in the snippet: a
     * document's cover/preamble chunks (index 0,1,2) would otherwise lead and the
     * length budget would truncate the actual rule off. The stitch is best-aware
     * so the operative clause survives even when the budget fits only one chunk.
     * Degrades safely to the best chunk's content when per-chunk data is missing.
     *
     * @param array $result   A single search result (best_chunk + chunks[]).
     * @param int   $maxLength Total snippet character budget.
     * @param int   $maxChunks Maximum number of chunks to stitch together.
     * @return string The assembled, length-capped snippet.
     */
    private function assembleSnippet(array $result, int $maxLength, int $maxChunks): string
    {
        $bestChunk = $result['best_chunk'] ?? null;
        $bestContent = is_object($bestChunk) && isset($bestChunk->content)
            ? (string) $bestChunk->content
            : '';

        $chunks = $result['chunks'] ?? null;

        // Single-chunk path: only one chunk to show, or multi-chunk disabled.
        if ($maxChunks <= 1 || !is_array($chunks) || count($chunks) <= 1) {
            return $this->truncateSnippet($bestContent, $maxLength);
        }

        // Order the retrieved chunks by reading order (chunk index) so neighbor
        // distances and the final stitch are computed against a coherent sequence.
        $ordered = $chunks;
        usort($ordered, static fn ($a, $b) => self::chunkIndexOf($a) <=> self::chunkIndexOf($b));

        // Anchor on the best chunk's index. When the best_chunk carries no index,
        // fall back to the lowest-index retrieved chunk so the centering logic
        // still has a stable anchor (degrades to the legacy reading-order lead).
        $bestIndex = is_object($bestChunk) && isset($bestChunk->chunkIndex)
            ? (int) $bestChunk->chunkIndex
            : self::chunkIndexOf($ordered[0]);

        // Select chunks CENTERED on the best index: the best chunk first (distance
        // 0), then its nearest reading-order neighbors. Ties (a neighbor equally
        // far before/after) resolve toward the earlier index for a natural read.
        $candidates = $ordered;
        usort($candidates, static function ($a, $b) use ($bestIndex) {
            $da = abs(self::chunkIndexOf($a) - $bestIndex);
            $db = abs(self::chunkIndexOf($b) - $bestIndex);
            return $da <=> $db ?: self::chunkIndexOf($a) <=> self::chunkIndexOf($b);
        });

        $selected = [];
        $selectedContents = [];
        foreach ($candidates as $c) {
            if (count($selected) >= $maxChunks) {
                break;
            }
            $content = self::chunkContentOf($c);
            if ($content === '' || in_array($content, $selectedContents, true)) {
                continue;
            }
            $selected[] = $c;
            $selectedContents[] = $content;
        }

        if (empty($selected)) {
            return $this->truncateSnippet($bestContent, $maxLength);
        }

        // Re-sort the chosen chunks by reading order for the final stitch so the
        // model reads them top-to-bottom as they appear in the document.
        usort($selected, static fn ($a, $b) => self::chunkIndexOf($a) <=> self::chunkIndexOf($b));

        return $this->stitchCenteredOnBest($selected, $bestIndex, $maxLength);
    }

    /**
     * Stitch reading-order-sorted chunks into one snippet, guaranteeing the best
     * chunk's content is admitted first so a tight length budget can never truncate
     * the operative clause off in favour of a leading cover/preamble chunk.
     *
     * The best chunk seeds the budget; remaining chunks are then added in reading
     * order while they still fit. The output preserves reading order (the best
     * chunk appears in its natural position among the admitted chunks).
     *
     * @param array $selected  Chunks already sorted by reading order (chunk index).
     * @param int   $bestIndex The anchor (best) chunk index.
     * @param int   $maxLength Total snippet character budget.
     */
    private function stitchCenteredOnBest(array $selected, int $bestIndex, int $maxLength): string
    {
        $separator = "\n…\n";

        // Locate the best chunk among the reading-order-sorted selection.
        $bestPos = null;
        foreach ($selected as $i => $c) {
            if (self::chunkIndexOf($c) === $bestIndex) {
                $bestPos = $i;
                break;
            }
        }
        if ($bestPos === null) {
            $bestPos = 0;
        }

        // Seed the budget with the best chunk (always admitted, possibly truncated
        // to the full budget if it alone exceeds it).
        $admitted = [$bestPos => $this->truncateSnippet(self::chunkContentOf($selected[$bestPos]), $maxLength)];
        $used = mb_strlen($admitted[$bestPos], 'UTF-8');

        // Add the remaining chunks in reading order while they fit, accounting for
        // the separator each addition introduces.
        foreach ($selected as $i => $c) {
            if ($i === $bestPos) {
                continue;
            }
            $content = self::chunkContentOf($c);
            if ($content === '') {
                continue;
            }
            $cost = mb_strlen($content, 'UTF-8') + mb_strlen($separator, 'UTF-8');
            if ($used + $cost > $maxLength) {
                continue;
            }
            $admitted[$i] = $content;
            $used += $cost;
        }

        // Emit in reading order (keys are the reading-order positions).
        ksort($admitted);

        return implode($separator, $admitted);
    }

    /**
     * Extract a chunk's content from either an object chunk or an array wrapper.
     */
    private static function chunkContentOf(mixed $chunk): string
    {
        if (is_object($chunk) && isset($chunk->content)) {
            return (string) $chunk->content;
        }
        if (is_array($chunk)) {
            $inner = $chunk['chunk'] ?? null;
            if (is_object($inner) && isset($inner->content)) {
                return (string) $inner->content;
            }
            if (isset($chunk['content'])) {
                return (string) $chunk['content'];
            }
        }
        return '';
    }

    /**
     * Extract a chunk's index from either an object chunk or an array wrapper.
     */
    private static function chunkIndexOf(mixed $chunk): int
    {
        if (is_object($chunk) && isset($chunk->chunkIndex)) {
            return (int) $chunk->chunkIndex;
        }
        if (is_array($chunk)) {
            $inner = $chunk['chunk'] ?? null;
            if (is_object($inner) && isset($inner->chunkIndex)) {
                return (int) $inner->chunkIndex;
            }
            if (isset($chunk['chunk_index'])) {
                return (int) $chunk['chunk_index'];
            }
        }
        return 0;
    }

    /**
     * Truncate a snippet to the configured maximum length
     *
     * Length is measured in characters (multibyte-safe) so the budget matches the
     * chunker's character budgets and does not split a UTF-8 sequence mid-byte.
     *
     * @param string $content The content to truncate
     * @param int $maxLength Maximum length in characters
     * @return string Truncated content with ellipsis if needed
     */
    private function truncateSnippet(string $content, int $maxLength): string
    {
        if (mb_strlen($content, 'UTF-8') <= $maxLength) {
            return $content;
        }

        return mb_substr($content, 0, $maxLength, 'UTF-8') . '...';
    }

    /**
     * Validate that user is provided when security is enabled
     *
     * @param mixed $user The user to validate
     * @throws \RuntimeException When security is enabled and user is null
     */
    private function validateUserForSecurity(mixed $user): void
    {
        if ($this->accessResolver->shouldEnforceSecurity() && $user === null) {
            throw new \RuntimeException('User required for file context retrieval');
        }
    }

    /**
     * Enhance search query with conversation context for follow-up questions
     *
     * When a question appears to be a follow-up (referencing "those", "them", etc.)
     * and there's a focused entity from the conversation context, the query is
     * enhanced by prepending the entity context for better search relevance.
     *
     * @param string $question The original search question
     * @param array $conversationContext Context from the conversation including:
     *   - focused_entity: The main entity being discussed (e.g., "Team", "User")
     *   - last_answer_summary: Summary of the previous answer
     * @return string The enhanced query or original question if no enhancement needed
     */
    private function enhanceQueryWithContext(string $question, array $conversationContext): string
    {
        if (empty($conversationContext)) {
            return $question;
        }

        // Check if this looks like a follow-up question
        if (!$this->isFollowUpQuestion($question)) {
            return $question;
        }

        // Add focused entity context
        if (!empty($conversationContext['focused_entity'])) {
            $entity = $conversationContext['focused_entity'];
            return "{$entity}: {$question}";
        }

        return $question;
    }

    /**
     * Detect if question is a follow-up referencing previous results
     *
     * Looks for common patterns that indicate the question refers to
     * previously mentioned items, such as "those", "them", "these", etc.
     *
     * @param string $question The question to analyze
     * @return bool True if the question appears to be a follow-up
     */
    private function isFollowUpQuestion(string $question): bool
    {
        $followUpPatterns = ['those', 'them', 'these', 'which of', 'any of', 'the same'];
        $questionLower = strtolower($question);

        foreach ($followUpPatterns as $pattern) {
            if (str_contains($questionLower, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
