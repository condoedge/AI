<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

/**
 * ResponseEntityActionsSection
 *
 * Informs the AI about available entity and generic actions,
 * and how to format action links in responses.
 *
 * Gets entity types from:
 * 1. Conversation context's focused_entity (most reliable)
 * 2. Cypher query parsing (fallback)
 *
 * Gets entity IDs from:
 * 1. Conversation context's last_result_sample
 * 2. Current response data
 *
 * Priority 55 places this after data (50) but before statistics (60).
 */
class ResponseEntityActionsSection extends BaseResponseSection
{
    protected string $name = 'entity_actions';
    protected int $priority = 55;

    private ?EntityAutoDiscovery $discovery = null;

    public function __construct()
    {
        parent::__construct();
        $this->discovery = app(EntityAutoDiscovery::class);
    }

    /**
     * Include when there are entity types with actions OR generic actions
     */
    public function shouldInclude(array $context, array $options = []): bool
    {
        // Get entity types from all sources
        $entityTypes = $this->resolveEntityTypes($context);

        foreach ($entityTypes as $entityType) {
            if ($this->discovery->hasEntityActions($entityType)) {
                return true;
            }
        }

        // Check for generic actions
        $genericActions = $this->discovery->getGenericActions();
        return !empty($genericActions);
    }

    public function format(array $context, array $options = []): string
    {
        $output = $this->header('Action Links');
        $output .= "You can include clickable action links in your response using Markdown syntax.\n\n";

        // Resolve entity types and IDs
        $entityTypes = $this->resolveEntityTypes($context);
        $entityData = $this->resolveEntityData($context);

        foreach ($entityTypes as $entityType) {
            $actions = $this->discovery->getEntityActions($entityType);
            if (empty($actions)) {
                continue;
            }

            $output .= "**{$entityType} Actions:**\n";
            foreach ($actions as $action) {
                $aliases = implode(', ', $action['aliases'] ?? []);
                $output .= "- Format: `[display text](entity://{$entityType}/ID/{$action['action_key']})` - {$action['label']}";
                if ($aliases) {
                    $output .= " (user may say: {$aliases})";
                }
                $output .= "\n";
            }

            // Show available entity IDs
            if (!empty($entityData)) {
                $output .= "\n**Available {$entityType} IDs:**\n";
                foreach ($entityData as $entity) {
                    $id = $entity['id'] ?? $entity['_id'] ?? $entity['neo4j_id'] ?? null;
                    $name = $entity['name'] ?? $entity['title'] ?? $entity['full_name'] ?? 'Unknown';
                    if ($id) {
                        $firstActionKey = $actions[0]['action_key'] ?? 'profile';
                        $output .= "- {$name}: `[View {$name}](entity://{$entityType}/{$id}/{$firstActionKey})`\n";
                    }
                }
            }

            $output .= "\n";
        }

        // Generic actions
        $genericActions = $this->discovery->getGenericActions();
        if (!empty($genericActions)) {
            $output .= "**Generic Actions:**\n";
            foreach ($genericActions as $action) {
                $aliases = implode(', ', $action['aliases'] ?? []);
                $output .= "- Format: `[display text](action://{$action['action_key']})` - {$action['label']}";
                if ($aliases) {
                    $output .= " (user may say: {$aliases})";
                }
                $output .= "\n";
            }
            $output .= "\n";
        }

        $output .= "**CRITICAL:** When the user asks for a link, profile, or action listed above:\n";
        $output .= "- Use the EXACT entity IDs shown above\n";
        $output .= "- Format: `[Display Text](entity://EntityType/ID/action_key)`\n";
        $output .= "- Example: `[View John Doe](entity://Person/152463/profile)`\n\n";

        return $output;
    }

    /**
     * Resolve entity types from multiple sources (priority order)
     */
    private function resolveEntityTypes(array $context): array
    {
        $types = [];

        // 1. Conversation context focused_entity (most reliable)
        $conversationContext = $context['conversation_context'] ?? [];
        if (!empty($conversationContext['focused_entity'])) {
            $types[] = $conversationContext['focused_entity'];
        }

        // 2. Mentioned entities from conversation
        if (!empty($conversationContext['mentioned_entities'])) {
            $types = array_merge($types, $conversationContext['mentioned_entities']);
        }

        // 3. Parse from Cypher query (fallback)
        $cypher = $context['cypher'] ?? '';
        if ($cypher) {
            $cypherLabels = $this->extractLabelsFromCypher($cypher);
            $types = array_merge($types, $cypherLabels);
        }

        return array_unique($types);
    }

    /**
     * Resolve entity data (IDs and names) from multiple sources
     */
    private function resolveEntityData(array $context): array
    {
        // 1. Current response data
        $currentData = $context['data'] ?? [];

        // 2. Conversation context last_result_sample (for NO QUERY cases)
        $conversationContext = $context['conversation_context'] ?? [];
        $lastResults = $conversationContext['last_result_sample'] ?? [];

        // Prefer current data if available, otherwise use conversation context
        if (!empty($currentData)) {
            return $currentData;
        }

        return $lastResults;
    }

    /**
     * Extract entity labels from a Cypher query (fallback method)
     */
    private function extractLabelsFromCypher(string $cypher): array
    {
        $labels = [];

        // Match patterns like (variable:Label) or (:Label)
        // Handles: (p:Person), (:Person), (n:Person:Employee)
        if (preg_match_all('/\([\w]*:(\w+(?::\w+)*)\)/', $cypher, $matches)) {
            foreach ($matches[1] as $labelGroup) {
                foreach (explode(':', $labelGroup) as $label) {
                    if ($label) {
                        $labels[] = $label;
                    }
                }
            }
        }

        return array_unique($labels);
    }
}
