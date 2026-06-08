<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
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
 *
 * Style-aware: this section is pure action-link (entity://) INSTRUCTIONS, which
 * contradict the 'minimal'/'concise' styles ("ONLY the direct answer, no extra
 * words"). It is suppressed entirely for those styles.
 */
class ResponseEntityActionsSection extends BaseResponseSection
{
    protected string $name = 'entity_actions';
    protected int $priority = 55;

    /**
     * Styles that want just the answer; action-link instructions don't apply.
     */
    private const TERSE_STYLES = ['minimal', 'concise'];

    private ?EntityAutoDiscovery $discovery = null;

    public function __construct()
    {
        parent::__construct();
        $this->discovery = app(EntityAutoDiscovery::class);
    }

    /**
     * Include when there are entity types with actions OR generic actions,
     * but never for terse styles where action links would be unwanted noise.
     */
    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        // Terse styles ask for just the answer; action-link instructions contradict that.
        if ($this->isTerseStyle($options)) {
            return false;
        }

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

    public function format(PipelineContext $context, array $options = []): string
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

            // Show available entity IDs with pre-formatted links
            if (!empty($entityData) && !empty($actions[0]['action_key'])) {
                $firstActionKey = $actions[0]['action_key'];
                $output .= "\n**Available {$entityType} IDs:**\n";
                foreach ($entityData as $entity) {
                    $id = $this->extractEntityId($entity);
                    $name = $entity['name'] ?? $entity['title'] ?? $entity['full_name'] ?? 'Unknown';
                    if ($id) {
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

        $output .= "**CRITICAL — IDs ONLY INSIDE ACTION LINKS, NEVER IN PROSE:**\n";
        $output .= "When the user asks for a link, profile, or action listed above:\n";
        $output .= "- Use the EXACT entity IDs shown above, but ONLY inside the `entity://` / `action://` link target.\n";
        $output .= "- NEVER write a raw ID in your prose/sentence text. The ID is an internal identifier and the privacy policy forbids revealing it in plain text; it is allowed only as part of the link URL, which the UI consumes and the user never sees.\n";
        $output .= "- Format: `[Display Text](entity://EntityType/ID/action_key)` — refer to the entity by NAME in the visible text, ID stays in the link.\n";
        $output .= "- Example: `[View John Doe](entity://Person/152463/profile)` (the user sees \"View John Doe\", not \"152463\").\n";
        $output .= "- You may add params after the action key as a query string for default values: `[Open form](action://open_modal?modal=editUnit&amount=450)`.\n\n";

        return $output;
    }

    /**
     * Whether the requested style wants just the answer (no action links).
     */
    private function isTerseStyle(array $options): bool
    {
        $style = $options['style'] ?? config('ai.response_generation.default_style', 'friendly');

        return in_array($style, self::TERSE_STYLES, true);
    }

    /**
     * Resolve entity types from multiple sources (priority order)
     */
    private function resolveEntityTypes(PipelineContext $context): array
    {
        $types = [];

        // 1. Conversation context focused_entity (most reliable)
        $conversationContext = $context->conversationContext;
        if (!empty($conversationContext['focused_entity'])) {
            $types[] = $conversationContext['focused_entity'];
        }

        // 2. Mentioned entities from conversation
        if (!empty($conversationContext['mentioned_entities'])) {
            $types = array_merge($types, $conversationContext['mentioned_entities']);
        }

        // 3. Parse from Cypher query (fallback)
        $cypher = $context->queryResult->cypher ?? '';
        if ($cypher) {
            $cypherLabels = $this->extractLabelsFromCypher($cypher);
            $types = array_merge($types, $cypherLabels);
        }

        return array_unique($types);
    }

    /**
     * Resolve entity data (IDs and names) from multiple sources
     */
    private function resolveEntityData(PipelineContext $context): array
    {
        // 1. Current response data
        $currentData = $context->executionResult->data;

        // 2. Conversation context last_result_sample (for NO QUERY cases)
        $conversationContext = $context->conversationContext;
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
