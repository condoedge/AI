<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * Token accounting and retrieval-confidence math for context payloads.
 *
 * Pure, side-effect-free: estimates token usage, breaks it down by section,
 * and computes a top-k confidence signal. Used by the optional budget/stats/
 * confidence helpers on ContextRetriever.
 */
class TokenBudgeter
{
    public function __construct(
        private readonly array $entityConfigs = [],
    ) {
    }

    /**
     * Analyze a context and return statistics about what was included and
     * approximate token usage.
     *
     * @param PipelineContext|array $context Context from retrieveContext or legacy array
     */
    public function stats(PipelineContext|array $context): array
    {
        $stats = [
            'token_estimate' => 0,
            'breakdown' => [],
            'items_included' => [],
            'items_excluded' => [],
            'compression_ratio' => 0,
        ];

        // Support both PipelineContext and legacy array access
        $similarQueries = $context instanceof PipelineContext ? $context->similarQueries : ($context['similar_queries'] ?? []);
        $graphSchema = $context instanceof PipelineContext ? $context->graphSchema : ($context['graph_schema'] ?? []);
        $entityMetadata = $context instanceof PipelineContext ? $context->entityMetadata : ($context['entity_metadata'] ?? []);
        $relevantEntities = $context instanceof PipelineContext ? $context->relevantEntities : ($context['relevant_entities'] ?? []);
        $selectionInfo = $context instanceof PipelineContext ? $context->selectionInfo : ($context['selection_info'] ?? []);

        $similarQueriesTokens = 0;
        foreach ($similarQueries as $query) {
            $similarQueriesTokens += $this->estimateTokens($query['question'] ?? '');
            $similarQueriesTokens += $this->estimateTokens($query['query'] ?? '');
        }
        $stats['breakdown']['similar_queries'] = $similarQueriesTokens;
        $stats['items_included']['similar_queries'] = count($similarQueries);

        $schemaTokens = 0;
        $schemaTokens += $this->estimateTokens(implode(', ', $graphSchema['labels'] ?? []));
        $schemaTokens += $this->estimateTokens(implode(', ', $graphSchema['relationships'] ?? []));
        $schemaTokens += $this->estimateTokens(implode(', ', $graphSchema['propertyKeys'] ?? []));
        $stats['breakdown']['graph_schema'] = $schemaTokens;
        $stats['items_included']['schema_labels'] = count($graphSchema['labels'] ?? []);
        $stats['items_included']['schema_relationships'] = count($graphSchema['relationships'] ?? []);
        $stats['items_included']['schema_properties'] = count($graphSchema['propertyKeys'] ?? []);

        $metadataTokens = 0;
        foreach ($entityMetadata['entity_metadata'] ?? [] as $entityMeta) {
            $metadataTokens += $this->estimateTokens(json_encode($entityMeta));
        }
        $stats['breakdown']['entity_metadata'] = $metadataTokens;
        $stats['items_included']['detected_entities'] = count($entityMetadata['detected_entities'] ?? []);
        $stats['items_included']['detected_scopes'] = count($entityMetadata['detected_scopes'] ?? []);

        $examplesTokens = 0;
        foreach ($relevantEntities as $label => $entities) {
            foreach ($entities as $entity) {
                $examplesTokens += $this->estimateTokens(json_encode($entity));
            }
        }
        $stats['breakdown']['example_entities'] = $examplesTokens;
        $stats['items_included']['example_entities'] = array_sum(array_map('count', $relevantEntities));

        $stats['token_estimate'] = array_sum($stats['breakdown']);

        if (!empty($selectionInfo) && ($selectionInfo['method'] ?? '') === 'semantic') {
            $totalEntities = count($this->entityConfigs);
            $selectedEntities = $selectionInfo['entities_selected'] ?? 1;
            $stats['compression_ratio'] = $totalEntities > 0
                ? round((1 - ($selectedEntities / $totalEntities)) * 100, 2)
                : 0;
        }

        return $stats;
    }

