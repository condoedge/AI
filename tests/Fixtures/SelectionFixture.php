<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

/**
 * The CondoEdge entity-selection fixture (Phase 5C), frozen from the live host:
 * graph labels + relationship patterns (with counts), the configured entity edges,
 * each entity's security.team_path, the user_entity_paths entity lists, and — per
 * gold question — the Query-Understanding output captured live (entity types,
 * isPossessive, intent), the labels of the matched scopes and the direct vector
 * entity hits. See selection/condoedge-selection.json.
 */
final class SelectionFixture
{
    private static ?array $data = null;

    public static function data(): array
    {
        return self::$data ??= json_decode(
            (string) file_get_contents(__DIR__ . '/selection/condoedge-selection.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /** @return string[] */
    public static function labels(): array
    {
        return self::data()['labels'];
    }

    /** @return array<int, array{from_label: string, type: string, to_label: string, count: ?int}> */
    public static function patterns(): array
    {
        return array_map(
            fn (array $p) => ['from_label' => $p[0], 'type' => $p[1], 'to_label' => $p[2], 'count' => $p[3]],
            self::data()['patterns']
        );
    }

    /**
     * Entity configs synthesized from the frozen edges + team paths, keyed by a
     * FQCN like the host's (App\Models\<Label>).
     */
    public static function entityConfigs(): array
    {
        $data = self::data();
        $configs = [];
        $labels = array_unique(array_merge(
            array_keys($data['configured_edges']),
            array_keys($data['tenant_paths'])
        ));

        foreach ($labels as $label) {
            $config = [
                'graph' => [
                    'label' => $label,
                    'relationships' => $data['configured_edges'][$label] ?? [],
                ],
            ];
            if (array_key_exists($label, $data['tenant_paths'])) {
                $config['security'] = ['team_path' => $data['tenant_paths'][$label]];
            }
            $configs['App\\Models\\' . $label] = $config;
        }

        return $configs;
    }

    public static function userEntityPaths(): array
    {
        return self::data()['user_entity_paths'];
    }

    /**
     * @return array{question: string, entity_types: string[], is_possessive: bool, intent: string,
     *               question_type: string, scope_labels: string[], vector_hits: array<string, float>}
     */
    public static function question(string $id): array
    {
        return self::data()['questions'][$id];
    }
}
