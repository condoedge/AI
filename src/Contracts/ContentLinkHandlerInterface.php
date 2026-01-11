<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Interface for inline content link handlers.
 *
 * Handlers process specific link patterns in AI responses and create
 * clickable Kompo elements. Each handler specializes in one link type:
 * - ActionLinkHandler: entity:// and action:// protocols
 * - FileCitationHandler: [1], [2] numbered citations
 *
 * All handlers follow the same pattern:
 * 1. Match links in content using regex
 * 2. Create Kompo elements for matched links
 * 3. Strip links to plain text for clean content
 */
interface ContentLinkHandlerInterface
{
    /**
     * Get the regex pattern(s) this handler matches.
     *
     * @return string|array Single pattern or array of patterns
     */
    public function getPatterns(): string|array;

    /**
     * Check if content contains links this handler can process.
     *
     * @param string $content The content to check
     * @return bool True if content contains matchable links
     */
    public function hasLinks(string $content): bool;

    /**
     * Create Kompo elements for all matched links.
     *
     * @param string $content The content to process
     * @param array $context Additional context (files, user, etc.)
     * @return array Array of Kompo elements
     */
    public function createElements(string $content, array $context = []): array;

    /**
     * Strip links from content, leaving display text.
     *
     * @param string $content The content to process
     * @return string Content with links replaced by plain text
     */
    public function stripLinks(string $content): string;
}
