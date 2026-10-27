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
 * with structure: [['ref' => 1, 'id' => 1, 'name' => 'file.pdf', ...], ...].
 * [N] resolves to the item whose 'ref' is N (the list is compact, not positional).
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

    /**
     * Create elements for all matched citations.
     *
     * Overrides parent to auto-reset metadata before processing,
     * preventing state accumulation when handler is reused.
     */
    public function createElements(string $content, array $context = []): array
    {
        $this->resetMetadata();

        // Hosts whose users cannot open the cited files show no citation link (the markers are still stripped).
        if (!config('ai.chat.show_file_references', true)) {
            return [];
        }

        return parent::createElements($content, $context);
    }

    protected function getDeduplicationKey(array $match): string
    {
        return "file-citation-{$match[1]}";
    }

    protected function createElementForMatch(array $match, array $context): mixed
    {
        $citationNumber = (int) $match[1];
        $file = $this->findFileByRef($context['files'] ?? [], $citationNumber);

        if ($file === null) {
            return null;
        }

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
     * Resolve a citation number to its file by the item's 'ref'.
     *
     * The persisted referenced_files list (ResponseFileEnricher::buildReferencedFiles)
     * is COMPACT — only the cited files, in order of first citation — so array
     * position is NOT the citation number: "[3] … [1]" yields [ref 3, ref 1].
     * Each item carries its prompt citation number in 'ref'. When ANY item has a
     * 'ref', lookup is by ref only (a ref-less item is never matched by position,
     * which could shadow the real [N]). Only a list where NO item has a 'ref'
     * (hand-built context) is resolved by 1-based position.
     */
    private function findFileByRef(array $files, int $citationNumber): ?array
    {
        $files = array_values(array_filter($files, 'is_array'));

        $anyRef = false;
        foreach ($files as $candidate) {
            if (isset($candidate['ref'])) {
                $anyRef = true;
                if ((int) $candidate['ref'] === $citationNumber) {
                    return $candidate;
                }
            }
        }

        if ($anyRef) {
            return null;
        }

        return $files[$citationNumber - 1] ?? null;
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

    /** A run of markers ("[2], [3] ,[4]") goes with its separators and the space before it. */
    public function stripLinks(string $content): string
    {
        return preg_replace('/\s*\[\d+\](?:\s*[,;]?\s*\[\d+\])*/u', '', $content) ?? $content;
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
        // Whose document it is (the record an uploaded file is filed on, or the
        // "general document" line of a project file) — beside the name.
        $scope = trim((string) ($file['scope_label'] ?? ''));
        if ($scope !== '') {
            $fileName .= " · {$scope}";
        }
        $slot = "file-citation-{$citationNumber}";

        return _Link("[{$citationNumber}]")
            ->class('text-indigo-600 font-medium cursor-pointer hover:underline')
            ->balloon($fileName, 'up')
            ->attr(['data-action-slot' => $slot]);
    }
}
