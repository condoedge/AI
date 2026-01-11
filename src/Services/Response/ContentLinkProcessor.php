<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Contracts\ContentLinkHandlerInterface;

/**
 * Orchestrates processing of all inline content links.
 *
 * Combines multiple handlers (actions, file citations) into a single
 * entry point for processing AI response content.
 *
 * Usage:
 *   $processor = app(ContentLinkProcessor::class);
 *   $result = $processor->processForDirectRendering($content, ['files' => $files]);
 *   // $result = ['content' => '...', 'elements' => [...], 'has_links' => true]
 *
 * Default handlers:
 * - ActionLinkHandler: entity:// and action:// links (configured)
 * - FileCitationHandler: [1], [2] citations (default modal)
 *
 * Extensible: Add custom handlers via registerHandler()
 */
class ContentLinkProcessor
{
    /** @var ContentLinkHandlerInterface[] */
    private array $handlers = [];

    /** @var FileCitationHandler|null Reference for metadata extraction */
    private ?FileCitationHandler $citationHandler = null;

    public function __construct(
        ?ActionLinkHandler $actionHandler = null,
        ?FileCitationHandler $citationHandler = null
    ) {
        // Register default handlers
        if ($actionHandler) {
            $this->handlers['actions'] = $actionHandler;
        }
        if ($citationHandler) {
            $this->handlers['citations'] = $citationHandler;
            $this->citationHandler = $citationHandler;
        }
    }

    /**
     * Register a custom handler.
     *
     * @param string $name Handler name for identification
     * @param ContentLinkHandlerInterface $handler The handler instance
     * @return self
     */
    public function registerHandler(string $name, ContentLinkHandlerInterface $handler): self
    {
        $this->handlers[$name] = $handler;
        return $this;
    }

    /**
     * Check if content contains any processable links.
     */
    public function hasLinks(string $content): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler->hasLinks($content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create all elements from all handlers.
     *
     * @param string $content The content to process
     * @param array $context Context for handlers (files, user, etc.)
     * @return array All Kompo elements from all handlers
     */
    public function createElements(string $content, array $context = []): array
    {
        $allElements = [];

        foreach ($this->handlers as $handler) {
            $elements = $handler->createElements($content, $context);
            $allElements = array_merge($allElements, $elements);
        }

        return $allElements;
    }

    /**
     * Strip all links from content.
     *
     * @param string $content The content to process
     * @return string Clean content with links stripped
     */
    public function stripLinks(string $content): string
    {
        foreach ($this->handlers as $handler) {
            $content = $handler->stripLinks($content);
        }

        return $content;
    }

    /**
     * Process content for direct rendering.
     *
     * Strips links and creates elements in one pass.
     * This is the main entry point for message rendering.
     *
     * @param string $content The AI response content
     * @param array $context Context for handlers (files, user, etc.)
     * @return array{content: string, elements: array, has_links: bool, file_citations: array}
     */
    public function processForDirectRendering(string $content, array $context = []): array
    {
        // Reset citation metadata before processing
        if ($this->citationHandler) {
            $this->citationHandler->resetMetadata();
        }

        $elements = $this->createElements($content, $context);
        $cleanContent = $this->stripLinks($content);

        // Collect file citation metadata for proxy element creation
        $fileCitations = $this->citationHandler
            ? $this->citationHandler->getCitationMetadata()
            : [];

        return [
            'content' => $cleanContent,
            'elements' => $elements,
            'has_links' => !empty($elements),
            'file_citations' => $fileCitations,
        ];
    }

    /**
     * Get registered handler names.
     */
    public function getHandlerNames(): array
    {
        return array_keys($this->handlers);
    }
}
