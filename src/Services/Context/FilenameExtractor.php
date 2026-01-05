<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

/**
 * Extracts filenames from natural language queries.
 *
 * Detects explicit file references like "bariloche.txt" or "report.pdf"
 * to enable filename-based search alongside semantic search.
 */
class FilenameExtractor
{
    /**
     * Common file extensions to detect
     */
    private const EXTENSIONS = [
        'txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv',
        'md', 'json', 'xml', 'html', 'htm', 'rtf', 'odt',
        'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'gif', 'svg',
    ];

    /**
     * Extract filenames from a query string.
     *
     * @param string $query The user's query
     * @return array List of extracted filenames (without paths)
     */
    public function extract(string $query): array
    {
        // Skip if query looks like a URL
        if ($this->containsUrl($query)) {
            return [];
        }

        $filenames = [];

        // Pattern 1: Quoted filenames (can contain spaces)
        // Matches: "my document.pdf", 'report.xlsx'
        $quotedPattern = '/["\']([^"\']+\.(' . $this->getExtensionPattern() . '))["\']' . '/i';
        if (preg_match_all($quotedPattern, $query, $matches)) {
            foreach ($matches[1] as $match) {
                if (!empty($match)) {
                    $filenames[] = $match;
                }
            }
        }

        // Remove quoted parts from query before processing unquoted patterns
        // This prevents double-matching filenames inside quotes
        $queryWithoutQuotes = preg_replace('/["\'][^"\']*["\']/', '', $query);

        // Pattern 2: Unquoted filenames (word characters, hyphens, underscores)
        // Matches: report.pdf, budget_2024.xlsx, my-notes.txt
        // Uses word boundary and ensures the filename part before the dot contains only valid chars
        $unquotedPattern = '/(?<![\/:\w])([\w][\w\-_]*\.(' . $this->getExtensionPattern() . '))(?!\w)/i';
        if (preg_match_all($unquotedPattern, $queryWithoutQuotes, $matches)) {
            foreach ($matches[1] as $match) {
                if (!empty($match) && !in_array($match, $filenames)) {
                    $filenames[] = $match;
                }
            }
        }

        // Pattern 3: Path with filename - extract just the filename
        // Matches: docs/readme.md -> readme.md
        $pathPattern = '/[\/\\\\]([\w][\w\-_]*\.(' . $this->getExtensionPattern() . '))(?!\w)/i';
        if (preg_match_all($pathPattern, $queryWithoutQuotes, $matches)) {
            foreach ($matches[1] as $match) {
                if (!empty($match) && !in_array($match, $filenames)) {
                    $filenames[] = $match;
                }
            }
        }

        return array_values(array_unique($filenames));
    }

    /**
     * Check if query contains a URL.
     */
    private function containsUrl(string $query): bool
    {
        return (bool) preg_match('/https?:\/\/[^\s]+/i', $query);
    }

    /**
     * Build regex pattern for file extensions.
     */
    private function getExtensionPattern(): string
    {
        return implode('|', self::EXTENSIONS);
    }
}
