<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * A chunker that knows the document's STRUCTURE (Phase 7.1): it splits on
 * markdown headings and line-start article markers and returns, per chunk, the
 * stored text, the text to EMBED (prefixed with its heading path / article so
 * retrieval sees the context) and the chunk metadata stored in the vector payload.
 */
interface StructuredChunkerInterface extends FileChunkerInterface
{
    /**
     * @param string $content The text content to chunk
     * @param array $options FileChunkerInterface options plus:
     *   - min_chunk_chars: consecutive tiny articles/sections merge up to this size
     *   - max_chunk_chars: an article/section over this size is split within itself
     * @return list<array{text: string, embed_text: string, metadata: array{
     *     heading_path: list<string>, section_title: ?string, article_no: ?string,
     *     article_range: ?string, articles: list<string>, is_preamble: bool, page: ?int
     * }>>
     */
    public function chunkWithMetadata(string $content, array $options = []): array;
}
