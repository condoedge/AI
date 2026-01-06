<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Contracts\EntityAccessCheckerInterface;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

/**
 * Response Action Link Processor
 *
 * Extracts action links from AI responses and prepares them for rendering.
 * Handles both entity:// and action:// protocol links.
 *
 * Link formats:
 * - [text](entity://EntityType/id/action_key)
 * - [text](action://action_key)
 */
class ResponseActionLinkProcessor
{
    private const ENTITY_PATTERN = '/\[([^\]]+)\]\(entity:\/\/([^\/]+)\/([^\/]+)\/([^\)]+)\)/';
    private const ACTION_PATTERN = '/\[([^\]]+)\]\(action:\/\/([^\)]+)\)/';

    public function __construct(
        private ?EntityAutoDiscovery $discovery = null,
        private ?EntityAccessCheckerInterface $accessChecker = null
    ) {
        $this->discovery = $discovery ?? app(EntityAutoDiscovery::class);
    }

    /**
     * Extract all action links from response text
     *
     * @param string $response The AI response text
     * @param mixed $user Optional user for access checking
     * @return array Array of action link metadata
     */
    public function extractActionLinks(string $response, mixed $user = null): array
    {
        $links = [];

        // Extract entity action links
        preg_match_all(self::ENTITY_PATTERN, $response, $entityMatches, PREG_SET_ORDER);
        foreach ($entityMatches as $match) {
            $entityType = $match[2];
            $entityId = $match[3];

            // Skip entity links user cannot access (when access checker is provided)
            if ($this->accessChecker !== null && $user !== null) {
                if (!$this->accessChecker->canAccess($entityType, $entityId, $user)) {
                    continue;
                }
            }

            $links[] = [
                'type' => 'entity',
                'full_match' => $match[0],
                'text' => $match[1],
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action_key' => $match[4],
                'has_resolver' => $this->discovery->getEntityActionResolver($entityType, $match[4]) !== null,
            ];
        }

        // Extract generic action links (always included - no entity to check)
        preg_match_all(self::ACTION_PATTERN, $response, $actionMatches, PREG_SET_ORDER);
        foreach ($actionMatches as $match) {
            $links[] = [
                'type' => 'generic',
                'full_match' => $match[0],
                'text' => $match[1],
                'action_key' => $match[2],
                'has_resolver' => $this->discovery->getGenericActionResolver($match[2]) !== null,
            ];
        }

        return $links;
    }

    /**
     * Process response and return enriched data
     *
     * @param string $response The AI response text
     * @param mixed $user Optional user for access checking
     * @return array Contains 'action_links' array and 'has_action_links' boolean
     */
    public function processResponse(string $response, mixed $user = null): array
    {
        $links = $this->extractActionLinks($response, $user);

        return [
            'action_links' => $links,
            'has_action_links' => !empty($links),
        ];
    }

    /**
     * Enrich a response array with action link metadata
     *
     * @param array $response The response array (must contain 'answer' or 'content')
     * @param mixed $user Optional user for access checking
     * @return array The enriched response
     */
    public function enrichResponse(array $response, mixed $user = null): array
    {
        $content = $response['answer'] ?? $response['content'] ?? '';
        $actionData = $this->processResponse($content, $user);

        return array_merge($response, $actionData);
    }
}
