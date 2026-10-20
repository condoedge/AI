<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\StructuredChunkerInterface;
use Condoedge\Ai\Support\ArticleLocator;

/**
 * Semantic text chunking service
 *
 * Structured content (markdown headings, line-start article markers) is chunked
 * by STRUCTURE: one article / heading section per chunk, with heading-path and
 * article metadata (Phase 7.1). Plain text keeps the legacy splitter that
 * preserves paragraph and sentence boundaries.
 */
class SemanticChunker implements StructuredChunkerInterface
{
    /**
     * Default chunk size in characters
     */
    private const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Default overlap between chunks in characters
     */
    private const DEFAULT_OVERLAP = 200;

    /**
     * File type specific chunk sizes
     */
    private const CHUNK_SIZES = [
        'pdf' => 1200,
        'txt' => 1000,
        'md' => 1500,
        'docx' => 1200,
        'html' => 1500,
        'default' => 1000,
    ];

    /**
     * File type specific overlap sizes
     */
    private const OVERLAP_SIZES = [
        'pdf' => 200,
        'txt' => 150,
        'md' => 300,
        'docx' => 200,
        'html' => 300,
        'default' => 200,
    ];

    /**
     * Structure-aware chunking defaults (Phase 7.1). Consecutive tiny articles /
     * sections merge until a chunk reaches MIN; an article or section larger than
     * MAX is split within itself (each part keeps the same metadata). The largest
     * CCQ co-ownership article is ~1500 chars, so MAX keeps every article whole.
     */
    private const DEFAULT_MIN_CHUNK_CHARS = 200;
    private const DEFAULT_MAX_CHUNK_CHARS = 2000;

    /**
     * A document is an "article document" (legal corpus) once it defines at least
     * this many line-start articles — a stray "Article 12 …" line in a how-to guide
     * must not turn every other section into preamble.
     */
    private const MIN_ARTICLE_UNITS = 3;

    /**
     * In a non-article document a chunk is preamble only when it has (almost) no
     * body besides its headings — a short but real how-to section is content.
     */
    private const PREAMBLE_BODY_CHARS = 80;

    /**
     * {@inheritdoc}
     *
     * Structured content (markdown headings / line-start article markers) is
     * chunked by structure — the same texts chunkWithMetadata() returns. Plain
     * text keeps the legacy paragraph → sentence → character splitter.
     */
    public function chunk(string $content, array $options = []): array
    {
        $normalized = $this->normalizeLineEndings($content);
        if (trim($normalized) === '') {
            return [];
        }

        $units = $this->structuralUnits($normalized);
        if ($units !== null) {
            return array_column($this->structuredChunks($units, $options), 'text');
        }

        return $this->legacyChunk($content, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function chunkWithMetadata(string $content, array $options = []): array
    {
        $normalized = $this->normalizeLineEndings($content);
        if (trim($normalized) === '') {
            return [];
        }

        $units = $this->structuralUnits($normalized);
        if ($units !== null) {
            return $this->structuredChunks($units, $options);
        }

        return array_map(
            fn (string $text) => ['text' => $text, 'embed_text' => $text, 'metadata' => $this->chunkMetadata([], [], false)],
            array_values($this->legacyChunk($content, $options))
        );
    }

    /**
     * Split normalized content into structural UNITS: a unit starts at every
     * markdown heading and every line-start article marker (ArticleLocator) and
     * runs until the next one, so an article keeps its sub-paragraphs and its
     * amendment-history line. Fenced code blocks are opaque (a "# comment" in a
     * script is not a heading). Returns null when the content has no structure.
     *
     * @return list<array{lines: list<string>, article: ?string, path: list<string>}>|null
     */
    private function structuralUnits(string $content): ?array
    {
        $units = [];
        $current = null;
        $stack = [];
        $inFence = false;
        $structured = false;

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inFence = !$inFence;
            } elseif (!$inFence && preg_match('/^(#{1,6})\s+(.*\S)\s*$/', $line, $m) === 1) {
                $structured = true;
                $level = strlen($m[1]);
                while ($stack !== [] && $stack[count($stack) - 1]['level'] >= $level) {
                    array_pop($stack);
                }
                $title = $this->cleanHeading($m[2]);
                if ($title !== '') {
                    $stack[] = ['level' => $level, 'title' => $title];
                }
                if ($current !== null) {
                    $units[] = $current;
                }
                $current = ['lines' => [$line], 'article' => ArticleLocator::lineArticle($line), 'path' => array_column($stack, 'title')];
                continue;
            } elseif (!$inFence && ($article = ArticleLocator::lineArticle($line)) !== null) {
                $structured = true;
                if ($current !== null) {
                    $units[] = $current;
                }
                $current = ['lines' => [$line], 'article' => $article, 'path' => array_column($stack, 'title')];
                continue;
            }

            $current ??= ['lines' => [], 'article' => null, 'path' => array_column($stack, 'title')];
            $current['lines'][] = $line;
        }

        if ($current !== null) {
            $units[] = $current;
        }

        if (!$structured) {
            return null;
        }

        return array_values(array_filter($units, fn (array $u) => $this->unitText($u) !== ''));
    }

