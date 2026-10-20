<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\DTOs\PrunedEntitySelection;
use Condoedge\Ai\Services\Security\TeamPathResolver;

/**
 * EntitySelectionPruner (Task 5.10)
 *
 * Decides WHICH graph labels reach the query-generation prompt (one schema card
 * each). The former selection unioned every vector hit with every configured
 * neighbour of every hit (a hub like Union or User fanned out to 15–30 labels),
 * so the schema block — and the prompt — grew with the graph, not the question.
 *
 * Pruning is structural, in tiers (a label keeps the FIRST tier it qualifies for,
 * and the global cap keeps tiers in this order):
 *
 *   1. seed      — what the question is about: Query-Understanding entity types,
 *                  the follow-up augmentation anchor, the page's entity, the
 *                  conversation focus (the caller assembles these).
 *   2. scope     — the labels of the matched business concepts (scopes).
 *   2b. calculation — the labels of the canonical calculation(s) the question asks
 *                  for (CalculationMatcher), so the metric's formula can run even
 *                  when Query Understanding named other entity types. They are
 *                  seeds for tenant hops / closure; a calculation label that is a
 *                  tenant hop of a seed keeps the tenant (compact-card) tier.
 *   (vector)     — ONLY when nothing seeded the selection (no seed, scope or
 *                  calculation label): the top-K direct vector hits (already
 *                  threshold-filtered by the selector) become the seeds.
 *   3. tenant    — the labels a seed's tenant-scoping rule traverses (e.g. Unit
 *                  is scoped through Union), so the model can emit the rule. Ahead
 *                  of identity; the hops of identity / closure / ownership labels
 *                  are added too, so EVERY selected label can be scoped.
 *   4. identity  — the identity labels (User/Team), ONLY when QU judged the
 *                  question possessive/self-referential — about the asker in
 *                  person OR about the asker's group (the team anchor).
 *   5. closure   — the intermediate labels on the SHORTEST join path between each
 *                  pair of seeds, over the live relationship patterns plus the
 *                  configured (scalar-FK) joins, at most `closureDepth` intermediate
 *                  labels. Identity labels are tenancy anchors, never join paths.
 *                  Among equally short paths the one adding the fewest new labels
 *                  (then the strongest edges) wins.
 *   6. ownership — PERSONAL possession only: the labels on a seed's configured
 *                  user_entity_path (how "my X" reaches X). A question about the
 *                  group's records ("our X") keeps the identity anchors and the
 *                  tenant rule; it never walks the asker's own path (eval p17:
 *                  one flag fed both, and a collective turn carried the tier).
 *
 * Cap: labels are kept in tier order, each TOGETHER with its tenant hops (reserved
 * with it — a label that does not fit with its hops is dropped; a hop never stands
 * alone).
 *
 * Pure: no store access. The live patterns and label list come from the (cached)
 * graph schema; null means "unknown" (no existence filter / configured joins only).
 */
final class EntitySelectionPruner
{
    public const TIER_SEED = 'seed';
    public const TIER_SCOPE = 'scope';
    public const TIER_CALCULATION = 'calculation';
    public const TIER_VECTOR = 'vector';
    public const TIER_IDENTITY = 'identity';
    public const TIER_TENANT = 'tenant';
    public const TIER_CLOSURE = 'closure';
    public const TIER_OWNERSHIP = 'ownership';

    public const DEFAULT_MAX_LABELS = 12;
    public const DEFAULT_CLOSURE_DEPTH = 2;
    public const DEFAULT_VECTOR_FALLBACK_TOP_K = 5;

    /** Bound on the equally-short paths enumerated per seed pair. */
    private const MAX_SHORTEST_PATHS = 64;

    private ?\Closure $tenantScopeLabels;

