<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Contracts\FileChunkerInterface;
use Condoedge\Ai\Contracts\StructuredChunkerInterface;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\FileOwnerResolverInterface;
use Condoedge\Ai\DTOs\ProcessingResult;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Jobs\BuildDocumentMapJob;
use Condoedge\Ai\Support\DocumentMap;
use Condoedge\Ai\Support\DocumentTitle;
use Condoedge\Ai\Support\FileLink;
use Condoedge\Ai\Support\Frontmatter;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * File processing service
 *
 * This service orchestrates the complete file processing pipeline:
 * 1. Extract text from file
 * 2. Chunk the text
 * 3. Generate embeddings for each chunk
 * 4. Store chunks with embeddings in vector store
 */
class FileProcessor implements FileProcessorInterface
{
    /** Formats whose YAML frontmatter is read as the document's metadata ({@see Frontmatter}). */
    private const FRONTMATTER_EXTENSIONS = ['md', 'markdown', 'mdown', 'mdx'];

    /**
     * @param FileExtractorRegistry $extractorRegistry
     * @param FileChunkerInterface $chunker
     * @param EmbeddingProviderInterface $embeddingProvider
     * @param ChunkStoreInterface $chunkStore
     */
    public function __construct(
        private readonly FileExtractorRegistry $extractorRegistry,
        private readonly FileChunkerInterface $chunker,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly ChunkStoreInterface $chunkStore,
        private readonly ?FileOwnerResolverInterface $ownerResolver = null
    ) {}