    /**
     * Group units into chunks: one article (or heading section) per chunk;
     * consecutive tiny units merge up to the min size — articles only with
     * articles under the SAME heading path, sections only with sections — and
     * never past the max size. An oversized single unit is split within itself.
     *
     * @param list<array{lines: list<string>, article: ?string, path: list<string>}> $units
     * @return list<array{text: string, embed_text: string, metadata: array}>
     */
    private function structuredChunks(array $units, array $options): array
    {
        $min = (int) ($options['min_chunk_chars'] ?? self::DEFAULT_MIN_CHUNK_CHARS);
        $max = max(1, (int) ($options['max_chunk_chars'] ?? self::DEFAULT_MAX_CHUNK_CHARS));

        $articleUnits = count(array_filter($units, static fn (array $u) => $u['article'] !== null));
        $isArticleDocument = $articleUnits >= self::MIN_ARTICLE_UNITS;

        $groups = [];
        $count = count($units);
        $i = 0;
        while ($i < $count) {
            $group = [$units[$i]];
            $length = mb_strlen($this->unitText($units[$i]), 'UTF-8');
            $i++;

            while ($i < $count && $length < $min && $this->canMerge($group[count($group) - 1], $units[$i])) {
                $nextLength = mb_strlen($this->unitText($units[$i]), 'UTF-8');
                if ($length + 1 + $nextLength > $max) {
                    break;
                }
                $group[] = $units[$i];
                $length += 1 + $nextLength;
                $i++;
            }

            $groups[] = $group;
        }

        $chunks = [];
        foreach ($groups as $group) {
            $lines = array_merge(...array_column($group, 'lines'));
            $text = trim(implode("\n", $lines));
            $articles = array_values(array_unique(array_filter(array_column($group, 'article'), static fn ($a) => $a !== null)));
            $path = $group[count($group) - 1]['path'];
            $isPreamble = $articles === [] && ($isArticleDocument || $this->bodyLength($lines) < self::PREAMBLE_BODY_CHARS);

            $parts = mb_strlen($text, 'UTF-8') > $max ? $this->splitOversized($text, $max) : [$text];
            $partCount = count($parts);

            foreach ($parts as $index => $part) {
                $metadata = $this->chunkMetadata($path, $articles, $isPreamble);
                if ($partCount > 1) {
                    $metadata['part'] = $index + 1;
                    $metadata['part_count'] = $partCount;
                }

                $chunks[] = [
                    'text' => $part,
                    'embed_text' => $this->embeddingText($part, $metadata),
                    'metadata' => $metadata,
                ];
            }
        }

        return $chunks;
    }

    /**
     * Whether $next may be merged after $last: articles only with articles under
     * the same heading path; non-article sections only with non-article sections
     * (a title/heading block never swallows the first article).
     */
    private function canMerge(array $last, array $next): bool
    {
        if ($last['article'] !== null) {
            return $next['article'] !== null && $next['path'] === $last['path'];
        }

        return $next['article'] === null;
    }

    /**
     * Split an oversized article/section within itself: on line (paragraph)
     * boundaries first, a line still over the max on sentence boundaries, and a
     * sentence still over the max on characters.
     *
     * @return list<string>
     */
    private function splitOversized(string $text, int $max): array
    {
        $pieces = [];
        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($line, 'UTF-8') <= $max) {
                $pieces[] = $line;
                continue;
            }
            foreach (preg_split('/(?<=[.!?;:])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [$line] as $sentence) {
                if (mb_strlen($sentence, 'UTF-8') <= $max) {
                    $pieces[] = $sentence;
                    continue;
                }
                foreach (mb_str_split($sentence, $max, 'UTF-8') as $slice) {
                    $pieces[] = $slice;
                }
            }
        }

