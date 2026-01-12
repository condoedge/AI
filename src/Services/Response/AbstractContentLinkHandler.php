<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Contracts\ContentLinkHandlerInterface;

/**
 * Base class for content link handlers.
 *
 * Provides common functionality for processing links in AI response content:
 * - Pattern matching and extraction
 * - Deduplication of repeated links
 * - Template methods for subclasses
 *
 * Subclasses implement:
 * - getPatterns(): The regex pattern to match
 * - createElementForMatch(): Create Kompo element for a match
 * - getDeduplicationKey(): Key for deduplicating matches
 * - getStripReplacement(): What to replace links with when stripping
 */
abstract class AbstractContentLinkHandler implements ContentLinkHandlerInterface
{
    /**
     * Check if content contains processable links.
     */
    public function hasLinks(string $content): bool
    {
        return (bool) preg_match($this->getPatterns(), $content);
    }

    /**
     * Create elements for all matched links.
     *
     * Handles deduplication automatically - each unique link only
     * creates one element even if it appears multiple times.
     */
    public function createElements(string $content, array $context = []): array
    {
        $elements = [];
        $seen = [];

        if (!preg_match_all($this->getPatterns(), $content, $matches, PREG_SET_ORDER)) {
            return $elements;
        }

        foreach ($matches as $match) {
            $key = $this->getDeduplicationKey($match);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $element = $this->createElementForMatch($match, $context);

            if ($element !== null) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /**
     * Strip all links from content.
     */
    public function stripLinks(string $content): string
    {
        return preg_replace_callback(
            $this->getPatterns(),
            fn(array $match) => $this->getStripReplacement($match),
            $content
        ) ?? $content;
    }

    /**
     * Get a unique key for deduplicating this match.
     *
     * @param array $match Regex match array
     * @return string Unique key for this link
     */
    abstract protected function getDeduplicationKey(array $match): string;

    /**
     * Create a Kompo element for a matched link.
     *
     * @param array $match Regex match array
     * @param array $context Processing context
     * @return mixed Kompo element or null to skip
     */
    abstract protected function createElementForMatch(array $match, array $context): mixed;

    /**
     * Get replacement text when stripping this link.
     *
     * @param array $match Regex match array
     * @return string Replacement text (empty string to remove completely)
     */
    abstract protected function getStripReplacement(array $match): string;
}
