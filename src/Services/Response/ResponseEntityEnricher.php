<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

/**
 * Response Entity Enricher
 *
 * Enriches query results with entity action metadata by checking
 * if each entity type has a configured profile action.
 */
class ResponseEntityEnricher
{
    public function __construct(
        private EntityAutoDiscovery $discovery
    ) {}

    /**
     * Enrich entity results with action metadata
     *
     * Adds available actions info to each result that has an entity label.
     *
     * @param array $results The query results to enrich
     * @param array $options Additional options (reserved for future use)
     * @return array The enriched results
     */
    public function enrichEntityResults(array $results, array $options = []): array
    {
        $enriched = [];

        foreach ($results as $result) {
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
            ]);
        }

        return $enriched;
    }
}