        $parts = [];
        $current = '';
        foreach ($pieces as $piece) {
            $candidate = $current === '' ? $piece : $current . "\n" . $piece;
            if ($current !== '' && mb_strlen($candidate, 'UTF-8') > $max) {
                $parts[] = trim($current);
                $current = $piece;
                continue;
            }
            $current = $candidate;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return array_values(array_filter($parts, static fn (string $p) => $p !== ''));
    }

    /**
     * @param list<string> $path
     * @param list<string> $articles
     */
    private function chunkMetadata(array $path, array $articles, bool $isPreamble): array
    {
        $count = count($articles);

        return [
            'heading_path' => $path,
            'section_title' => $path === [] ? null : $path[count($path) - 1],
            'article_no' => $count === 1 ? $articles[0] : null,
            'article_range' => $count > 1 ? $articles[0] . '–' . $articles[$count - 1] : null,
            'articles' => $articles,
            'is_preamble' => $isPreamble,
            'page' => null,
        ];
    }

    /**
     * The text that is EMBEDDED: the chunk prefixed with its heading path and
     * article ("A > B — art. 1069:"), so retrieval sees where the passage sits
     * even though the stored text is just the article.
     */
    private function embeddingText(string $text, array $metadata): string
    {
        $prefix = implode(' > ', $metadata['heading_path']);

        $article = $metadata['article_no'] ?? $metadata['article_range'] ?? null;
        if ($article !== null) {
            $prefix .= ($prefix === '' ? '' : ' — ') . 'art. ' . $article;
        }

        return $prefix === '' ? $text : $prefix . ":\n" . $text;
    }

    private function unitText(array $unit): string
    {
        return trim(implode("\n", $unit['lines']));
    }