    /**
     * @param array<string, array> $entityConfigs entity configs (FQCN => config)
     * @param string[] $identityLabels
     * @param array<string, array> $userEntityPaths ai.user_entity_paths (label => ['entities' => [...]])
     * @param callable|null $tenantScopeLabels fn(string $label): string[] — defaults to TeamPathResolver::scopeLabels
     */
    public function __construct(
        private readonly array $entityConfigs = [],
        private readonly array $identityLabels = ['User', 'Team'],
        private readonly array $userEntityPaths = [],
        ?callable $tenantScopeLabels = null,
        private readonly int $maxLabels = self::DEFAULT_MAX_LABELS,
        private readonly int $closureDepth = self::DEFAULT_CLOSURE_DEPTH,
        private readonly int $vectorFallbackTopK = self::DEFAULT_VECTOR_FALLBACK_TOP_K,
    ) {
        $this->tenantScopeLabels = $tenantScopeLabels !== null ? \Closure::fromCallable($tenantScopeLabels) : null;
    }

    /**
     * Build from the ai.semantic_context / ai.user_entity_paths configuration.
     */
    public static function fromConfig(array $entityConfigs): self
    {
        $identity = config('ai.semantic_context.always_include_identity_labels', ['User', 'Team']);
        $paths = config('ai.user_entity_paths', []);

        return new self(
            entityConfigs: $entityConfigs,
            identityLabels: is_array($identity) ? array_values(array_filter($identity, 'is_string')) : [],
            userEntityPaths: is_array($paths) ? $paths : [],
            maxLabels: max(1, (int) config('ai.semantic_context.max_labels', self::DEFAULT_MAX_LABELS)),
            closureDepth: max(0, (int) config('ai.semantic_context.closure_depth', self::DEFAULT_CLOSURE_DEPTH)),
            vectorFallbackTopK: max(0, (int) config('ai.semantic_context.vector_fallback_top_k', self::DEFAULT_VECTOR_FALLBACK_TOP_K)),
        );
    }

