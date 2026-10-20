<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportPdfAction;

/**
 * EntityActionAwarenessSection
 *
 * Tells the QueryGenerator which phrases are UI ACTIONS (app pages, exports), NOT
 * database fields — so "export to excel" or "open the calendar" is never queried as a
 * property — and that actions never replace a data question.
 *
 * Actions plan B5 (skeptic-checked 2026-10-01): links are attached by CODE from the rows
 * (ActionAttacher), so the generator no longer needs the alias catalog, the per-row ids
 * of previous results or the "answer needs_query=false and the response layer will write
 * the link" escape hatch — it only needs the action KEYS + labels, the export action QU
 * asked for described in full, the data-question guardrail, and one rule: a link to a
 * specific record is a data question about that record (query it, with its id).
 *
 * Single source of truth: actions come from the {@see AiActionRegistry}; mutations the
 * principal may not perform are never listed (ActionAdvertisement).
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
        $actions = $this->offeredActions(
            $context->conversationContext['focused_entity'] ?? null
        );

        return !empty($actions['generic']) || !empty($actions['entity']);
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $focusedEntity = $context->conversationContext['focused_entity'] ?? null;
        $labels = array_values(array_filter(
            (array) ($context->graphSchema['labels'] ?? []),
            fn ($l) => is_string($l) && $l !== ''
        ));
        $actions = $this->offeredActions($focusedEntity, $labels);

        $output = $this->header('ACTION REQUESTS - IMPORTANT');
        $output .= "These are UI ACTIONS, NOT database fields (the response layer attaches their links):
";

        foreach ($actions['entity'] as $entityType => $entityActions) {
            $output .= "- {$entityType}: " . implode(', ', array_map(fn (AiActionInterface $a) => $this->keyAndLabel($a), $entityActions)) . "
";
        }

        $described = [];
        $keys = [];
        foreach ($actions['generic'] as $action) {
            if ($this->matchesExportFormat($action, $context->qu->exportFormat)) {
                $described[] = $action;
            } else {
                $keys[] = $this->keyAndLabel($action);
            }
        }
        if ($keys !== []) {
            $output .= '- Generic: ' . implode(', ', $keys) . "
";
        }
        foreach ($described as $action) {
            $output .= $this->describeAction($action);
        }

        return $output . "
" . $this->formatGuardrails();
    }

    private function keyAndLabel(AiActionInterface $action): string
    {
        $label = trim($action->label());

        return '`' . $action->key() . '`' . ($label !== '' && $label !== $action->key() ? " ({$label})" : '');
    }

    private function matchesExportFormat(AiActionInterface $action, ?string $exportFormat): bool
    {
        return match ($exportFormat) {
            'excel' => $action instanceof ExportExcelAction,
            'pdf' => $action instanceof ExportPdfAction,
            default => false,
        };
    }

    /**
     * The data-question guardrail + the record-link rule.
     */
    private function formatGuardrails(): string
    {
        // GUARDRAIL (regression pin — ambiguous-balance t0): the action list above
        // must never legitimize skipping a DATA question. An action's name can
        // overlap the subject of a legitimate data request; the model once classified
        // such a question as an action request and answered "no query required",
        // collapsing the turn into "no results".
        $output = "**ACTIONS NEVER REPLACE DATA QUESTIONS:**
";
        $output .= "The list above only identifies which phrases are UI actions rather than database fields. ";
        $output .= "When the user asks for information that the graph can answer — a value, an amount, a balance, a list, details of something — ";
        $output .= "you MUST still generate the Cypher query for the underlying data, ";
        $output .= "even if the question's wording overlaps an action's name, and even when previous results are available in context — ";
        $output .= "the response layer attaches any relevant action links separately. ";
        $output .= "A request to open or get the link of a SPECIFIC record is a data question about that record: query it (returning its id) — its link is attached from the rows. ";
        $output .= "Answer with `\"needs_query\": false` only when the request is PURELY to open or navigate to one of these app pages or actions ";
        $output .= "with no informational component to answer.

";

        return $output;
    }

    /**
     * One line for an action described in full: key + description, and a note when it
     * takes params.
     */
    protected function describeAction(AiActionInterface $action): string
    {
        $line = "- `{$action->key()}` — {$action->description()}";

        if (!empty($this->userFacingParams($action))) {
            $line .= ' [accepts params]';
        }

        return $line . "
";
    }

    /**
     * The params the USER may supply, excluding the entity-id carrier the dispatcher seeds
     * (a {@see \Condoedge\Ai\Services\Actions\ModelMethodMutationAction} declares it only so
     * the id rides the approval token).
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
     * The registry's relevance-scoped actions, minus the MUTATING ones the current principal may not
     * be offered (security A1/A2) — a resident's query-side prompt never lists a staff-only write.
     *
     * @param string[] $resultTypes
     * @return array{entity: array<string, AiActionInterface[]>, generic: AiActionInterface[]}
     */
    protected function offeredActions(?string $focusedEntity, array $resultTypes = []): array
    {
        return \Condoedge\Ai\Services\Actions\ActionAdvertisement::filterMutations(
            $this->actionRegistry()->relevantActions($focusedEntity, $resultTypes),
            \Condoedge\Ai\Services\Actions\ActionAdvertisement::currentUser()
        );
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
}
