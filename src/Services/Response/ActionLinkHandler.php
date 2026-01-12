<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

/**
 * Handler for entity:// and action:// links in AI responses.
 *
 * Processes markdown-style links with custom protocols:
 * - [text](entity://EntityType/id/action_key)
 * - [text](action://action_key)
 *
 * Creates Kompo elements using configured action resolvers.
 */
class ActionLinkHandler extends AbstractContentLinkHandler
{
    private const COMBINED_PATTERN = '/\[([^\]]+)\]\((entity|action):\/\/([^\)]+)\)/';

    private ?EntityAutoDiscovery $discovery = null;

    public function __construct(?EntityAutoDiscovery $discovery = null)
    {
        $this->discovery = $discovery;
    }

    public function getPatterns(): string
    {
        return self::COMBINED_PATTERN;
    }

    protected function getDeduplicationKey(array $match): string
    {
        // Full URI is unique key: entity://Type/id/action or action://key
        return "{$match[2]}://{$match[3]}";
    }

    protected function createElementForMatch(array $match, array $context): mixed
    {
        $text = $match[1];
        $protocol = $match[2];
        $path = $match[3];

        if ($protocol === 'entity') {
            return $this->createEntityLink($text, $path);
        }

        return $this->createGenericLink($text, $path);
    }

    protected function getStripReplacement(array $match): string
    {
        // Keep the link text, remove the protocol URI
        return $match[1];
    }

    /**
     * Create element for entity action link.
     *
     * Path format: EntityType/id/action_key
     */
    private function createEntityLink(string $text, string $path): mixed
    {
        $parts = explode('/', $path, 3);

        if (count($parts) < 3) {
            return null;
        }

        [$entityType, $entityId, $actionKey] = $parts;

        $resolver = $this->getDiscovery()->getEntityActionResolver($entityType, $actionKey);

        if ($resolver === null) {
            // Fallback: return plain link text
            return _Html($text);
        }

        return $resolver($entityId, $text);
    }

    /**
     * Create element for generic action link.
     *
     * Path format: action_key
     */
    private function createGenericLink(string $text, string $actionKey): mixed
    {
        $resolver = $this->getDiscovery()->getGenericActionResolver($actionKey);

        if ($resolver === null) {
            // Fallback: return plain link text
            return _Html($text);
        }

        return $resolver($text);
    }

    private function getDiscovery(): EntityAutoDiscovery
    {
        if ($this->discovery === null) {
            $this->discovery = app(EntityAutoDiscovery::class);
        }

        return $this->discovery;
    }
}
