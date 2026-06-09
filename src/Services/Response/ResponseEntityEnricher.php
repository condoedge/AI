<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

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

            $entityLabel = $result['_label'] ?? $result['type'] ?? null;

            if (!$entityLabel) {
                $enriched[] = $result;
                continue;
            }

            $actions = $this->discovery->getEntityActions($entityLabel);

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
     * Build ready-to-render markdown action links for one row.
     *
     * @param array $row
     * @param array $actions  From getEntityActions(): [['action_key','label','aliases'], ...]
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
