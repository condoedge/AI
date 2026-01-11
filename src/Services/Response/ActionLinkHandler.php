<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

/**
 * Handler for entity:// and action:// protocol links.
 *
 * Processes AI-generated action links and creates Kompo elements
 * using configured resolvers from config/ai.php.
 *
 * Link formats:
 * - [text](entity://EntityType/id/action_key) → Entity action
 * - [text](action://action_key) → Generic action
 *
 * Resolvers are configured in config/ai.php:
 * - entity_actions.EntityType.action_key.action = fn($id, $text) => _Link(...)
 * - generic_actions.action_key.action = fn($text) => _Link(...)
 */
class ActionLinkHandler extends AbstractContentLinkHandler
{
    private const ENTITY_PATTERN = '/\[([^\]]+)\]\(entity:\/\/([^\/]+)\/([^\/]+)\/([^\)]+)\)/';
    private const ACTION_PATTERN = '/\[([^\]]+)\]\(action:\/\/([^\)]+)\)/';

    public function __construct(
        private ?EntityAutoDiscovery $discovery = null
    ) {
        $this->discovery = $discovery ?? app(EntityAutoDiscovery::class);
    }

    public function getPatterns(): array
    {
        return [self::ENTITY_PATTERN, self::ACTION_PATTERN];
    }

    protected function getDeduplicationKey(array $match): string
    {
        // Entity link: [text](entity://Type/id/action)
        if (count($match) === 5) {
            return "entity-{$match[2]}-{$match[3]}-{$match[4]}";
        }

        // Generic action: [text](action://action_key)
        return "generic-{$match[2]}";
    }

    protected function createElementForMatch(array $match, array $context): mixed
    {
        // Entity link: [text](entity://Type/id/action)
        if (count($match) === 5) {
            $text = $match[1];
            $entityType = $match[2];
            $entityId = $match[3];
            $actionKey = $match[4];

            $resolver = $this->discovery->getEntityActionResolver($entityType, $actionKey);
            if ($resolver) {
                return $resolver($entityId, $text);
            }

            return null;
        }

        // Generic action: [text](action://action_key)
        $text = $match[1];
        $actionKey = $match[2];

        $resolver = $this->discovery->getGenericActionResolver($actionKey);
        if ($resolver) {
            return $resolver($text);
        }

        return null;
    }

    protected function getStripReplacement(array $match): string
    {
        // Return the link text (first capture group)
        return $match[1];
    }
}