    /**
     * Enforce a token budget over an ordered list of prompt sections.
     *
     * Each entry is the assembled contribution of one prompt section:
     *   ['name' => string, 'role' => string, 'priority' => int,
     *    'content' => string, 'keep' => bool]
     *
     * When the summed token estimate of all entries exceeds $budget, the least
     * important DROPPABLE sections (keep === false) are removed first — least
     * important meaning the highest priority number (priorities run low->high =
     * most->least important). Sections flagged keep are never dropped, even if the
     * result still exceeds the budget: correctness (the question + task cue must
     * survive) wins over the budget. A non-positive budget disables enforcement and
     * returns the input unchanged, so the default (no budget) path is a no-op.
     *
     * The relative order of the surviving sections is preserved.
     *
     * @param array<int, array{name: string, role: string, priority: int, content: string, keep: bool}> $sections
     * @param int $budget Maximum total token estimate; <= 0 disables enforcement.
     * @return array<int, array{name: string, role: string, priority: int, content: string, keep: bool}>
     */
    public function enforce(array $sections, int $budget): array
    {
        if ($budget <= 0 || empty($sections)) {
            return $sections;
        }

        $total = 0;
        foreach ($sections as $section) {
            $total += $this->estimateTokens($section['content'] ?? '');
        }

        if ($total <= $budget) {
            return $sections;
        }

        // Candidates for dropping: droppable sections, least important first
        // (highest priority number first; ties broken by later position so the
        // last-assembled section is shed first).
        $droppable = [];
        foreach ($sections as $index => $section) {
            if (empty($section['keep'])) {
                $droppable[] = ['index' => $index, 'priority' => (int) ($section['priority'] ?? 0)];
            }
        }

        usort($droppable, function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority']
                ?: $b['index'] <=> $a['index'];
        });

        $dropped = [];
        foreach ($droppable as $candidate) {
            if ($total <= $budget) {
                break;
            }
            $dropped[$candidate['index']] = true;
            $total -= $this->estimateTokens($sections[$candidate['index']]['content'] ?? '');
        }

        $kept = [];
        foreach ($sections as $index => $section) {
            if (!isset($dropped[$index])) {
                $kept[] = $section;
            }
        }

        return $kept;
    }

    /**
     * Estimate token count for a text string.
     *
     * Rough approximation: ~4 characters per token for English text, with a
     * small uplift for JSON structure overhead.
     */
    public function estimateTokens(string $text): int
    {
        if (empty($text)) {
            return 0;
        }

        $baseTokens = (int) ceil(strlen($text) / 4);

        // If it looks like JSON, add overhead for structure
        if (str_starts_with(trim($text), '{') || str_starts_with(trim($text), '[')) {
            $baseTokens = (int) ceil($baseTokens * 1.2);
        }

        return $baseTokens;
    }

    /**
     * Compute a retrieval-confidence signal from a top-k score distribution.
     *
     * Blends strength (mean of the top-k) with margin (gap between the best and
     * the median), clamped to [0, 1]. An empty distribution yields 0.0.
     *
     * @param array<int|float> $scores Top-k relevance scores (any order).
     * @param int              $k      How many of the top scores to consider.
     * @return array{overall: float, strength: float, margin: float, top_score: float, considered: int}
     */
    public function confidence(array $scores, int $k = 5): array
    {
        $numeric = array_values(array_filter(
            array_map(fn ($s) => is_numeric($s) ? (float) $s : null, $scores),
            fn ($s) => $s !== null
        ));

        if (empty($numeric)) {
            return [
                'overall' => 0.0,
                'strength' => 0.0,
                'margin' => 0.0,
                'top_score' => 0.0,
                'considered' => 0,
            ];
        }

        rsort($numeric);
        $topK = array_slice($numeric, 0, max(1, $k));
        $count = count($topK);

        $top = $topK[0];
        $strength = array_sum($topK) / $count;

        // Median of the considered window.
        $mid = (int) floor(($count - 1) / 2);
        $median = ($count % 2 === 1)
            ? $topK[$mid]
            : (($topK[$mid] + $topK[$mid + 1]) / 2.0);

        // Separation of the best match from the bulk. Flat distributions (best ~=
        // median) signal ambiguity; a clear leader signals confidence.
        $margin = max(0.0, $top - $median);

        // Blend strength (weighted higher) with margin, then clamp.
        $overall = (0.7 * $strength) + (0.3 * $margin);
        $overall = max(0.0, min(1.0, $overall));

        return [
            'overall' => round($overall, 4),
            'strength' => round($strength, 4),
            'margin' => round($margin, 4),
            'top_score' => round($top, 4),
            'considered' => $count,
        ];
    }
}