    /**
     * @param array<string, string> $seeds ordered label => reason (QU / anchor / page / focus)
     * @param string[] $scopeLabels labels of the matched scopes
     * @param array<string, float> $vectorHits label => score (direct vector hits, threshold-filtered)
     * @param string[]|null $schemaLabels live graph labels (null = unknown)
     * @param array<int, array>|null $patterns live relationship patterns (null = unknown)
     * @param string[] $calculationLabels labels of the calculation(s) the question asks for
     * @param bool $isPossessive the question is possessive, personal OR collective: keeps the identity anchors
     * @param bool|null $ownershipPaths the possession is the asker's OWN: also keeps the ownership-path
     *        labels. Only ever together with $isPossessive (a path starts at an identity anchor);
     *        null = whenever the question is possessive.
     */
    public function prune(
        array $seeds,
        array $scopeLabels,
        array $vectorHits,
        bool $isPossessive,
        ?array $schemaLabels,
        ?array $patterns,
        array $calculationLabels = [],
        ?bool $ownershipPaths = null,
    ): PrunedEntitySelection {
        $ownershipPaths = $isPossessive && ($ownershipPaths ?? true);


        $canonical = $this->canonicalLabels($schemaLabels);
        $known = $schemaLabels !== null;
        $normalize = function (mixed $label) use ($canonical, $known): ?string {
            if (!is_string($label) || trim($label) === '') {
                return null;
            }
            $label = class_basename(trim($label));

            return $canonical[strtolower($label)] ?? ($known ? null : $label);
        };

        $tiers = [];
        $reasons = [];
        $add = function (?string $label, string $tier, string $reason) use (&$tiers, &$reasons): void {
            if ($label !== null && !isset($tiers[$label])) {
                $tiers[$label] = $tier;
                $reasons[$label] = $reason;
            }
        };

        // 1–2. Seeds, then the matched scopes' labels.
        foreach ($seeds as $label => $reason) {
            $add($normalize($label), self::TIER_SEED, is_string($reason) && $reason !== '' ? $reason : 'seed');
        }
        foreach ($scopeLabels as $label) {
            $label = $normalize($label);
            $add($label, self::TIER_SCOPE, "Matched business concept on {$label}");
        }
        // 2b. Calculation labels, right after the question's own labels — except the
        // ones that are a tenant hop of those (they get the tenant tier below).
        $seedHops = [];
        foreach (array_keys($tiers) as $terminal) {
            foreach ($this->tenantScopeLabelsFor($terminal) as $hop) {
                $hop = $normalize($hop);
                if ($hop !== null) {
                    $seedHops[$hop] = true;
                }
            }
        }
        $calculation = [];
        foreach ($calculationLabels as $label) {
            $label = $normalize($label);
            if ($label !== null && !isset($tiers[$label])) {
                $calculation[$label] = true;
                if (!isset($seedHops[$label])) {
                    $add($label, self::TIER_CALCULATION, "Canonical calculation over {$label}");
                }
            }
        }
        $calculation = array_keys($calculation);

        // Vector hits: a fallback for questions with no seed at all.
        if ($tiers === [] && $calculation === []) {
            $hits = [];
            foreach ($vectorHits as $label => $score) {
                $label = $normalize($label);
                if ($label !== null && is_numeric($score)) {
                    $hits[$label] = max((float) $score, $hits[$label] ?? -INF);
                }
            }
            arsort($hits);
            foreach (array_slice($hits, 0, $this->vectorFallbackTopK, true) as $label => $score) {
                $add($label, self::TIER_VECTOR, sprintf('Vector match (score %.3f)', $score));
            }
        }

        $terminals = array_values(array_unique(array_merge(array_keys($tiers), $calculation)));
        $identity = [];

        // Tenant hops per label (label => hop labels), for every label that is selected.
        $hopsOf = [];
        $addHops = function (string $owner) use (&$hopsOf, $normalize, $add): void {
            if (isset($hopsOf[$owner])) {
                return;
            }
            $hopsOf[$owner] = [];
            foreach ($this->tenantScopeLabelsFor($owner) as $hop) {
                $hop = $normalize($hop);
                if ($hop !== null && $hop !== $owner) {
                    $hopsOf[$owner][] = $hop;
                    $add($hop, self::TIER_TENANT, "Tenant scoping of {$owner} goes through {$hop}");
                }
            }
        };

        // 3. Labels the tenant-scoping rule of each seed traverses — ahead of the
        //    identity labels, so the cap never cuts the hop a seed is scoped through.
        foreach ($terminals as $terminal) {
            $addHops($terminal);
        }

        // 4. Identity anchors — only for possessive / self-referential questions.
        if ($isPossessive) {
            foreach ($this->identityLabels as $label) {
                $label = $normalize($label);
                if ($label !== null) {
                    $add($label, self::TIER_IDENTITY, 'Identity anchor (possessive question)');
                    $identity[] = $label;
                }
            }
        }

        // Calculation labels that were not tenant hops after all (defensive).
        foreach ($calculation as $label) {
            $add($label, self::TIER_CALCULATION, "Canonical calculation over {$label}");
        }

        // 5. Shortest-path closure between every pair of seeds.
        $adjacency = $this->adjacency($patterns, $normalize);
        $count = count($terminals);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $path = $this->bestShortestPath($terminals[$i], $terminals[$j], $adjacency, $tiers);
                foreach ($path ?? [] as $label) {
                    $add($label, self::TIER_CLOSURE, "Joins {$terminals[$i]} and {$terminals[$j]} (shortest path)");
                }
            }
        }

        // 6. Ownership paths ("my X") of the primary seeds — personal possession only.
        if ($ownershipPaths) {
            foreach ($terminals as $terminal) {
                if (!in_array($tiers[$terminal], [self::TIER_SEED, self::TIER_VECTOR], true)) {
                    continue;
                }
                foreach ((array) ($this->userEntityPaths[$terminal]['entities'] ?? []) as $label) {
                    $label = $normalize($label);
                    $add($label, self::TIER_OWNERSHIP, "Ownership path to {$terminal}");
                }
            }
        }

        // Every selected label gets its scoping rule rendered: the tenant hops of the
        // identity / closure / ownership labels too (and of their hops, transitively).
        for ($i = 0; $i < count($tiers); $i++) {
            $addHops(array_keys($tiers)[$i]);
        }

        // Global cap, in tier order. A label is kept only TOGETHER with its tenant hops
        // (they are reserved with it; a label that does not fit with them is dropped),
        // and a hop is never kept on its own.
        $keptSet = [];
        $dropped = [];
        foreach (array_keys($tiers) as $label) {
            if (isset($keptSet[$label]) || ($tiers[$label] === self::TIER_TENANT && !$this->isSelectedHop($label, $hopsOf, $keptSet))) {
                continue;
            }
            $need = [$label => true];
            foreach ($this->hopClosure($label, $hopsOf) as $hop) {
                $need[$hop] = true;
            }
            $need = array_diff_key($need, $keptSet);
            if (count($keptSet) + count($need) > $this->maxLabels) {
                $dropped[] = $label;
                continue;
            }
            $keptSet += $need;
        }
        $kept = array_values(array_filter(array_keys($tiers), fn ($l) => isset($keptSet[$l])));
        $dropped = array_values(array_unique(array_merge(
            $dropped,
            array_values(array_filter(array_keys($tiers), fn ($l) => !isset($keptSet[$l])))
        )));

        return new PrunedEntitySelection(
            labels: $kept,
            tiers: array_intersect_key($tiers, $keptSet),
            reasons: array_intersect_key($reasons, $keptSet),
            identityLabels: array_values(array_filter(array_unique($identity), fn ($l) => isset($keptSet[$l]))),
            dropped: $dropped,
        );
    }

    /**
     * Whether $hop is a tenant hop of an already-kept label (then it is kept already).
     *
     * @param array<string, string[]> $hopsOf
     * @param array<string, true> $keptSet
     */
    private function isSelectedHop(string $hop, array $hopsOf, array $keptSet): bool
    {
        foreach ($hopsOf as $owner => $hops) {
            if (isset($keptSet[$owner]) && in_array($hop, $hops, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tenant hops of $label, and theirs, transitively.
     *
     * @param array<string, string[]> $hopsOf
     * @return string[]
     */
    private function hopClosure(string $label, array $hopsOf): array
    {
        $seen = [];
        $stack = $hopsOf[$label] ?? [];
        while ($stack !== []) {
            $hop = array_pop($stack);
            if ($hop === $label || isset($seen[$hop])) {
                continue;
            }
            $seen[$hop] = true;
            foreach ($hopsOf[$hop] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return array_keys($seen);
    }

    /**
     * lower-case label => canonical label, from the live graph (when known) plus
     * the entity configs.
     *
     * @return array<string, string>
     */
    private function canonicalLabels(?array $schemaLabels): array
    {
        $map = [];
        if ($schemaLabels !== null) {
            foreach ($schemaLabels as $label) {
                if (is_string($label) && $label !== '') {
                    $map[strtolower($label)] = $label;
                }
            }

            return $map;
        }

        foreach ($this->entityConfigs as $fqcn => $config) {
            $label = $this->labelOf((string) $fqcn, is_array($config) ? $config : []);
            $map[strtolower($label)] = $label;
        }

        return $map;
    }

    private function labelOf(string $fqcn, array $config): string
    {
        $label = $config['graph']['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : class_basename($fqcn);
    }

    /** @return string[] */
    private function tenantScopeLabelsFor(string $label): array
    {
        if ($this->tenantScopeLabels !== null) {
            return (array) ($this->tenantScopeLabels)($label);
        }

        if (function_exists('config') && !config('ai.security.enabled', true)) {
            return [];
        }

        try {
            return (new TeamPathResolver())->scopeLabels($label);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Undirected join graph: live patterns (weight = edge count) plus configured
     * relationships, which include scalar-FK joins the graph has no edge for
     * (weight 0 — a join the model can still write with WHERE x.fk = y.id).
     *
     * @return array<string, array<string, int>>
     */
    private function adjacency(?array $patterns, \Closure $normalize): array
    {
        $adjacency = [];
        $link = function (?string $a, ?string $b, int $weight) use (&$adjacency): void {
            if ($a === null || $b === null || $a === $b) {
                return;
            }
            $adjacency[$a][$b] = max($weight, $adjacency[$a][$b] ?? -1);
            $adjacency[$b][$a] = max($weight, $adjacency[$b][$a] ?? -1);
        };

        foreach ($patterns ?? [] as $pattern) {
            if (!is_array($pattern)) {
                continue;
            }
            $from = $pattern['from_label'] ?? $pattern['fromLabel'] ?? $pattern['from'] ?? null;
            $to = $pattern['to_label'] ?? $pattern['toLabel'] ?? $pattern['to'] ?? null;
            $link($normalize($from), $normalize($to), max(1, (int) ($pattern['count'] ?? 1)));
        }

        foreach ($this->entityConfigs as $fqcn => $config) {
            if (!is_array($config)) {
                continue;
            }
            $label = $normalize($this->labelOf((string) $fqcn, $config));
            foreach ((array) ($config['graph']['relationships'] ?? []) as $relationship) {
                if (is_array($relationship) && !empty($relationship['pivot'])) {
                    continue;
                }
                $link($label, $normalize($relationship['target_label'] ?? null), 0);
            }
        }

        return $adjacency;
    }

    /**
     * The intermediate labels of the best shortest path from $from to $to (at most
     * closureDepth intermediates), or null when none exists. Identity labels may
     * only be crossed when already selected; among equally short paths the one
     * adding the fewest NEW labels wins, then the strongest weakest edge, then the
     * lexically first (deterministic).
     *
     * @param array<string, array<string, int>> $adjacency
     * @param array<string, string> $selected
     * @return string[]|null
     */
    private function bestShortestPath(string $from, string $to, array $adjacency, array $selected): ?array
    {
        if (isset($adjacency[$from][$to])) {
            return [];
        }

        $maxHops = $this->closureDepth + 1;
        if ($maxHops < 2 || !isset($adjacency[$from], $adjacency[$to])) {
            return null;
        }

        $identity = array_fill_keys($this->identityLabels, true);
        $crossable = fn (string $label): bool => !isset($identity[$label]) || isset($selected[$label]);

        // BFS layers with every shortest predecessor.
        $distance = [$from => 0];
        $predecessors = [];
        $frontier = [$from];
        for ($depth = 1; $depth <= $maxHops && $frontier !== [] && !isset($distance[$to]); $depth++) {
            $next = [];
            foreach ($frontier as $node) {
                if ($node !== $from && !$crossable($node)) {
                    continue;
                }
                foreach (array_keys($adjacency[$node] ?? []) as $neighbor) {
                    $known = $distance[$neighbor] ?? null;
                    if ($known === null) {
                        $distance[$neighbor] = $depth;
                        $next[$neighbor] = true;
                    }
                    if (($distance[$neighbor] ?? null) === $depth) {
                        $predecessors[$neighbor][$node] = true;
                    }
                }
            }
            $frontier = array_keys($next);
        }

        if (!isset($distance[$to])) {
            return null;
        }

        $paths = [];
        $this->collectPaths($to, $from, $predecessors, [], $paths);

        $best = null;
        $bestKey = null;
        foreach ($paths as $path) {
            // $path runs from $from to $to; intermediates exclude both ends.
            $intermediates = array_slice($path, 1, -1);
            $new = count(array_filter($intermediates, fn ($l) => !isset($selected[$l])));
            $bottleneck = PHP_INT_MAX;
            for ($k = 0; $k < count($path) - 1; $k++) {
                $bottleneck = min($bottleneck, $adjacency[$path[$k]][$path[$k + 1]] ?? 0);
            }
            $key = [$new, -$bottleneck, implode('|', $intermediates)];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = $intermediates;
            }
        }

        return $best;
    }

    /**
     * Enumerate shortest paths by walking predecessors back from $node to $origin.
     *
     * @param array<string, array<string, bool>> $predecessors
     * @param string[] $suffix
     * @param array<int, string[]> $paths
     */
    private function collectPaths(string $node, string $origin, array $predecessors, array $suffix, array &$paths): void
    {
        if (count($paths) >= self::MAX_SHORTEST_PATHS) {
            return;
        }

        array_unshift($suffix, $node);
        if ($node === $origin) {
            $paths[] = $suffix;

            return;
        }

        foreach (array_keys($predecessors[$node] ?? []) as $previous) {
            $this->collectPaths($previous, $origin, $predecessors, $suffix, $paths);
        }
    }
}