    /**
     * {@inheritdoc}
     */
    public function processFile(object $file, array $options = []): ProcessingResult
    {
        $startTime = microtime(true);

        try {
            $this->validateFileObject($file);

            // Resolve the target collection for this call. Null => the chunk
            // store's bound collection (e.g. file_chunks). The docs ingest path
            // passes 'documentation_chunks' so physical docs land in their own
            // collection.
            $collection = $options['collection'] ?? null;

            // `skip_unchanged` (the docs ingest): a stored file whose bytes did not change is
            // a skip, not a failure; a changed one is replaced. Chunks stored before hashes
            // existed count as unchanged — `force` re-embeds them once.
            $sourceHash = ($options['skip_unchanged'] ?? false) ? $this->sourceHash($file) : null;
            if (!($options['force'] ?? false) && $this->isProcessed($file, $collection)) {
                if ($sourceHash === null) {
                    return ProcessingResult::failure(
                        $file->id,
                        "File already processed. Use 'force' option to reprocess.",
                        microtime(true) - $startTime
                    );
                }
                $storedHash = $this->storedSourceHash($file->id, $collection);
                if ($storedHash === null || $storedHash === $sourceHash) {
                    return ProcessingResult::success($file->id, 0, 0, microtime(true) - $startTime, [
                        'skipped' => true,
                        'reason' => $storedHash === null ? 'stored before content hashes' : 'unchanged',
                    ]);
                }
                $options['force'] = true;
            }

            $filePath = $this->getFilePath($file);

            if (!file_exists($filePath)) {
                return ProcessingResult::failure(
                    $file->id,
                    "File not found: {$filePath}",
                    microtime(true) - $startTime
                );
            }

            $extension = pathinfo($file->name, PATHINFO_EXTENSION);

            if (!$this->supportsFileType($extension)) {
                return ProcessingResult::failure(
                    $file->id,
                    "Unsupported file type: {$extension}",
                    microtime(true) - $startTime
                );
            }

            // Step 1: Extract text; a markdown frontmatter is the document's metadata, not its text.
            $text = $this->extractorRegistry->extract($filePath);
            [$frontmatter, $text] = in_array(strtolower($extension), self::FRONTMATTER_EXTENSIONS, true)
                ? Frontmatter::split($text)
                : [[], $text];
            $documentMetadata = Frontmatter::metadata($frontmatter, (string) $file->name);
            if ($sourceHash !== null) {
                $documentMetadata['source_hash'] = $sourceHash;
            }

            if (empty(trim($text))) {
                return ProcessingResult::failure(
                    $file->id,
                    "No text content extracted from file",
                    microtime(true) - $startTime
                );
            }

            // Page breaks: the PDF parser joins pages with a form feed. Record each
            // break's character offset, then turn it into a newline (same length,
            // so every chunk offset stays a real position in $text).
            $pageBreaks = $this->pageBreakOffsets($text);
            if ($pageBreaks !== []) {
                $text = str_replace("\f", "\n", $text);
            }

            // Step 2: Chunk text
            $chunkSize = $options['chunk_size'] ?? $this->chunker->getRecommendedChunkSize($extension);
            $overlap = $options['overlap'] ?? $this->chunker->getRecommendedOverlap($extension);

            $chunkOptions = [
                'chunk_size' => $chunkSize,
                'overlap' => $overlap,
                'min_chunk_chars' => $options['min_chunk_chars'] ?? $this->configValue('ai.file_processing.chunking.min_chunk_chars'),
                'max_chunk_chars' => $options['max_chunk_chars'] ?? $this->configValue('ai.file_processing.chunking.max_chunk_chars'),
            ];
            $chunkOptions = array_filter($chunkOptions, static fn ($v) => $v !== null);

            // Structure-aware chunkers (Phase 7.1) return, per chunk, the stored
            // text, the article/heading-prefixed text to EMBED, and the metadata
            // stored in the payload. Plain chunkers embed the text itself.
            if ($this->chunker instanceof StructuredChunkerInterface) {
                $structured = $this->chunker->chunkWithMetadata($text, $chunkOptions);
                $textChunks = array_column($structured, 'text');
                $embedTexts = array_column($structured, 'embed_text');
                $chunkMetadata = array_column($structured, 'metadata');
            } else {
                $textChunks = array_values($this->chunker->chunk($text, $chunkOptions));
                $embedTexts = $textChunks;
                $chunkMetadata = [];
            }

            if (empty($textChunks)) {
                return ProcessingResult::failure(
                    $file->id,
                    "Failed to chunk text content",
                    microtime(true) - $startTime
                );
            }

            $embedPrefix = $this->embedPrefix($documentMetadata);
            if ($embedPrefix !== '') {
                $embedTexts = array_map(static fn (string $embedText) => $embedPrefix . $embedText, $embedTexts);
            }

            // The record an UPLOADED file is filed on — resolved once per file,
            // before anything is paid for or stored: a file whose owner cannot be
            // resolved is not stored as a file of nobody.
            $owner = $this->ownerOf($file, $collection);

            // Step 3: Generate embeddings
            $embeddings = $this->embeddingProvider->embedBatch($embedTexts);

            // Step 4: Create FileChunk objects
            $fileChunks = $this->createFileChunks(
                $file,
                $textChunks,
                $embeddings,
                $text,
                $chunkMetadata,
                $pageBreaks,
                $owner,
                $documentMetadata
            );

            // A forced (re-)ingest REPLACES the file's points — and with them the
            // document map written beside them. The records of the chunks whose
            // text did not change are read BEFORE the delete and stored with the
            // new chunks (reprocessFile() read them before it removed the file).
            if ($options['force'] ?? false) {
                $fileChunks = $this->withCarriedMap(
                    $fileChunks,
                    $options['_carried_map'] ?? $this->storedMapRecords($file->id, $collection),
                    $collection
                );
            }

            // A forced (re-)ingest REPLACES the file's points: point ids are
            // file+chunk_index, so when the new split has fewer chunks the old
            // tail would otherwise linger as stale passages. Done only after the
            // embeddings succeeded. (reprocessFile() already removed them.)
            if (($options['force'] ?? false) && !($options['_already_removed'] ?? false)) {
                $this->chunkStore->deleteFileChunks($file->id, $collection);
            }

            // Step 5: Store chunks (routed to the requested collection if given)
            $storeSuccess = $this->chunkStore->storeChunks($fileChunks, $collection);

            if (!$storeSuccess) {
                return ProcessingResult::failure(
                    $file->id,
                    "Failed to store chunks in vector database",
                    microtime(true) - $startTime
                );
            }

            // Step 6: queue the document map (gists + scope) of the stored chunks.
            $this->queueDocumentMap($file->id, $collection);

            return ProcessingResult::success(
                $file->id,
                count($fileChunks),
                count($embeddings),
                microtime(true) - $startTime,
                [
                    'file_name' => $file->name,
                    'file_size' => filesize($filePath),
                    'text_length' => strlen($text),
                    'chunk_size' => $chunkSize,
                    'overlap' => $overlap,
                ]
            );
        } catch (\Exception $e) {
            return ProcessingResult::failure(
                $file->id,
                "Processing error: {$e->getMessage()}",
                microtime(true) - $startTime,
                ['exception' => get_class($e)]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function reprocessFile(object $file, ?string $collection = null): ProcessingResult
    {
        // Read before the removal: the map records the re-ingest carries over.
        $carried = $this->storedMapRecords($file->id ?? '', $collection);

        $this->removeFile($file, $collection);

        // Force reprocess, preserving the target collection.
        return $this->processFile($file, [
            'force' => true,
            'collection' => $collection,
            '_already_removed' => true,
            '_carried_map' => $carried,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function removeFile(object $file, ?string $collection = null): bool
    {
        $this->validateFileObject($file);

        return $this->chunkStore->deleteFileChunks($file->id, $collection);
    }

    /**
     * {@inheritdoc}
     */
    public function isProcessed(object $file, ?string $collection = null): bool
    {
        $this->validateFileObject($file);

        return $this->chunkStore->hasFileChunks($file->id, $collection);
    }

    /**
     * {@inheritdoc}
     */
    public function getFileStats(object $file, ?string $collection = null): array
    {
        $this->validateFileObject($file);

        $chunks = $this->chunkStore->getFileChunks($file->id, $collection);

        if (empty($chunks)) {
            return [
                'processed' => false,
                'chunk_count' => 0,
            ];
        }

        $totalSize = array_sum(array_map(
            fn($chunk) => $chunk->getContentLength(),
            $chunks
        ));

        return [
            'processed' => true,
            'chunk_count' => count($chunks),
            'total_content_size' => $totalSize,
            'average_chunk_size' => $totalSize / count($chunks),
            'first_chunk_preview' => substr($chunks[0]->content, 0, 100) . '...',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsFileType(string $extension): bool
    {
        return $this->extractorRegistry->supports($extension);
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedFileTypes(): array
    {
        return $this->extractorRegistry->getSupportedExtensions();
    }

    /**
     * Validate that the file object has required properties
     *
     * @param object $file
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateFileObject(object $file): void
    {
        $required = ['id', 'name', 'path'];

        foreach ($required as $property) {
            if (!isset($file->$property)) {
                throw new \InvalidArgumentException(
                    "File object must have '{$property}' property"
                );
            }
        }
    }

    /**
     * Get the full file path
     *
     * @param object $file
     * @return string
     */
    private function getFilePath(object $file): string
    {
        if ($this->isAbsolutePath($file->path)) {
            return $file->path;
        }

        // Construct from the storage disk root for relative paths.
        $disk = $file->disk ?? 'local';
        $storagePath = config("filesystems.disks.{$disk}.root");

        if (!$storagePath) {
            throw new \RuntimeException("Storage disk '{$disk}' not configured");
        }

        return $storagePath . DIRECTORY_SEPARATOR . $file->path;
    }

    /**
     * Check if a path is absolute
     *
     * @param string $path
     * @return bool
     */
    private function isAbsolutePath(string $path): bool
    {
        // Windows: C:\ or \\server\share
        if (preg_match('/^[A-Z]:/i', $path) || str_starts_with($path, '\\\\')) {
            return true;
        }

        // Unix: /path
        return str_starts_with($path, '/');
    }

    /**
     * Create FileChunk objects from text chunks and embeddings
     *
     * @param object $file
     * @param array $textChunks
     * @param array $embeddings
     * @param string $fullText
     * @param array<int,array> $chunkMetadata Per-chunk structural metadata (index-aligned)
     * @param list<int> $pageBreaks Character offsets of page breaks in $fullText
     * @return array
     */
    private function createFileChunks(
        object $file,
        array $textChunks,
        array $embeddings,
        string $fullText,
        array $chunkMetadata = [],
        array $pageBreaks = [],
        ?array $owner = null,
        array $documentMetadata = []
    ): array {
        $this->assertEmbeddingsAlignWithChunks($textChunks, $embeddings);

        $fileChunks = [];
        $totalChunks = count($textChunks);

        // The document's OWN TITLE — its frontmatter title, else the first top-level
        // heading of its first chunk, none when it has no such heading — stamped on every
        // chunk: a passage deep in the document then names its document without a read of
        // the first chunk. The rest of its frontmatter rides along.
        $title = $documentMetadata['title'] ?? DocumentTitle::fromFirstChunk((string) ($textChunks[0] ?? ''));
        $titled = ($title !== '' ? [DocumentTitle::METADATA_KEY => $title] : [])
            + array_diff_key($documentMetadata, ['title' => true]);

        // Moving character cursor over the source. Chunks overlap and the
        // chunker may renormalize whitespace between joined units, so positions
        // are resolved by locating each chunk's text in the source from the
        // cursor rather than by cumulatively summing chunk lengths (which would
        // run past the true end once overlap is involved).
        $cursor = 0;

        foreach ($textChunks as $index => $chunkText) {
            [$startPosition, $endPosition] = $this->resolveChunkOffsets(
                $fullText,
                $chunkText,
                $cursor
            );

            $fileChunks[] = new FileChunk(
                fileId: $file->id,
                fileName: $file->name,
                content: $chunkText,
                embedding: $embeddings[$index],
                chunkIndex: $index,
                totalChunks: $totalChunks,
                startPosition: $startPosition,
                endPosition: $endPosition,
                metadata: $this->withPage($chunkMetadata[$index] ?? [], $pageBreaks, $startPosition)
                    + $titled
                    + ($owner !== null ? [FileLink::METADATA_KEY => FileLink::payload($owner, $chunkText)] : [])
            );

            // Advance the cursor to this chunk's start so the next (overlapping)
            // chunk is searched forward of here, never behind it.
            $cursor = $startPosition;
        }

        return $fileChunks;
    }

    /** The sha1 of the file's bytes with CRLF read as LF (a git autocrlf checkout); null when it cannot be read. */
    private function sourceHash(object $file): ?string
    {
        try {
            $bytes = @file_get_contents($this->getFilePath($file));
        } catch (\Throwable) {
            return null;
        }

        return is_string($bytes) ? sha1(str_replace("\r\n", "\n", $bytes)) : null;
    }

    /** The bytes hash stamped on a stored file's chunks; null when they carry none. */
    private function storedSourceHash(int|string $fileId, ?string $collection): ?string
    {
        $hash = ($this->chunkStore->getFileChunks($fileId, $collection)[0] ?? null)?->metadata['source_hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * What every embedded text of a document starts with: its frontmatter breadcrumb, and its
     * keywords when the host asks for them (ai.file_context.frontmatter.embed_keywords). '' without.
     */
    private function embedPrefix(array $documentMetadata): string
    {
        $lines = [];
        if (($documentMetadata['breadcrumb'] ?? '') !== '') {
            $lines[] = $documentMetadata['breadcrumb'];
        }
        if (($documentMetadata['keywords'] ?? []) !== [] && $this->configValue('ai.file_context.frontmatter.embed_keywords')) {
            $lines[] = implode(', ', $documentMetadata['keywords']);
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * The owner of the file being processed, or null for a PROJECT file.
     *
     * UPLOADED files are linked to the record they are filed on
     * ({@see FileOwnerResolverInterface}; without a resolver: a file of no
     * record) and every one of their chunks carries that link. PROJECT files —
     * the physical collection, or a `physical:` id wherever it is sent — are
     * global by construction and carry none.
     *
     * A resolver that throws fails the processing (the caller's catch): an
     * upload stored without its link would be served to every record.
     *
     * @return array{owner: ?string, ancestors: list<string>}|null
     */
    private function ownerOf(object $file, ?string $collection): ?array
    {
        if (self::isProjectFile($file->id ?? null, $collection)) {
            return null;
        }
        if ($this->ownerResolver === null) {
            return ['owner' => null, 'ancestors' => []];
        }

        try {
            $owner = $this->ownerResolver->ownerOf($file);
        } catch (\Throwable $e) {
            throw new \RuntimeException('The record this file is filed on could not be resolved: ' . $e->getMessage(), 0, $e);
        }

        return [
            'owner' => is_string($owner['owner'] ?? null) ? $owner['owner'] : null,
            'ancestors' => array_values(array_filter((array) ($owner['ancestors'] ?? []), 'is_string')),
        ];
    }

    /**
     * Whether a file is one of the host's own PROJECT files: its id is a
     * `physical:` id, or it is stored in the physical collection.
     */
    public static function isProjectFile(mixed $fileId, ?string $collection): bool
    {
        if (is_string($fileId) && str_starts_with($fileId, 'physical:')) {
            return true;
        }

        try {
            $physical = function_exists('config') ? config('ai.file_context.physical_collection', 'documentation_chunks') : null;
        } catch (\Throwable $e) {
            $physical = null;
        }

        return $collection !== null && trim($collection) !== '' && is_string($physical) && $collection === $physical;
    }

    /**
     * Character offsets of the form-feed page breaks the PDF parser emits.
     *
     * @return list<int>
     */
    private function pageBreakOffsets(string $text): array
    {
        if (!str_contains($text, "\f")) {
            return [];
        }

        $offsets = [];
        $position = 0;
        while (($found = mb_strpos($text, "\f", $position, 'UTF-8')) !== false) {
            $offsets[] = $found;
            $position = $found + 1;
        }

        return $offsets;
    }

    /**
     * Stamp the chunk's 1-based page (pages known only for paged sources).
     */
    private function withPage(array $metadata, array $pageBreaks, int $startPosition): array
    {
        if ($pageBreaks === []) {
            return $metadata;
        }

        $page = 1;
        foreach ($pageBreaks as $offset) {
            if ($offset < $startPosition) {
                $page++;
            }
        }
        $metadata['page'] = $page;

        return $metadata;
    }

    /**
     * The document-map records the file's stored chunks carry, keyed by what a
     * record is about: the chunk's text (the record's own hash) and its place in
     * the document (its heading path — the scope of a record is the scope of its
     * part). Stale records are left out. [] when the feature is off, the file is
     * not stored yet, or the store cannot be read: a carry is a saving, never a
     * condition of the ingest.
     *
     * @return array<string, array<string, mixed>>
     */
    private function storedMapRecords(int|string $fileId, ?string $collection): array
    {
        try {
            if (!config('ai.file_context.document_map.enabled', true)) {
                return [];
            }

            $records = [];
            foreach ($this->chunkStore->getFileChunks($fileId, $collection) as $chunk) {
                $record = $chunk instanceof FileChunk ? DocumentMap::recordOf($chunk) : null;
                if ($record !== null) {
                    $records[$this->mapRecordKey($chunk)] ??= $record;
                }
            }

            return $records;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The new chunks, each with the stored record of the SAME text at the SAME
     * place when there is one (`metadata.map`, which the chunk store writes as the
     * point's map). Logs how many records were carried and how many were dropped
     * (their text changed or is gone): the dropped ones are what the map build
     * that follows has to pay for.
     *
     * @param list<FileChunk> $chunks
     * @param array<string, array<string, mixed>> $records {@see self::storedMapRecords()}
     * @return list<FileChunk>
     */
    private function withCarriedMap(array $chunks, array $records, ?string $collection): array
    {
        if ($records === [] || $chunks === []) {
            return $chunks;
        }

        $carried = 0;
        $used = [];
        foreach ($chunks as $position => $chunk) {
            $key = $this->mapRecordKey($chunk);
            if (!isset($records[$key])) {
                continue;
            }
            $chunks[$position] = new FileChunk(
                fileId: $chunk->fileId,
                fileName: $chunk->fileName,
                content: $chunk->content,
                embedding: $chunk->embedding,
                chunkIndex: $chunk->chunkIndex,
                totalChunks: $chunk->totalChunks,
                startPosition: $chunk->startPosition,
                endPosition: $chunk->endPosition,
                metadata: ['map' => $records[$key]] + $chunk->metadata
            );
            $carried++;
            $used[$key] = true;
        }

        try {
            Log::info('Document map carried over a re-ingest', [
                'file_id' => $chunks[0]->fileId,
                'collection' => $collection,
                'carried' => $carried,
                'dropped' => count($records) - count($used),
            ]);
        } catch (\Throwable $e) {
            // Logging is best effort.
        }

        return $chunks;
    }

    private function mapRecordKey(FileChunk $chunk): string
    {
        return DocumentMap::hash($chunk->content) . '|' . implode("\u{1F}", DocumentMap::headingPathOf($chunk));
    }

    /**
     * Queue the document map of a file whose chunks were just stored.
     *
     * The map costs one small LLM call per passage (about 2.4 s each, measured),
     * so it is never built inline: with a `sync` queue nothing is dispatched and
     * `ai:documents:map` builds it instead. It is an annotation of an ingest that
     * already succeeded — whatever goes wrong here (no container, no queue, a
     * refused connection) is swallowed and the ProcessingResult is untouched.
     * Switch: ai.file_context.document_map.enabled (default on).
     *
     * Only the host's OWN documents (the physical collection) are mapped by
     * default. A file a user uploaded is mapped only when the host switched that
     * on (ai.file_context.document_map.uploads): a map makes a document's text
     * weigh on what an answer is built on, and what an uploader can do with that
     * is the host's call (see the security notes of the configuration).
     *
     * @param int|string $fileId
     * @param string|null $collection The collection the chunks were stored in
     * @return void
     */
    private function queueDocumentMap(int|string $fileId, ?string $collection): void
    {
        try {
            if (!config('ai.file_context.document_map.enabled', true)) {
                return;
            }

            $ownDocuments = $collection !== null
                && $collection === config('ai.file_context.physical_collection', 'documentation_chunks');
            if (!$ownDocuments && !config('ai.file_context.document_map.uploads', false)) {
                return;
            }

            // Same routing as the file-processing job; a connection that runs
            // inline is `sync` whatever its name.
            $connection = config('ai.file_processing.queue_connection') ?: config('queue.default', 'sync');
            if (!is_string($connection) || config("queue.connections.{$connection}.driver", $connection) === 'sync') {
                return;
            }

            $job = new BuildDocumentMapJob($fileId, $collection);
            if (config('ai.file_processing.queue_connection')) {
                $job->onConnection($connection);
            }
            $queue = config('ai.file_processing.queue_name', 'default');
            if (is_string($queue) && $queue !== '' && $queue !== 'default') {
                $job->onQueue($queue);
            }

            app(Dispatcher::class)->dispatch($job);
        } catch (\Throwable $e) {
            // Never affects the ingest.
        }
    }

    /**
     * Read a config value when a Laravel container is booted (null otherwise, so
     * the processor stays usable in plain unit tests).
     */
    private function configValue(string $key): mixed
    {
        try {
            return function_exists('config') ? config($key) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve the real character [start, end) offsets of a chunk in the source.
     *
     * The chunk length is authoritative (end = start + character length). The
     * start is the chunk's character position in the source, found from the
     * moving cursor. Whitespace-normalized joins (paragraph/sentence chunks)
     * may not be verbatim substrings, so we fall back to anchoring on the
     * chunk's leading token; if even that fails the cursor itself is used.
     *
     * @param string $fullText
     * @param string $chunkText
     * @param int $cursor Character offset to search forward from
     * @return array{0:int,1:int} [start, end]
     */
    private function resolveChunkOffsets(string $fullText, string $chunkText, int $cursor): array
    {
        $chunkLength = mb_strlen($chunkText, 'UTF-8');

        $start = mb_strpos($fullText, $chunkText, $cursor, 'UTF-8');

        if ($start === false) {
            // Anchor on the chunk's first line / leading token to keep start
            // truthful for whitespace-normalized joins.
            $anchor = trim((string) preg_split('/\r\n|\r|\n/', $chunkText, 2)[0]);

            $start = $anchor !== ''
                ? mb_strpos($fullText, $anchor, $cursor, 'UTF-8')
                : false;

            if ($start === false) {
                $start = $cursor;
            }
        }

        return [$start, $start + $chunkLength];
    }

    /**
     * Guard that every chunk has exactly one matching embedding so a chunk can
     * never be paired with the wrong vector or an empty/short batch silently
     * becoming an unsearchable empty vector.
     *
     * @param array $textChunks
     * @param array $embeddings
     * @return void
     * @throws \RuntimeException
     */
    private function assertEmbeddingsAlignWithChunks(array $textChunks, array $embeddings): void
    {
        if (count($embeddings) !== count($textChunks)) {
            throw new \RuntimeException(sprintf(
                'Embedding/chunk count mismatch: %d chunks vs %d embeddings.',
                count($textChunks),
                count($embeddings)
            ));
        }

        foreach ($textChunks as $index => $chunkText) {
            $vector = $embeddings[$index] ?? null;

            if (!is_array($vector) || $vector === []) {
                throw new \RuntimeException(
                    "Empty or missing embedding for chunk index {$index}."
                );
            }
        }
    }
}
