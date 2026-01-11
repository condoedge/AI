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
 *
 * Slot-Fill Pattern:
 * - processActionLinks() converts markdown links to slots in HTML
 * - extractActionLinkFillers() creates Kompo elements to fill those slots
 * - JS fillActionSlots() moves fillers into slots, making them visible and functional
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
     * Process action links in HTML content, converting them to slots.
     *
     * Converts:
     * - [text](entity://Type/id/action) → <span data-action-slot="..." data-fallback-text="text"></span>
     * - [text](action://action_key) → <span data-action-slot="..." data-fallback-text="text"></span>
     *
     * The data-fallback-text attribute enables graceful degradation:
     * if no filler exists (resolver not configured), JS shows the fallback text.
     *
     * @param string $content HTML content after markdown rendering
     * @return string Content with action links replaced by slots
     */
    public function processActionLinks(string $content): string
    {
        // Replace entity action links with slots
        // Note: Values are used in data attributes AND must match extractActionLinkFillers keys exactly
        $result = preg_replace_callback(
            self::ENTITY_PATTERN,
            function ($matches) {
                $text = $matches[1];
                $slotId = "entity-{$matches[2]}-{$matches[3]}-{$matches[4]}";
                $escapedSlotId = htmlspecialchars($slotId, ENT_QUOTES, 'UTF-8');
                $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

                return "<span data-action-slot=\"{$escapedSlotId}\" data-fallback-text=\"{$escapedText}\" class=\"action-slot\"></span>";
            },
            $content
        );
        $content = $result ?? $content;

        // Replace generic action links with slots
        $result = preg_replace_callback(
            self::ACTION_PATTERN,
            function ($matches) {
                $text = $matches[1];
                $slotId = "generic-{$matches[2]}";
                $escapedSlotId = htmlspecialchars($slotId, ENT_QUOTES, 'UTF-8');
                $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

                return "<span data-action-slot=\"{$escapedSlotId}\" data-fallback-text=\"{$escapedText}\" class=\"action-slot\"></span>";
            },
            $content
        );

        return $result ?? $content;
    }

    /**
     * Extract action links and create Kompo filler elements.
     *
     * Creates hidden Kompo elements that will be moved into slots by JS.
     * Each filler has data-fills-slot attribute matching the slot's data-action-slot.
     *
     * @param string $content Original content (before markdown rendering)
     * @return array Array of Kompo elements to be rendered as fillers
     */
    public function extractActionLinkFillers(string $content): array
    {
        $fillers = [];

        // Extract entity action links
        if (preg_match_all(self::ENTITY_PATTERN, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $text = $match[1];
                $entityType = $match[2];
                $entityId = $match[3];
                $actionKey = $match[4];

                $key = "entity-{$entityType}-{$entityId}-{$actionKey}";
                if (isset($fillers[$key])) {
                    continue;
                }

                $resolver = $this->discovery->getEntityActionResolver($entityType, $actionKey);
                if ($resolver) {
                    try {
                        $element = $resolver($entityId, $text);
                        if ($element) {
                            $fillers[$key] = $element
                                ->attr(['data-fills-slot' => $key])
                                ->class('hidden action-filler');
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Action link resolver failed', [
                            'entity_type' => $entityType,
                            'entity_id' => $entityId,
                            'action_key' => $actionKey,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Extract generic action links
        if (preg_match_all(self::ACTION_PATTERN, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $text = $match[1];
                $actionKey = $match[2];

                $key = "generic-{$actionKey}";
                if (isset($fillers[$key])) {
                    continue;
                }

                $resolver = $this->discovery->getGenericActionResolver($actionKey);
                if ($resolver) {
                    try {
                        $element = $resolver($text);
                        if ($element) {
                            $fillers[$key] = $element
                                ->attr(['data-fills-slot' => $key])
                                ->class('hidden action-filler');
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Action link resolver failed', [
                            'action_key' => $actionKey,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return array_values($fillers);
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

    /**
     * Create visible Kompo action elements for direct rendering.
     *
     * Unlike extractActionLinkFillers() which creates hidden elements for JS to move,
     * this creates visible, styled elements ready to render directly in PHP.
     *
     * Use this when you want to append action buttons at the end of a message
     * or in a dedicated actions section, without relying on JS slot filling.
     *
     * @param string $content The markdown content with action links
     * @return array Array of visible Kompo elements
     */
    public function createActionElements(string $content): array
    {
        $elements = [];

        // Extract entity action links
        if (preg_match_all(self::ENTITY_PATTERN, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $text = $match[1];
                $entityType = $match[2];
                $entityId = $match[3];
                $actionKey = $match[4];

                $key = "entity-{$entityType}-{$entityId}-{$actionKey}";
                if (isset($elements[$key])) {
                    continue;
                }

                $resolver = $this->discovery->getEntityActionResolver($entityType, $actionKey);
                if ($resolver) {
                    try {
                        $element = $resolver($entityId, $text);
                        if ($element) {
                            $elements[$key] = $element;
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Action link resolver failed', [
                            'entity_type' => $entityType,
                            'entity_id' => $entityId,
                            'action_key' => $actionKey,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Extract generic action links
        if (preg_match_all(self::ACTION_PATTERN, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $text = $match[1];
                $actionKey = $match[2];

                $key = "generic-{$actionKey}";
                if (isset($elements[$key])) {
                    continue;
                }

                $resolver = $this->discovery->getGenericActionResolver($actionKey);
                if ($resolver) {
                    try {
                        $element = $resolver($text);
                        if ($element) {
                            $elements[$key] = $element;
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Action link resolver failed', [
                            'action_key' => $actionKey,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return array_values($elements);
    }

    /**
     * Strip action links from content, leaving just the link text.
     *
     * Use this when you want clean markdown/HTML without action link protocols,
     * combined with createActionElements() for a separate actions section.
     *
     * @param string $content The content with action links
     * @return string Content with action links replaced by their display text
     */
    public function stripActionLinks(string $content): string
    {
        // Replace entity links with just the text
        $result = preg_replace(self::ENTITY_PATTERN, '$1', $content);
        $content = $result ?? $content;

        // Replace generic links with just the text
        $result = preg_replace(self::ACTION_PATTERN, '$1', $content);

        return $result ?? $content;
    }

    /**
     * Process content and return both clean content and action elements.
     *
     * Convenience method that combines stripActionLinks() and createActionElements().
     * Returns everything needed for the "actions at end" pattern.
     *
     * @param string $content The markdown content with action links
     * @return array{content: string, elements: array, has_actions: bool}
     */
    public function processForDirectRendering(string $content): array
    {
        $elements = $this->createActionElements($content);
        $cleanContent = $this->stripActionLinks($content);

        return [
            'content' => $cleanContent,
            'elements' => $elements,
            'has_actions' => !empty($elements),
        ];
    }
}
