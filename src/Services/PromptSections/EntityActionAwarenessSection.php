<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\AiActionRegistry;

/**
 * EntityActionAwarenessSection
 *
 * Informs the QueryGenerator about available actions so it knows:
 * - "profile link", "profile page", "export to excel" etc. are actions, NOT database fields
 * - When user asks for actions AND context has entity IDs, return NO QUERY REQUIRED
 *
 * Single source of truth: actions come from the {@see AiActionRegistry}, the same registry the
 * render/response side reads, so the query generator and responder agree on what actions exist.
 * The registry merges built-ins (export_excel, render_table, link, open_modal, export_pdf),
 * config closures (entity_actions/generic_actions), and explicitly-registered class actions.
 *
 * Priority 58 places this after ConversationContext (55) but before DetectedEntities (60).
 */
class EntityActionAwarenessSection extends BasePromptSection
{
    protected string $name = 'entity_action_awareness';
    protected int $priority = 58;

    /**
     * Include when the registry knows of any action (built-in, config, or registered).
     */
    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        $actions = $this->actionRegistry()->relevantActions(
            $context->conversationContext['focused_entity'] ?? null
        );

        return !empty($actions['generic']) || !empty($actions['entity']);
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $output = $this->header('ACTION REQUESTS - IMPORTANT');

        $output .= "The following are ACTION REQUESTS, NOT database fields:\n\n";

        $focusedEntity = $context->conversationContext['focused_entity'] ?? null;
        $actions = $this->actionRegistry()->relevantActions($focusedEntity);

        foreach ($actions['entity'] as $entityType => $entityActions) {
            $output .= "**{$entityType} Actions:**\n";
            foreach ($entityActions as $action) {
                $output .= $this->describeAction($action);
            }
            $output .= "\n";
        }

        if (!empty($actions['generic'])) {
            $output .= "**Generic Actions:**\n";
            foreach ($actions['generic'] as $action) {
                $output .= $this->describeAction($action);
            }
            $output .= "\n";
        }

        $lastResults = $context->conversationContext['last_result_sample'] ?? [];

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
                    continue;
                }
                $name = $result['name'] ?? $result['title'] ?? $result['full_name'] ?? "Item " . ($index + 1);
                $entityType = $focusedEntity ?? 'Entity';
                $output .= "- {$entityType}: {$name} (ID: {$id})\n";
            }
            $output .= "\n";

            // The Example illustrates acting on an ID from the previous results, so it only
            // has a referent when those results exist.
            $output .= "**Example:**\n";
            $output .= "- User: \"give me the profile link for John\"\n";
            $output .= "- If John's ID is in context above → Return: `NO QUERY REQUIRED`\n";
            $output .= "- The response generator will format the action link using the ID\n\n";
        }

        return $output;
    }

    /**
     * One token-lean line per action: key + description, the aliases the user might say, and a
     * note when the action takes params. Key + description are the model-agnostic tool metadata
     * the future native tool-spec also reads from the same registry.
     */
    protected function describeAction(AiActionInterface $action): string
    {
        $line = "- `{$action->key()}` — {$action->description()}";

        $aliases = $action->aliases();
        if (!empty($aliases)) {
            $line .= ' (user may say: ' . implode(', ', $aliases) . ')';
        }

        if (!empty($this->userFacingParams($action))) {
            $line .= ' [accepts params]';
        }

        return $line . "\n";
    }

    /**
     * The params the USER may supply, excluding the entity-id carrier the dispatcher seeds from
     * the link path (a {@see \Condoedge\Ai\Services\Actions\ModelMethodMutationAction} declares it
     * in its schema only so the id rides the approval token — it is not a user-facing param, so it
     * must not make the action read as "[accepts params]").
     *
     * @return array<string, mixed>
     */
    protected function userFacingParams(AiActionInterface $action): array
    {
        if (method_exists($action, 'declaredParams')) {
            return $action->declaredParams();
        }

        $schema = $action->inputSchema();
        unset($schema[\Condoedge\Ai\Services\Actions\ModelMethodMutationAction::ID_PARAM]);

        return $schema;
    }

    /**
     * Resolve the shared registry (built-ins + config + registered actions). Lazily resolved
     * through the container, degrading to a standalone instance with SAFE DEFAULTS when DI isn't
     * wired (e.g. pure unit tests) — mirrors EntityAutoDiscovery::actionRegistry().
     */
    protected function actionRegistry(): AiActionRegistry
    {
        if (function_exists('app')) {
            try {
                return app(AiActionRegistry::class);
            } catch (\Throwable $e) {
                // Fall through to a standalone instance.
            }
        }

        return new AiActionRegistry();
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
