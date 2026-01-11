<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

/**
 * Handler for file citation links [1], [2], etc.
 *
 * Processes numbered citations in AI responses and creates clickable
 * elements that open the file preview modal. Uses DEFAULT behavior
 * (no configuration needed) - files already have their preview modal.
 *
 * Citation format: [1], [2], [3]...
 *
 * Context must include 'files' array from message->getReferencedFiles()
 * with structure: [['id' => 1, 'name' => 'file.pdf', 'type' => 'pdf', ...], ...]
 */
class FileCitationHandler extends AbstractContentLinkHandler
{
    private const CITATION_PATTERN = '/\[(\d+)\]/';

    /** @var array Metadata for processed citations */
    private array $citationMetadata = [];

    public function getPatterns(): string
    {
        return self::CITATION_PATTERN;
    }

    protected function getDeduplicationKey(array $match): string
    {
        return "file-citation-{$match[1]}";
    }

    protected function createElementForMatch(array $match, array $context): mixed
    {
        $citationNumber = (int) $match[1];
        $files = $context['files'] ?? [];

        // Citations are 1-indexed, array is 0-indexed
        $fileIndex = $citationNumber - 1;

        if (!isset($files[$fileIndex])) {
            return null;
        }

        $file = $files[$fileIndex];

        // Track metadata for this citation
        $this->citationMetadata[] = [
            'slot' => "file-citation-{$citationNumber}",
            'id' => $file['id'] ?? null,
            'type' => $file['morphable_type'] ?? 'file',
            'mime' => $file['mime_type'] ?? null,
        ];

        return $this->createFileLink($file, $citationNumber);
    }

    /**
     * Get metadata for all processed citations.
     *
     * Returns array of citation metadata for proxy element creation.
     * Call after createElements() to get metadata for processed citations.
     *
     * @return array Array of ['slot' => string, 'id' => int, 'type' => string, 'mime' => string]
     */
    public function getCitationMetadata(): array
    {
        return $this->citationMetadata;
    }

    /**
     * Reset citation metadata.
     *
     * Call before processing a new content string to clear previous metadata.
     */
    public function resetMetadata(): void
    {
        $this->citationMetadata = [];
    }

    protected function getStripReplacement(array $match): string
    {
        // Remove citations from prose - clickable buttons shown at end
        return '';
    }

    /**
     * Create a clickable file citation link.
     *
     * Creates a visible link element with data-action-slot attribute for JS wiring.
     * The actual click behavior is wired via hidden proxy elements with matching
     * data-action-proxy attributes.
     */
    protected function createFileLink(array $file, int $citationNumber): mixed
    {
        $fileName = $file['name'] ?? "File {$citationNumber}";
        $slot = "file-citation-{$citationNumber}";

        return _Link("[{$citationNumber}]")
            ->class('text-indigo-600 font-medium cursor-pointer hover:underline')
            ->balloon($fileName, 'up')
            ->attr(['data-action-slot' => $slot]);
    }
}
