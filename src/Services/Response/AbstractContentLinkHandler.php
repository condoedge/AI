<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Contracts\ContentLinkHandlerInterface;

/**
 * Abstract base for content link handlers.
 *
 * Provides common functionality for processing inline links:
 * - Pattern matching with deduplication
 * - Element creation with error handling
 * - Link stripping for clean content
 *
 * Subclasses implement:
 * - getPatterns(): Define what to match
 * - createElementForMatch(): Create Kompo element for each match
 * - getStripReplacement(): Define what to replace links with
 */
abstract class AbstractContentLinkHandler implements ContentLinkHandlerInterface
{
    /**
     * Check if content contains matchable links.
     */
    public function hasLinks(string $content): bool
    {
        $patterns = (array) $this->getPatterns();

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create elements for all matched links (with deduplication).
     */
    public function createElements(string $content, array $context = []): array
    {
        $elements = [];
        $patterns = (array) $this->getPatterns();

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $key = $this->getDeduplicationKey($match);

                    if (isset($elements[$key])) {
                        continue;
                    }

                    try {
                        $element = $this->createElementForMatch($match, $context);
                        if ($element !== null) {
                            $elements[$key] = $element;
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Content link handler failed', [
                            'handler' => static::class,
                            'match' => $match[0] ?? '',
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return array_values($elements);
    }

    /**
     * Strip links from content.
     */
    public function stripLinks(string $content): string
    {
        $patterns = (array) $this->getPatterns();

        foreach ($patterns as $pattern) {
            $result = preg_replace_callback(
                $pattern,
                fn($match) => $this->getStripReplacement($match),
                $content
            );
            $content = $result ?? $content;
        }

        return $content;
    }

    /**
     * Get unique key for deduplication.
     *
     * @param array $match Regex match array
     * @return string Unique key for this link
     */
    abstract protected function getDeduplicationKey(array $match): string;

    /**
     * Create Kompo element for a matched link.
     *
     * @param array $match Regex match array
     * @param array $context Additional context
     * @return mixed Kompo element or null if cannot create
     */
    abstract protected function createElementForMatch(array $match, array $context): mixed;

    /**
     * Get replacement text when stripping links.
     *
     * @param array $match Regex match array
     * @return string Replacement text (usually the display text)
     */
    abstract protected function getStripReplacement(array $match): string;
}