    /**
     * Characters of real body text: lines that are not headings, rules or blank.
     *
     * @param list<string> $lines
     */
    private function bodyLength(array $lines): int
    {
        $length = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match('/^#{1,6}\s/', $trimmed) === 1 || preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed) === 1) {
                continue;
            }
            $length += mb_strlen($trimmed, 'UTF-8');
        }

        return $length;
    }

    /**
     * A heading's plain title: markdown emphasis / code marks and closing hashes removed.
     */
    private function cleanHeading(string $heading): string
    {
        $heading = preg_replace('/\s+#+\s*$/', '', $heading) ?? $heading;
        $heading = str_replace(['**', '__', '`'], '', $heading);

        return trim(preg_replace('/\s+/u', ' ', $heading) ?? $heading);
    }

    /**
     * Legacy unstructured splitter: paragraphs, then sentences, then characters.
     */
    private function legacyChunk(string $content, array $options = []): array
    {
        $chunkSize = $options['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE;
        $overlap = $options['overlap'] ?? self::DEFAULT_OVERLAP;
        $preserveSentences = $options['preserve_sentences'] ?? true;
        $preserveParagraphs = $options['preserve_paragraphs'] ?? true;

        // Normalize line endings
        $content = $this->normalizeLineEndings($content);

        // Handle empty or whitespace-only content
        if (trim($content) === '') {
            return [];
        }

        // If content is smaller than chunk size, return as single chunk
        if (mb_strlen($content, 'UTF-8') <= $chunkSize) {
            return [$content];
        }

        // Try paragraph-based chunking first if enabled
        if ($preserveParagraphs) {
            $chunks = $this->chunkByParagraphs($content, $chunkSize, $overlap);
            if (count($chunks) > 0) {
                return $chunks;
            }
        }

        // Fall back to sentence-based chunking if enabled
        if ($preserveSentences) {
            $chunks = $this->chunkBySentences($content, $chunkSize, $overlap);
            if (count($chunks) > 0) {
                return $chunks;
            }
        }

        // Fall back to character-based chunking
        return $this->chunkByCharacters($content, $chunkSize, $overlap);
    }

    /**
     * {@inheritdoc}
     */
    public function getRecommendedChunkSize(string $fileType): int
    {
        return self::CHUNK_SIZES[$fileType] ?? self::CHUNK_SIZES['default'];
    }

    /**
     * {@inheritdoc}
     */
    public function getRecommendedOverlap(string $fileType): int
    {
        return self::OVERLAP_SIZES[$fileType] ?? self::OVERLAP_SIZES['default'];
    }

    /**
     * Normalize line endings to \n
     *
     * @param string $content
     * @return string
     */
    private function normalizeLineEndings(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * Chunk text by paragraphs
     *
     * @param string $content
     * @param int $chunkSize
     * @param int $overlap
     * @return array
     */
    private function chunkByParagraphs(string $content, int $chunkSize, int $overlap): array
    {
        // Split by double newlines (paragraphs)
        $paragraphs = preg_split('/\n\s*\n/', $content, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($paragraphs)) {
            return [];
        }

        return $this->groupIntoChunks($paragraphs, $chunkSize, $overlap, "\n\n");
    }

    /**
     * Chunk text by sentences
     *
     * @param string $content
     * @param int $chunkSize
     * @param int $overlap
     * @return array
     */
    private function chunkBySentences(string $content, int $chunkSize, int $overlap): array
    {
        // Split by sentence boundaries (. ! ?) followed by whitespace or end of string
        $sentences = preg_split('/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($sentences)) {
            return [];
        }

        return $this->groupIntoChunks($sentences, $chunkSize, $overlap, ' ');
    }

    /**
     * Chunk text by fixed character positions
     *
     * @param string $content
     * @param int $chunkSize
     * @param int $overlap
     * @return array
     */
    private function chunkByCharacters(string $content, int $chunkSize, int $overlap): array
    {
        $chunks = [];
        $length = mb_strlen($content, 'UTF-8');
        $position = 0;

        // Ensure overlap doesn't exceed chunk size minus 1 (need to move forward)
        $overlap = min($overlap, $chunkSize - 1);

        while ($position < $length) {
            $chunk = mb_substr($content, $position, $chunkSize, 'UTF-8');
            $chunks[] = $chunk;
            $position += $chunkSize - $overlap;
        }

        return array_filter($chunks, fn($chunk) => trim($chunk) !== '');
    }

    /**
     * Group text units (paragraphs or sentences) into chunks
     *
     * @param array $units Array of text units
     * @param int $chunkSize Target chunk size
     * @param int $overlap Overlap size
     * @param string $separator Separator between units
     * @return array
     */
    private function groupIntoChunks(array $units, int $chunkSize, int $overlap, string $separator): array
    {
        $chunks = [];
        $currentChunk = [];
        $currentLength = 0;
        $separatorLength = mb_strlen($separator, 'UTF-8');

        foreach ($units as $unit) {
            $unitLength = mb_strlen($unit, 'UTF-8');

            // If a single unit exceeds the chunk size, only that unit is split
            // (via finer character chunking). The boundary-aligned units around
            // it keep their boundaries instead of the whole document collapsing
            // to blind character slicing.
            if ($unitLength > $chunkSize) {
                // Flush whatever boundary-aligned content is buffered first so
                // the oversized unit's slices don't get fused onto it.
                if (!empty($currentChunk)) {
                    $chunks[] = implode($separator, $currentChunk);
                    $currentChunk = [];
                    $currentLength = 0;
                }

                foreach ($this->chunkByCharacters($unit, $chunkSize, $overlap) as $slice) {
                    $chunks[] = $slice;
                }

                continue;
            }

            // If adding this unit would exceed chunk size and we already have content
            if ($currentLength > 0 && ($currentLength + $unitLength + $separatorLength) > $chunkSize) {
                // Save current chunk
                $chunks[] = implode($separator, $currentChunk);

                // Start new chunk with overlap
                $currentChunk = $this->getOverlapUnits($currentChunk, $overlap, $separator);
                $currentLength = mb_strlen(implode($separator, $currentChunk), 'UTF-8');
            }

            $currentChunk[] = $unit;
            $currentLength += $unitLength + $separatorLength;
        }

        // Add final chunk if not empty
        if (!empty($currentChunk)) {
            $chunks[] = implode($separator, $currentChunk);
        }

        return array_filter($chunks, fn($chunk) => trim($chunk) !== '');
    }

    /**
     * Get units to include in overlap
     *
     * @param array $units Previous units
     * @param int $overlapSize Target overlap size
     * @param string $separator Separator between units
     * @return array
     */
    private function getOverlapUnits(array $units, int $overlapSize, string $separator): array
    {
        $overlapUnits = [];
        $overlapLength = 0;
        $separatorLength = mb_strlen($separator, 'UTF-8');

        // Take units from the end until we reach overlap size
        for ($i = count($units) - 1; $i >= 0; $i--) {
            $unit = $units[$i];
            $unitLength = mb_strlen($unit, 'UTF-8');

            if ($overlapLength + $unitLength > $overlapSize) {
                break;
            }

            array_unshift($overlapUnits, $unit);
            $overlapLength += $unitLength + $separatorLength;
        }

        return $overlapUnits;
    }
}
