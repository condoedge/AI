<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Interface for content link handlers.
 *
 * Handlers process specific link patterns in AI response content
 * and create Kompo elements for interactive display.
 */
interface ContentLinkHandlerInterface
{
    /**
     * Get the regex pattern(s) this handler processes.
     *
     * @return string Regex pattern for matching links
     */
    public function getPatterns(): string;

    /**
     * Check if content contains links this handler processes.
     *
     * @param string $content The content to check
     * @return bool True if handler can process links in this content
     */
    public function hasLinks(string $content): bool;

    /**
     * Create Kompo elements for all matched links.
     *
     * @param string $content The content to process
     * @param array $context Context for element creation (files, user, etc.)
     * @return array Array of Kompo elements
     */
    public function createElements(string $content, array $context = []): array;

    /**
     * Strip all links from content.
     *
     * @param string $content The content to process
     * @return string Content with links removed/replaced
     */
    public function stripLinks(string $content): string;
}
