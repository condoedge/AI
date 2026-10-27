<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;

/**
 * Response Entity Enricher
 *
 * Enriches query result rows with entity action metadata AND ready-to-render per-row action
 * links that drive the UI.
 *
 * For each row carrying an entity label, it:
 *   - flags whether the entity type has actions,
 *   - lists the available actions (key/label/aliases) for the prompt layer,
 *   - builds per-row `action_links`: markdown `entity://Type/ID/key` links the existing
 *     ContentLinkProcessor turns into clickable chips. The internal ID lives ONLY inside the
 *     link target (never surfaced in prose), honoring the privacy policy.
 */
class ResponseEntityEnricher
{
    /** Keys this enricher merges into rows — not query columns (column comparisons skip them). */
    public const ENRICHMENT_KEYS = ['has_actions', 'available_actions', 'entity_type', 'action_links'];

    /**
     * Every non-data key a result row may carry: the enrichment keys plus the
     * `_label` entity marker. Stripped from exports and ignored by column
     * comparisons (AiManager::resultColumns, ResultSetExporter::stripDecorations).
     *
     * KNOWN LIMIT: a genuine query column aliased `entity_type` (or `has_actions`,
     * …) is indistinguishable from the decoration and is dropped too. Renaming the
     * enrichment key to something distinctive (e.g. `_entity_type`) was checked
     * and deferred: the key is persisted in conversation snapshots (last_result_sample) that existing
     * conversations replay, so a rename needs a read-both migration.
     */
    public const DECORATION_KEYS = [...self::ENRICHMENT_KEYS, '_label'];

    public function __construct(
        private EntityAutoDiscovery $discovery,
        private ?AiActionRegistry $registry = null,
    ) {
    }

    /**
     * Enrich entity results with action metadata + per-row action-link chips.
     *
     * @param array $results The query results to enrich
     * @param array $options Additional options (reserved)
     * @return array The enriched results
     */
    public function enrichEntityResults(array $results, array $options = []): array
    {
        $enriched = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                $enriched[] = $result;
                continue;
            }

            $entityLabel = $this->resolveEntityLabel($result);

            if ($entityLabel === null) {
                $enriched[] = $result;
                continue;
            }

            // 90c W6: per-row chips only for actions THIS user survives authorize() on.
            // A1/A2: mutating actions additionally pass the FULL row gate (capability + row
            // authorization + precondition) for THIS row — a rejected reservation is never
            // offered an "approve" chip, a customer never gets one at all.
            $user = $this->currentUser();
            $actions = $this->rowAuthorizedActions(
                $entityLabel,
                $result,
                $this->discovery->getAuthorizedEntityActions($entityLabel, $user),
                $user
            );

            $enriched[] = array_merge($result, [
                'has_actions' => !empty($actions),
                'available_actions' => $actions,
                'entity_type' => $entityLabel,
                // Per-row chips that drive the UI.
                'action_links' => $this->buildActionLinks($entityLabel, $result, $actions),
            ]);
        }

        return $enriched;
    }

    /**
     * The row's entity label: `_label` is authoritative. A `type` column is only a
     * label when it is a string naming a registered entity — a query may alias ANY
     * domain value as `type` (live: `t.team_type AS type` = 2), and treating that as
     * an entity label crashed the turn (TypeError) or advertised actions for a
     * non-entity.
     */
    private function resolveEntityLabel(array $row): ?string
    {
        $label = $row['_label'] ?? null;
        if (is_string($label) && $label !== '') {
            return $label;
        }

        $type = $row['type'] ?? null;
        if (is_string($type) && $type !== '' && $this->isRegisteredEntityLabel($type)) {
            return $type;
        }

        return null;
    }

    /**
     * True when the label is a catalog entity: its configured graph label, or the
     * basename of its config key (the shared EntityLabelIndex lookup).
     */
    private function isRegisteredEntityLabel(string $label): bool
    {
        return EntityLabelIndex::shared()->isRegistered($label);
    }

    /**
     * Build ready-to-render markdown action links for one row.
     *
     * @param array $row
     * @param array $actions  From getAuthorizedEntityActions() + the per-row gate: [['action_key','label','aliases'], ...]
     * @return array<int, string>  e.g. ['[View John](entity://Person/152463/profile)']
     */
    private function buildActionLinks(string $entityLabel, array $row, array $actions): array
    {
        $id = $this->extractEntityId($row);
        if ($id === null || empty($actions)) {
            return [];
        }

        $name = $row['name'] ?? $row['title'] ?? $row['full_name'] ?? $entityLabel;

        $links = [];
        foreach ($actions as $action) {
            $key = $action['action_key'] ?? null;
            if (!$key) {
                continue;
            }
            $label = $action['label'] ?? $key;
            // ID stays inside the link target only; the visible text uses the entity name.
            $links[] = "[{$label}: {$name}](entity://{$entityLabel}/{$id}/{$key})";
        }

        return $links;
    }

    /**
     * Keep read-only actions as advertised; keep a MUTATING action only when this row passes its
     * full authorize() (the same gate dispatch + approval run). A row without an id cannot be
     * verified, so its mutations are dropped (fail closed). A gate that throws drops the action.
     *
     * @param array<int, array<string, mixed>> $actions
     * @return array<int, array<string, mixed>>
     */
    private function rowAuthorizedActions(string $entityLabel, array $row, array $actions, mixed $user): array
    {
        $registry = $this->actionRegistry();
        if ($registry === null || $actions === []) {
            return $actions;
        }

        $id = $this->extractEntityId($row);
        $kept = [];
        foreach ($actions as $entry) {
            $key = is_array($entry) ? ($entry['action_key'] ?? null) : null;
            $action = is_string($key) ? $registry->getEntity($entityLabel, $key) : null;

            if ($action !== null && $action->mutates()) {
                if ($id === null) {
                    continue;
                }
                try {
                    if (!$action->authorize($user, [ModelMethodMutationAction::ID_PARAM => $id])) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            $kept[] = $entry;
        }

        return $kept;
    }

    private function actionRegistry(): ?AiActionRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        try {
            return function_exists('app') ? app(AiActionRegistry::class) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The principal chips are built for — same resolution the dispatcher's
     * render-time gate falls back to (auth user; null when no auth context).
     */
    private function currentUser(): mixed
    {
        try {
            return function_exists('auth') ? auth()->user() : null;
        } catch (\Throwable $e) {
            return null; // no auth context (console/queue) — gated actions fail closed
        }
    }

    /**
     * Extract the row's entity ID using the configured id fields.
     */
    private function extractEntityId(array $row): ?string
    {
        $idFields = config('ai.entity_id_fields') ?? ['id', '_id', 'neo4j_id'];

        foreach ($idFields as $field) {
            if (isset($row[$field]) && $row[$field] !== null) {
                return (string) $row[$field];
            }
        }

        return null;
    }
}
