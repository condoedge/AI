<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * EntityActionAwarenessSection
 *
 * Informs the QueryGenerator about available entity actions so it knows:
 * - "profile link", "profile page" etc. are actions, NOT database fields
 * - When user asks for actions AND context has entity IDs, return NO QUERY REQUIRED
 *
 * Priority 58 places this after ConversationContext (55) but before DetectedEntities (60).
 */
class EntityActionAwarenessSection extends BasePromptSection
{
    protected string $name = 'entity_action_awareness';
    protected int $priority = 58;

    /**
     * Include when there are entity actions or generic actions configured
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        $entityActions = config('ai.entity_actions', []);
        $genericActions = config('ai.generic_actions', []);

        return !empty($entityActions) || !empty($genericActions);
    }

    public function format(string $question, array $context, array $options = []): string
    {
        $output = $this->header('ACTION REQUESTS - IMPORTANT');

        $output .= "The following terms are ACTION REQUESTS, NOT database fields:\n\n";

        // Collect all action aliases
        $allAliases = [];
        $entityActions = config('ai.entity_actions', []);

        foreach ($entityActions as $entityType => $actions) {
            foreach ($actions as $actionKey => $actionConfig) {
                $aliases = $actionConfig['aliases'] ?? [];
                foreach ($aliases as $alias) {
                    $allAliases[$alias] = [
                        'entity' => $entityType,
                        'action' => $actionKey,
                        'label' => $actionConfig['label'] ?? $actionKey,
                    ];
                }
            }
        }

        // Generic actions
        $genericActions = config('ai.generic_actions', []);
        foreach ($genericActions as $actionKey => $actionConfig) {
            $aliases = $actionConfig['aliases'] ?? [];
            foreach ($aliases as $alias) {
                $allAliases[$alias] = [
                    'entity' => null,
                    'action' => $actionKey,
                    'label' => $actionConfig['label'] ?? $actionKey,
                ];
            }
        }

        if (!empty($allAliases)) {
            $output .= "**Action Aliases (these are NOT fields to query):**\n";
            foreach ($allAliases as $alias => $info) {
                $entityPart = $info['entity'] ? " ({$info['entity']})" : ' (generic)';
                $output .= "- \"{$alias}\" → {$info['label']}{$entityPart}\n";
            }
            $output .= "\n";
        }

        // Check if conversation context has entity IDs
        $conversationContext = $context['conversation_context'] ?? [];
        $lastResults = $conversationContext['last_result_sample'] ?? [];
        $focusedEntity = $conversationContext['focused_entity'] ?? null;

        if (!empty($lastResults)) {
            $output .= "**CRITICAL RULE:**\n";
            $output .= "If the user asks for any of the above action terms (like 'profile link', 'profile page'):\n";
            $output .= "1. These are UI actions, NOT database queries\n";
            $output .= "2. The entity IDs are ALREADY AVAILABLE in context below\n";
            $output .= "3. You MUST return: `NO QUERY REQUIRED`\n\n";

            $output .= "**Available Entity IDs from Previous Results:**\n";
            foreach ($lastResults as $index => $result) {
                $id = $this->extractEntityId($result);
                if ($id === null) {
                    continue; // Skip entries without valid ID
                }
                $name = $result['name'] ?? $result['title'] ?? $result['full_name'] ?? "Item " . ($index + 1);
                $entityType = $focusedEntity ?? 'Entity';
                $output .= "- {$entityType}: {$name} (ID: {$id})\n";
            }
            $output .= "\n";
        }

        $output .= "**Example:**\n";
        $output .= "- User: \"give me the profile link for John\"\n";
        $output .= "- If John's ID is in context above → Return: `NO QUERY REQUIRED`\n";
        $output .= "- The response generator will format the action link using the ID\n\n";

        return $output;
    }

    /**
     * Extract entity ID from result using configured field names
     */
    protected function extractEntityId(array $result): ?string
    {
        $idFields = config('ai.entity_id_fields') ?? ['id', '_id', 'neo4j_id'];

        foreach ($idFields as $field) {
            if (isset($result[$field]) && $result[$field] !== null) {
                return (string) $result[$field];
            }
        }

        return null;
    }
}
