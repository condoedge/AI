<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Contracts\FileContextEnricherInterface;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Retrieval\DocumentScope;
use Condoedge\Ai\Services\Retrieval\FileReferenceOrder;
use Condoedge\Ai\Support\Audiences;
use Condoedge\Ai\Support\DocumentMap;
use Condoedge\Ai\Support\DocumentTitle;
use Condoedge\Ai\Support\PipelineObserverScope;
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
     * Above this many accessible DB file ids the ACL is not sent as a vector-store
     * pre-filter (request size); the access post-filter still enforces it.
     */
    private const ACL_PREFILTER_MAX_IDS = 10000;

    /**
     * Search option: a scratch memo (an \ArrayObject the CALLER creates) shared by
     * the searches of ONE turn's fan-out. Every angle asks the same things about
     * the same user and the same files — which DB files the user may access (the
     * vector-search pre-filter), whether a returned file passes the access gate,
     * and a file's chunks for the snippet window — so each is resolved once per
     * turn instead of once per angle (measured live 2026-10-06: ~3 s of a ~9 s
     * stage on 7 angles). Entries are keyed by the user they were resolved for;
     * the memo lives exactly as long as the caller keeps it (one fan-out), never
     * on this service. Absent = every search resolves everything itself.
     */
    public const TURN_MEMO = 'turn_memo';

    /**
     * Search option (bool): each reference also carries `snippet_chunks` — the
     * chunks its snippet is made of, chunk_index => text, in reading order (the
     * snippet is exactly those texts joined by SNIPPET_SEPARATOR). For the caller
     * that MERGES passages of one file: two passages whose windows overlap can then
     * be joined into one, each chunk printed once (eval p18-B rights t0: art.
     * 12–13 and 15–16 each reached the prompt twice). The map repeats the
     * snippet's text, so it is emitted only when asked for and the caller strips it
     * before the references leave the retrieval layer.
     */
    public const PASSAGE_CHUNKS = 'passage_chunks';

    /**
     * Search option — and TURN MEMO key: the turn's {@see DocumentScope} (the
     * record the question is about). Every search of UPLOADED files this provider
     * makes is narrowed to it; project files never are. The caller resolves it
     * once per turn and either passes it or leaves it in the turn memo, where
     * every search of the turn — passages, rankings, selected passages — finds
     * the same one. Absent or unknown: nothing is narrowed.
     */
    public const SCOPE = 'document_scope';

    /** Joins the chunks stitched into one snippet. */
    public const SNIPPET_SEPARATOR = "\n…\n";

    /** Key of a snippet part whose chunk index is unknown (a result without per-chunk data). */
    private const UNINDEXED_CHUNK = -1;

    /**
     * The filename extractor instance
     */
    private readonly FilenameExtractor $filenameExtractor;

    /**
     * The file context enricher instance
     */
    private readonly FileContextEnricherInterface $enricher;

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
     * @param ChunkStoreInterface|null $chunkStore Chunk store for context-window expansion;
     *        null disables the window (snippet assembled only from retrieved chunks).
     */
    public function __construct(
        private readonly FileSearchService $searchService,
        private readonly FileAccessResolverInterface $accessResolver,
        ?FilenameExtractor $filenameExtractor = null,
        ?FileContextEnricherInterface $enricher = null,
        ?ChunkStoreInterface $chunkStore = null
    ) {
        $this->filenameExtractor = $filenameExtractor ?? new FilenameExtractor();
        $this->enricher = $enricher ?? new DefaultFileContextEnricher();
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
     *   - conversation_context: the turn's conversation context (focused_entity)
     *   - follow_up_reference: QU's followUpReference for this turn (bool)
     * @return array Array of file references with standardized format
     * @throws \RuntimeException When security is enabled and user is null
     */
    public function searchRelevantFiles(string $question, mixed $user, array $options = []): array
    {
        // SECURITY: Require user when security is enabled
        $securityEnforced = $this->validateUserForSecurity($user);

        $memo = ($options[self::TURN_MEMO] ?? null) instanceof \ArrayObject ? $options[self::TURN_MEMO] : null;

        // Get configuration values
        $minScore = $options['min_score'] ?? config('ai.file_context.min_relevance_score', 0.4);
        $maxReferences = $options['limit'] ?? config('ai.file_context.max_references', 5);
        // Snippet budget (characters) and how many top chunks of the best file to
        // stitch into that snippet. A larger budget plus multiple chunks lets the
        // model quote the actual clause instead of a single ~200-char slice.
        // Fallback mirrors the package default: it must fit the ±context_window stitch.
        $snippetLength = (int) config('ai.file_context.snippet_length', 6000);
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
                ] + $this->audienceOptions($user));
                $filenameResults = array_merge($filenameResults, $results);
            } catch (\Throwable $e) {
                Log::debug('FileContextProvider: filename sub-search failed, continuing', [
                    'filename' => $filename,
                    'error' => $e->getMessage(),
                ]);
                // Observation only: the recovered failure reaches the turn trace.
                PipelineObserverScope::current()->stageError('file_context', $e);
            }
        }

        // Step 3: Enhance query with conversation context for follow-up questions
        // (follow-up = the QU judgment the caller threads in, never a word list)
        $conversationContext = is_array($options['conversation_context'] ?? null) ? $options['conversation_context'] : [];
        $enhancedQuery = $this->enhanceQueryWithContext(
            $question,
            $conversationContext,
            ($options['follow_up_reference'] ?? false) === true
        );

        // Step 4: Search by content (semantic search)
        //
        // The content sub-search is wrapped independently so a content-search failure
        // degrades to filename-only rather than aborting. min_score is forwarded so weak
        // chunks are cut early in Qdrant.
        $contentResults = [];
        $articleNumbers = array_values(array_filter((array) ($options['article_numbers'] ?? []), 'is_scalar'));
        try {
            $searchOptions = [
                'limit' => $maxReferences * 3,
                'min_score' => $minScore,
                'include_relationships' => false,
            ];
            // Exact-article angle (Phase 7.2): restrict to chunks defining these articles.
            if ($articleNumbers !== []) {
                $searchOptions['article_numbers'] = $articleNumbers;
            }
            // ACL pre-filter (Phase 7.3) — the access post-filter below stays the gate.
            $aclFileIds = $securityEnforced ? $this->accessibleFileIdsForPrefilter($user, $memo) : null;
            if ($aclFileIds !== null) {
                $searchOptions['acl_file_ids'] = $aclFileIds;
            }
            $searchOptions += $this->scopeOptions($options, $memo) + $this->audienceOptions($user);

            $contentResults = $this->searchService->searchByContent($enhancedQuery, $searchOptions);
        } catch (\Throwable $e) {
            Log::debug('FileContextProvider: content sub-search failed, continuing', [
                'error' => $e->getMessage(),
            ]);
            // Observation only: a vector-store failure (timeout / 5xx) degrades the
            // turn to "no documents" — the trace must show it was not "none found".
            PipelineObserverScope::current()->stageError('file_context', $e);
        }

        // Step 5: Combine results, filename matches first
        $combinedResults = $this->combineSearchResults($filenameResults, $contentResults);

        if (empty($combinedResults)) {
            return [];
        }

        // Step 6: Apply access control
        $fileIds = array_column($combinedResults, 'file_id');
        $accessibleFileIds = $this->accessibleAmong($fileIds, $user, $memo);

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

        // Order the pool with the same rule as the final list (FileReferenceOrder):
        // explicit filename matches, then fused results by the cross-collection
        // rank-fusion score (FileSearchService sets fusion_score on EVERY content
        // result of a fused search, never on filename matches), then raw
        // similarity — scales are never mixed. The relevance threshold above still
        // gates on raw 'score', so ordering by rank fusion never lets a
        // sub-threshold match through.
        $filteredResults = FileReferenceOrder::sort($filteredResults);

        // Cut the over-fetched, access-filtered pool (content candidates at limit*3
        // plus filename matches) to the score-sorted top-$maxReferences.
        $filteredResults = array_slice($filteredResults, 0, max(0, $maxReferences));

        // Context window (small-to-big): how many index-adjacent neighbor chunks to
        // stitch around the hit (per side). 0 disables expansion (identical to the
        // legacy behaviour). Read once so the per-result map below is uniform.
        $contextWindow = (int) config('ai.file_context.context_window', 2);

        // The window only expands anything when a chunk store is available to fetch
        // neighbors from; without one (e.g. before DI wiring, or a deployment with no
        // store bound) expansion is a no-op and snippet_chunks must be honoured exactly.
        $windowActive = $contextWindow > 0 && $this->chunkStore !== null;

        // Distinct passages per file this angle may emit (Phase 7): 1 (default) is
        // the legacy one-reference-per-file. With one article per chunk, the
        // runner-up article of the same file is often as relevant as the best one
        // (0.425 vs 0.427) and was structurally lost; the cross-angle merge in
        // AiManager asks for up to its per-file budget.
        $chunksPerFile = max(1, (int) ($options['chunks_per_file'] ?? 1));
        $buildReference = $this->referenceBuilder(
            $snippetLength,
            $snippetChunks,
            $contextWindow,
            $windowActive,
            $articleNumbers,
            $memo,
            ($options[self::PASSAGE_CHUNKS] ?? false) === true
        );

        // Transform to standard format
        $transformedResults = [];
        foreach ($filteredResults as $result) {
            $transformedResults[] = $buildReference($result);

            foreach ($this->additionalPassages($result, $chunksPerFile, $minScore, $contextWindow) as $extra) {
                $transformedResults[] = $buildReference($extra);
            }
        }

        // Apply project-specific enrichment (priority tiers, annotations, etc.)
        $transformedResults = $this->enricher->enrich($transformedResults, $question, $user);

        // Final order (FileReferenceOrder): scale tier, then that tier's score
        // (relevance — references carry no fusion_score) DESC, then the enricher's
        // priority_score as a TIEBREAK only (stable). Sorting by priority_score
        // first let a host boost outrank a far more relevant document.
        return FileReferenceOrder::sort($transformedResults);
    }

    /**
     * What a search of UPLOADED files is told about the turn: its scope (the
     * option, else the one left in the turn memo — {@see self::SCOPE}) and the
     * memo the store keeps the representatives of duplicate passages in.
     *
     * @return array{scope?: array{focus: string, ancestors: list<string>}, memo?: \ArrayObject}
     */
    private function scopeOptions(array $options, ?\ArrayObject $memo): array
    {
        $scope = $options[self::SCOPE] ?? ($memo !== null && $memo->offsetExists(self::SCOPE) ? $memo[self::SCOPE] : null);
        $filter = $scope instanceof DocumentScope ? $scope->filter() : null;

        return ($filter !== null ? ['scope' => $filter] : []) + ($memo !== null ? ['memo' => $memo] : []);
    }

    /**
     * Other DISTINCT passages of one file result for the same angle: chunks the
     * search returned with their own score over the relevance threshold, outside
     * the ±context_window of every already-emitted passage (whose snippet already
     * holds them), best first, up to $chunksPerFile - 1. Each is returned as a
     * result centred on that chunk with its OWN score.
     *
     * @return list<array>
     */
    private function additionalPassages(array $result, int $chunksPerFile, float $minScore, int $contextWindow): array
    {
        $scores = is_array($result['chunk_scores'] ?? null) ? $result['chunk_scores'] : [];
        $best = $result['best_chunk'] ?? null;
        if ($chunksPerFile <= 1 || $scores === [] || !is_object($best) || ($result['match_type'] ?? 'content') !== 'content') {
            return [];
        }

        $byIndex = [];
        foreach ((array) ($result['chunks'] ?? []) as $chunk) {
            if (is_object($chunk) && isset($chunk->chunkIndex)) {
                $byIndex[(int) $chunk->chunkIndex] = $chunk;
            }
        }

        arsort($scores);
        $selected = [(int) $best->chunkIndex];
        $extras = [];
        foreach ($scores as $index => $score) {
            if (count($selected) >= $chunksPerFile) {
                break;
            }
            $index = (int) $index;
            if ((float) $score < $minScore || !isset($byIndex[$index])) {
                continue;
            }
            foreach ($selected as $taken) {
                if (abs($index - $taken) <= max(0, $contextWindow)) {
                    continue 2;
                }
            }

            $extra = $result;
            $extra['best_chunk'] = $byIndex[$index];
            $extra['score'] = (float) $score;
            $extras[] = $extra;
            $selected[] = $index;
        }

        return $extras;
    }

    /**
     * The single reference builder: window-expands the result around its best
     * chunk, stitches the snippet and attaches the chunk's structural locator.
     */
    private function referenceBuilder(int $snippetLength, int $snippetChunks, int $contextWindow, bool $windowActive, array $articleNumbers, ?\ArrayObject $memo = null, bool $withPassageChunks = false): \Closure
    {
        return function ($result) use ($snippetLength, $snippetChunks, $contextWindow, $windowActive, $articleNumbers, $memo, $withPassageChunks) {
            $chunk = $result['best_chunk'];
            $fileId = $result['file_id'];
            $source = $this->accessResolver->isPhysicalFile($fileId) ? 'physical' : 'database';

            // The chunk list of the file is read ONCE for this reference (once per
            // turn when the angles share a memo): the window stitches the hit's
            // neighbours from it and the document's title is read off its first chunk.
            $reads = $memo ?? new \ArrayObject();

            // Pull the hit's index-adjacent neighbors (+/-N by chunk_index) from the
            // same file/collection — even neighbors the vector search did not return
            // — so the model reads the full passage, not a lone fragment.
            $result = $this->expandWithContextWindow($result, $contextWindow, $reads);

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
            $passageChunks = $this->assembleSnippetChunks($result, $snippetLength, $stitchChunks);
            $snippet = implode(self::SNIPPET_SEPARATOR, $passageChunks);

            $reference = [
                'file_id' => $fileId,
                'filename' => $chunk->fileName,
                'snippet' => $snippet,
                'relevance' => $result['score'],
                'chunk_index' => $chunk->chunkIndex,
                'source' => $source,
                'match_type' => $result['match_type'] ?? 'content',
            ] + $this->chunkLocator($chunk, $articleNumbers);

            // The document's OWN TITLE (its first top-level heading): what lets the
            // answer name its source — the file name alone does not say what the
            // document is. Absent when the document has none.
            $title = $this->documentTitle($result, $reads);
            if ($title !== '') {
                $reference['document_title'] = $title;
            }

            // WHOSE DOCUMENT (uploaded files only): the record the file is filed on
            // and the records that own it — keys, resolved to names by the caller —
            // and how many other copies of this passage under that owner the
            // distinct search folded into it. A project file carries none.
            if ($source !== 'physical' && array_key_exists('owner', $result)) {
                $reference['owner'] = is_string($result['owner']) ? $result['owner'] : null;
                $reference['ancestors'] = array_values(array_filter((array) ($result['ancestors'] ?? []), 'is_string'));
            }
            $copies = (int) ($result['duplicates'][(int) $chunk->chunkIndex] ?? 0);
            if ($source !== 'physical' && $copies > 0) {
                $reference['duplicates'] = $copies;
            }
            // The OTHER records the same text is filed on (one passage per text).
            $coOwners = $result['co_owners'][(int) $chunk->chunkIndex] ?? null;
            if ($source !== 'physical' && is_array($coOwners) && $coOwners !== []) {
                $reference['co_owners'] = array_values($coOwners);
            }

            // 'relevance' is the raw cosine (ranking, display and threshold).
            // fusion_score is deliberately NOT carried (Task 1.29): it is a
            // FILE-level number relative to THIS angle's result lists (the sum of
            // the file's chunk ranks), so every chunk of a document that owns the
            // list gets the same value, and angles cannot be compared by it. Carried
            // into the cross-angle merge it ranked a file's chunks by "how dominant
            // the file was in that angle" instead of by their own similarity (every
            // chunk of one by-law traced as 0.5560599). Its job — cutting this angle's pool
            // above — is done; every collection is searched with the SAME query
            // embedding, so cosine is comparable across collections from here on.

            // This angle's own similarity for each retrieved chunk of the file, so
            // the cross-angle merge can fuse the angles' per-file chunk rankings
            // (AiManager strips it before the context reaches the prompt).
            if (!empty($result['chunk_scores']) && is_array($result['chunk_scores'])) {
                $reference['chunk_scores'] = $result['chunk_scores'];
            }

            // Which chunks the snippet is made of (PASSAGE_CHUNKS) — only when every one
            // of them is known by its index (a result without per-chunk data is not).
            if ($withPassageChunks && $passageChunks !== [] && !array_key_exists(self::UNINDEXED_CHUNK, $passageChunks)) {
                $reference['snippet_chunks'] = $passageChunks;
            }

            return $reference;
        };
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
     * How this query ranks the chunks of every accessible file among its nearest
     * chunks, WITHOUT the relevance floor: file id => chunk_index => similarity,
     * best first.
     *
     * For the caller that fuses several phrasings of one question (AiManager). The
     * floor decides which passages a phrasing admits; which chunk of a file it
     * ranks FIRST needs no floor, and a phrasing that admits nothing of a file
     * votes for that chunk. A cosine is relative to the wording that produced it —
     * plain words score a formal text 0.37–0.45 where a phrasing in the documents'
     * register scores it 0.6–0.7 — so a fixed floor silenced the user's own wording
     * altogether and let a rewrite vote only in the runs where it happened to score
     * 0.41 instead of 0.37 (eval p16–p21 tear-down). Nothing is admitted through
     * this map: it holds scores, no passage.
     *
     * The SAME content search as searchRelevantFiles() — same query (with the
     * conversation focus on a follow-up), same pool size, same ACL pre-filter and
     * the same access gate afterwards — asked with min_score 0, so the scores over
     * the floor are exactly those the passages were chosen by and the rest of the
     * ranking continues below them. Filename matches and an exact-article filter
     * are not rankings and are not part of it. Options: limit,
     * conversation_context, follow_up_reference, TURN_MEMO — as for a search.
     *
     * Never throws: a ranking only adds to a vote. On any failure the map is empty.
     *
     * @return array<int|string, array<int, float>>
     */
    public function chunkRankings(string $question, mixed $user, array $options = []): array
    {
        $rankings = [];
        foreach ($this->rankedChunks($question, $user, $options) as $fileId => $ranked) {
            $rankings[$fileId] = array_map(static fn (array $chunk): float => $chunk['score'], $ranked['chunks']);
        }

        return $rankings;
    }

    /**
     * The chunks behind chunkRankings(), with what a reader needs to judge them:
     * per accessible file, its ranked chunks (best first) each with its similarity,
     * text, heading and heading path — and the raw search result a passage can be
     * built from ({@see self::passageAt()}). Same search, same access rules, no
     * relevance floor; preamble chunks (titles, tables of contents) are left out
     * whenever the file has content chunks, as in every search.
     *
     * Never throws: on any failure the map is empty.
     *
     * @return array<int|string, array{result: array, chunks: array<int, array{score: float, content: string, heading: string, heading_path: list<string>}>}>
     */
    public function rankedChunks(string $question, mixed $user, array $options = []): array
    {
        try {
            $securityEnforced = $this->validateUserForSecurity($user);
            $memo = ($options[self::TURN_MEMO] ?? null) instanceof \ArrayObject ? $options[self::TURN_MEMO] : null;

            $conversationContext = is_array($options['conversation_context'] ?? null) ? $options['conversation_context'] : [];
            $query = $this->enhanceQueryWithContext(
                $question,
                $conversationContext,
                ($options['follow_up_reference'] ?? false) === true
            );

            $searchOptions = [
                'limit' => ($options['limit'] ?? config('ai.file_context.max_references', 5)) * 3,
                'min_score' => 0.0,
                'include_relationships' => false,
            ];
            $aclFileIds = $securityEnforced ? $this->accessibleFileIdsForPrefilter($user, $memo) : null;
            if ($aclFileIds !== null) {
                $searchOptions['acl_file_ids'] = $aclFileIds;
            }
            $searchOptions += $this->scopeOptions($options, $memo) + $this->audienceOptions($user);

            // One ranking per query and turn: the vote from under the floor and the
            // passage selection both ask for the question's.
            $memoKey = 'ranked:' . self::userMemoKey($user) . ':' . md5($query . '|' . $searchOptions['limit']);
            if ($memo !== null && $memo->offsetExists($memoKey)) {
                return $memo[$memoKey];
            }

            $results = $this->searchService->searchByContent($query, $searchOptions);
            if ($results === []) {
                return [];
            }

            $accessible = array_fill_keys(
                array_map('strval', $this->accessibleAmong(array_column($results, 'file_id'), $user, $memo)),
                true
            );

            $ranked = [];
            foreach ($results as $result) {
                $fileId = $result['file_id'];
                $scores = $result['chunk_scores'] ?? null;
                if (!isset($accessible[(string) $fileId]) || !is_array($scores) || $scores === []) {
                    continue;
                }
                arsort($scores);

                $byIndex = [];
                foreach ((array) ($result['chunks'] ?? []) as $chunk) {
                    if (is_object($chunk) && isset($chunk->chunkIndex)) {
                        $byIndex[(int) $chunk->chunkIndex] = $chunk;
                    }
                }

                $chunks = [];
                foreach ($scores as $index => $score) {
                    $chunk = $byIndex[(int) $index] ?? null;
                    $metadata = is_object($chunk) && is_array($chunk->metadata ?? null) ? $chunk->metadata : [];
                    $chunks[(int) $index] = [
                        'score' => (float) $score,
                        'content' => is_object($chunk) ? (string) ($chunk->content ?? '') : '',
                        'heading' => $this->headingPath($metadata),
                        'heading_path' => $this->headingLevels($metadata),
                    ];
                }
                $ranked[$fileId] = ['result' => $result, 'chunks' => $chunks];
            }
            if ($memo !== null) {
                $memo[$memoKey] = $ranked;
            }

            return $ranked;
        } catch (\Throwable $e) {
            Log::debug('FileContextProvider: chunk ranking search failed, continuing', [
                'error' => $e->getMessage(),
            ]);
            PipelineObserverScope::current()->stageError('file_context', $e);

            return [];
        }
    }

    /**
     * The passage centred on ONE chunk of a ranked file ({@see self::rankedChunks()}):
     * the same reference a search emits for it — the chunk and its ±context_window
     * neighbours stitched in reading order, the structural locator, `relevance` its
     * own similarity — for a caller that selects a passage the relevance floor did
     * not admit (the passage selection of AiManager). The file passes the access
     * gate again here. Options: TURN_MEMO, PASSAGE_CHUNKS, `question` (enricher).
     *
     * @param array $result The `result` entry of rankedChunks() for the file.
     * @return array|null The reference; null when the chunk is unknown, the file is
     *         not accessible or anything fails (never throws).
     */
    public function passageAt(array $result, int $chunkIndex, mixed $user, array $options = []): ?array
    {
        try {
            $this->validateUserForSecurity($user);
            $memo = ($options[self::TURN_MEMO] ?? null) instanceof \ArrayObject ? $options[self::TURN_MEMO] : null;

            $fileId = $result['file_id'] ?? null;
            if ($fileId === null || $this->accessibleAmong([$fileId], $user, $memo) === []) {
                return null;
            }

            $centre = null;
            foreach ((array) ($result['chunks'] ?? []) as $chunk) {
                if (is_object($chunk) && isset($chunk->chunkIndex) && (int) $chunk->chunkIndex === $chunkIndex) {
                    $centre = $chunk;
                    break;
                }
            }
            if ($centre === null) {
                return null;
            }

            $contextWindow = (int) config('ai.file_context.context_window', 2);
            $buildReference = $this->referenceBuilder(
                (int) config('ai.file_context.snippet_length', 6000),
                (int) config('ai.file_context.snippet_chunks', 3),
                $contextWindow,
                $contextWindow > 0 && $this->chunkStore !== null,
                [],
                $memo,
                ($options[self::PASSAGE_CHUNKS] ?? false) === true
            );

            $centred = $result;
            $centred['best_chunk'] = $centre;
            $centred['score'] = (float) ($result['chunk_scores'][$chunkIndex] ?? 0.0);
            $centred['match_type'] = 'content';
            // The passage reports its own chunk only: the whole ranking of the
            // query is not its own.
            unset($centred['chunk_scores']);

            $enriched = $this->enricher->enrich([$buildReference($centred)], (string) ($options['question'] ?? ''), $user);

            return is_array($enriched[0] ?? null) ? $enriched[0] : null;
        } catch (\Throwable $e) {
            Log::debug('FileContextProvider: passage build failed, continuing', [
                'error' => $e->getMessage(),
            ]);
            PipelineObserverScope::current()->stageError('file_context', $e);

            return null;
        }
    }

    /**
     * The OWN TITLE of the document a search result belongs to
     * ({@see DocumentTitle}: the first top-level heading of its first chunk; '' when
     * it has none — nothing is guessed).
     *
     * No read of its own, ever: the title stamped on the hit at processing time when
     * there is one; else it is read off the file's chunk list the context window
     * has just read (`file_chunks:` in $reads — the turn memo, so once per file and
     * turn however many angles and passages name the file), resolved once per file
     * and kept beside it. With the window off the store is not asked: the title is
     * then only what the search itself returned (the first chunk among its chunks).
     *
     * @param array $result The search result (window-expanded or not).
     * @param \ArrayObject $reads The turn memo (or this reference's own scratch).
     */
    private function documentTitle(array $result, \ArrayObject $reads): string
    {
        $stored = DocumentTitle::stored($result['best_chunk'] ?? null);
        if ($stored !== null) {
            return $stored;
        }

        $fileId = $result['file_id'] ?? null;
        if (!is_int($fileId) && !is_string($fileId)) {
            return '';
        }
        $file = ($this->resolveResultCollection($result) ?? '') . ':' . $fileId;

        $titleKey = 'document_title:' . $file;
        if ($reads->offsetExists($titleKey)) {
            return (string) $reads[$titleKey];
        }

        $chunksKey = 'file_chunks:' . $file;
        if ($reads->offsetExists($chunksKey) && is_array($reads[$chunksKey])) {
            return $reads[$titleKey] = DocumentTitle::of($reads[$chunksKey]);
        }

        // The file was not read: only the chunks the search returned are at hand —
        // not the whole file, so nothing is concluded for the rest of the turn.
        return DocumentTitle::of(is_array($result['chunks'] ?? null) ? $result['chunks'] : []);
    }

    /**
     * Where the reference's best chunk sits (Phase 7.1 chunk metadata): its
     * article / article range, section heading path, preamble flag and the chunk's own
     * text ('passage' — one article, unlike the window-stitched snippet), plus
     * whether it matched an exact-article angle. Empty for legacy chunks.
     *
     * @param list<scalar> $articleNumbers
     */
    private function chunkLocator(mixed $chunk, array $articleNumbers): array
    {
        $metadata = is_object($chunk) && is_array($chunk->metadata ?? null) ? $chunk->metadata : [];
        $locator = [];

        foreach (['article_no', 'article_range'] as $key) {
            if (isset($metadata[$key]) && $metadata[$key] !== '') {
                $locator[$key] = (string) $metadata[$key];
            }
        }
        $section = $this->headingPath($metadata);
        if ($section !== '') {
            $locator['heading'] = $section;
        }
        // The path's LEVELS, for the cross-angle merge only: it tells which branch
        // of the document a passage belongs to (AiManager strips it before the
        // context reaches the prompt — the header text above is what the model reads).
        $levels = $this->headingLevels($metadata);
        if ($levels !== []) {
            $locator['heading_path'] = $levels;
        }
        if (array_key_exists('is_preamble', $metadata)) {
            $locator['is_preamble'] = (bool) $metadata['is_preamble'];
        }
        if (isset($metadata['page']) && is_numeric($metadata['page'])) {
            $locator['page'] = (int) $metadata['page'];
        }
        // Whose document it is (ai.audiences); the trace reads it, no prompt does.
        $audience = array_values(array_filter((array) ($metadata['audience'] ?? []), static fn ($a) => is_string($a) && $a !== ''));
        if ($audience !== []) {
            $locator['audience'] = $audience;
        }
        if ($metadata !== [] && is_object($chunk) && isset($chunk->content)) {
            $locator['passage'] = (string) $chunk->content;
        }
        if ($articleNumbers !== []) {
            $articles = array_map('strval', (array) ($metadata['articles'] ?? []));
            $locator['article_match'] = array_intersect(array_map('strval', $articleNumbers), $articles) !== [];
        }

        return $locator;
    }

    /** Joins the levels of a passage's heading path in every header shown to the model. */
    private const HEADING_PATH_SEPARATOR = ' › ';

    /**
     * Where the passage sits in its document: the chunk's FULL heading path
     * ("CHAPTER II — MEMBERS › DIVISION II — RIGHTS AND OBLIGATIONS OF MEMBERS"), not
     * its last heading alone. A leaf heading is not unique within a document — a by-law
     * may title a division "RIGHTS AND OBLIGATIONS" in two different chapters — so the
     * leaf alone presented an article of one chapter as the other's rule (eval p16-B
     * rights t1). Legacy chunks without a path keep
     * their section title.
     *
     * @param array<string, mixed> $metadata
     */
    private function headingPath(array $metadata): string
    {
        $levels = $this->headingLevels($metadata);

        return $levels !== []
            ? implode(self::HEADING_PATH_SEPARATOR, $levels)
            : trim((string) ($metadata['section_title'] ?? ''));
    }

    /**
     * The levels of the chunk's stored heading path, outermost first,
     * whitespace-normalised; [] for legacy chunks without one.
     *
     * @param array<string, mixed> $metadata
     * @return list<string>
     */
    private function headingLevels(array $metadata): array
    {
        $levels = [];
        foreach ((array) ($metadata['heading_path'] ?? []) as $level) {
            $level = is_scalar($level) ? trim((string) preg_replace('/\s+/u', ' ', (string) $level)) : '';
            if ($level !== '') {
                $levels[] = $level;
            }
        }

        return $levels;
    }

    /**
     * The user's accessible DB file ids for the vector-store ACL PRE-filter, or
     * null for "no pre-filter" (no user, an id set too large to send as a
     * filter, or the resolver failing — the post-filter is still the security
     * gate, so falling back to it is safe). Called only when security is enforced.
     *
     * @return list<int|string>|null
     */
    private function accessibleFileIdsForPrefilter(mixed $user, ?\ArrayObject $memo = null): ?array
    {
        if ($user === null) {
            return null;
        }

        $key = 'prefilter_ids:' . self::userMemoKey($user);
        if ($memo !== null && $memo->offsetExists($key)) {
            return $memo[$key];
        }

        try {
            $ids = $this->accessResolver->getAccessibleFileIds($user);
        } catch (\Throwable $e) {
            return null;
        }

        $ids = (!is_array($ids) || count($ids) > self::ACL_PREFILTER_MAX_IDS) ? null : array_values($ids);
        if ($memo !== null) {
            $memo[$key] = $ids;
        }

        return $ids;
    }

    /**
     * The access gate: which of these file ids the user may see, in their order.
     *
     * Always the access resolver's own answer. With a turn memo each file is put
     * to the resolver once per turn: an id it already judged for THIS user keeps
     * its verdict (granted or denied), only ids not seen yet are asked about.
     *
     * @param array<int|string> $fileIds
     * @return array<int|string>
     */
    private function accessibleAmong(array $fileIds, mixed $user, ?\ArrayObject $memo): array
    {
        if ($memo === null) {
            return $this->accessResolver->filterAccessibleFileIds($fileIds, $user);
        }

        $key = 'gate:' . self::userMemoKey($user);
        $verdicts = $memo->offsetExists($key) ? $memo[$key] : [];

        $unjudged = [];
        foreach ($fileIds as $fileId) {
            if (!array_key_exists((string) $fileId, $verdicts)) {
                $unjudged[(string) $fileId] = $fileId;
            }
        }
        if ($unjudged !== []) {
            $granted = $this->accessResolver->filterAccessibleFileIds(array_values($unjudged), $user);
            foreach ($unjudged as $id => $fileId) {
                $verdicts[$id] = in_array($fileId, $granted, true);
            }
            $memo[$key] = $verdicts;
        }

        return array_values(array_filter($fileIds, static fn ($fileId): bool => $verdicts[(string) $fileId] ?? false));
    }

    /**
     * The memo key of the user an access answer was resolved for: that very
     * object (one turn passes the same instance to every angle), so an entry can
     * never answer for another user.
     */
    private static function userMemoKey(mixed $user): string
    {
        return is_object($user) ? 'object#' . spl_object_id($user) : 'value#' . md5(serialize($user));
    }

    /**
     * The documents this user may read, for every search of the turn: code decides, never
     * the model ({@see Audiences}). [] without ai.audiences — the search is then unchanged.
     */
    private function audienceOptions(mixed $user): array
    {
        $audiences = Audiences::documents($user);

        return $audiences !== null ? ['audiences' => $audiences] : [];
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
     * the merged chunks and assembleSnippetChunks admits it first).
     *
     * @param array $result A single search result (best_chunk + chunks[] + collection).
     * @param int   $window Neighbors to include per side (chunk_index distance).
     * @return array The result with chunks[] augmented by the windowed neighbors.
     */
    private function expandWithContextWindow(array $result, int $window, ?\ArrayObject $memo = null): array
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

        // One read of the file per turn when the angles share a memo: every passage
        // of it, on every angle, stitches its neighbours from the same chunk list.
        $memoKey = 'file_chunks:' . ($collection ?? '') . ':' . $fileId;
        try {
            $fileChunks = ($memo !== null && $memo->offsetExists($memoKey))
                ? $memo[$memoKey]
                : $this->chunkStore->getFileChunks($fileId, $collection);
            if ($memo !== null) {
                $memo[$memoKey] = $fileChunks;
            }
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

        $wantedIndices = $this->withoutOutOfScopeNeighbours(
            $this->windowIndices($bestIndex, $window, array_keys($byIndex)),
            $byIndex,
            $bestIndex
        );

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
     * The window without the neighbours that belong to a part of the document
     * judged OUT of the application's scope (document map, `map.scope`).
     *
     * The search filter hides those chunks; the window reads the whole file and
     * would stitch them back around a hit that sits at the edge of the governing
     * part — under that part's header (live: the last article of the chapter on
     * another regime, in a snippet labelled with the governing chapter). A part is
     * out when one of its chunks carries a fresh `scope: out`; its other chunks —
     * heading-only ones, one whose record is stale — follow it.
     *
     * When the HIT ITSELF is out of scope nothing is removed: only a question that
     * names an article by number reaches such a chunk, and the user asked for it.
     * Nothing is removed either when the document map is switched off.
     *
     * @param array<int> $indices The window, in reading order.
     * @param array<int, mixed> $byIndex The file's chunks by chunk index.
     * @return array<int>
     */
    private function withoutOutOfScopeNeighbours(array $indices, array $byIndex, int $bestIndex): array
    {
        $centre = $byIndex[$bestIndex] ?? null;
        if (!$centre instanceof FileChunk
            || !config('ai.file_context.document_map.enabled', true)
            || DocumentMap::scopeOf($centre) === 'out') {
            return $indices;
        }

        $chunks = array_values(array_filter($byIndex, static fn (mixed $chunk): bool => $chunk instanceof FileChunk));
        $out = array_values(array_filter($chunks, static fn (FileChunk $chunk): bool => DocumentMap::scopeOf($chunk) === 'out'));
        if ($out === []) {
            return $indices;
        }

        $depth = DocumentMap::documentDepth($chunks);
        $partOf = static fn (FileChunk $chunk): ?string => DocumentMap::partOf(DocumentMap::headingPathOf($chunk), $depth);
        $outParts = array_fill_keys(array_filter(array_map($partOf, $out), 'is_string'), true);
        $centrePart = $partOf($centre);

        return array_values(array_filter($indices, static function (int $index) use ($byIndex, $bestIndex, $partOf, $outParts, $centrePart): bool {
            $chunk = $byIndex[$index] ?? null;
            if ($index === $bestIndex || !$chunk instanceof FileChunk) {
                return true;
            }
            if (DocumentMap::scopeOf($chunk) === 'out') {
                return false;
            }
            $part = $partOf($chunk);

            return $part === null || $part === $centrePart || !isset($outParts[$part]);
        }));
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
     * Returned as its PARTS — chunk_index => the text admitted for that chunk, in
     * reading order; the snippet is these joined by SNIPPET_SEPARATOR — so a caller
     * can tell which chunks a snippet holds (PASSAGE_CHUNKS). A stitch whose chunks
     * cannot be told apart by index is ONE part keyed UNINDEXED_CHUNK.
     *
     * @param array $result   A single search result (best_chunk + chunks[]).
     * @param int   $maxLength Total snippet character budget.
     * @param int   $maxChunks Maximum number of chunks to stitch together.
     * @return array<int, string> The parts of the assembled, length-capped snippet.
     */
    private function assembleSnippetChunks(array $result, int $maxLength, int $maxChunks): array
    {
        $bestChunk = $result['best_chunk'] ?? null;
        $bestContent = is_object($bestChunk) && isset($bestChunk->content)
            ? (string) $bestChunk->content
            : '';

        $chunks = $result['chunks'] ?? null;

        $bestOnly = [
            is_object($bestChunk) && isset($bestChunk->chunkIndex) ? (int) $bestChunk->chunkIndex : self::UNINDEXED_CHUNK
                => $this->truncateSnippet($bestContent, $maxLength),
        ];

        // Single-chunk path: only one chunk to show, or multi-chunk disabled.
        if ($maxChunks <= 1 || !is_array($chunks) || count($chunks) <= 1) {
            return $bestOnly;
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
            return $bestOnly;
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
     * @return array<int, string> chunk_index => admitted text, in reading order; ONE
     *         part keyed UNINDEXED_CHUNK (the whole stitch) when the chunks cannot be
     *         told apart by index.
     */
    private function stitchCenteredOnBest(array $selected, int $bestIndex, int $maxLength): array
    {
        $separator = self::SNIPPET_SEPARATOR;

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

        // Emit in reading order (keys are the reading-order positions), keyed by the
        // chunk each part is.
        ksort($admitted);
        $parts = [];
        foreach ($admitted as $position => $content) {
            $parts[self::chunkIndexOf($selected[$position])] = $content;
        }

        // Chunks without a distinct index (legacy wrappers): the stitch stays one part.
        return count($parts) === count($admitted)
            ? $parts
            : [self::UNINDEXED_CHUNK => implode($separator, $admitted)];
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
    private function validateUserForSecurity(mixed $user): bool
    {
        $enforced = $this->accessResolver->shouldEnforceSecurity();
        if ($enforced && $user === null) {
            throw new \RuntimeException('User required for file context retrieval');
        }

        return $enforced;
    }

    /**
     * Enhance search query with conversation context for follow-up questions
     *
     * When the Query Understanding LLM judged the question a follow-up that
     * refers back to what was just discussed ($followUpReference — threaded in
     * by the caller as options['follow_up_reference']) and the conversation has a
     * focused entity, the query is prefixed with that entity for better search
     * relevance. The follow-up judgment is semantic and language-agnostic
     * ("Et celles-là ?" as much as "and those?") — there is deliberately no
     * pronoun word list here (Phase 2.B).
     *
     * @param string $question The original search question
     * @param array $conversationContext Context from the conversation including:
     *   - focused_entity: The main entity being discussed (e.g., "Team", "User")
     * @param bool $isFollowUp QU's followUpReference for this turn
     * @return string The enhanced query or original question if no enhancement needed
     */
    private function enhanceQueryWithContext(string $question, array $conversationContext, bool $isFollowUp): string
    {
        if (!$isFollowUp || empty($conversationContext)) {
            return $question;
        }

        // Add focused entity context
        if (!empty($conversationContext['focused_entity'])) {
            $entity = $conversationContext['focused_entity'];
            return "{$entity}: {$question}";
        }

        return $question;
    }
}
