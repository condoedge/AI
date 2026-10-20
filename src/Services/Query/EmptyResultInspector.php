<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Digest\AttentionSourceRegistry;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Support\AliasNormalizer;

/**
 * EmptyResultInspector — what a query's EMPTY result means, established by code.
 *
 * A generated query that ran and matched nothing used to reach the abstention with
 * only Query Understanding's guesses about it (eval p16 A#3 / B#1): the stated
 * scope was QU's possessive flag ("nothing linked to you" after a TEAM-wide
 * query), an own-data empty never said WHY (the user owns no unit at all), and a
 * "next / upcoming" empty offered nothing although the host had declared what to
 * show instead. This class reads the EXECUTED query and host config, and asks the
 * graph through the pipeline's own executor — zero LLM calls:
 *
 *  - {@see scope()}: 'own' when the query is anchored on the user
 *    ({@see UserOwnershipPaths::anchorIn()}), 'team' when it was tenant-scoped
 *    without such an anchor, null when neither is known.
 *
 *  - {@see ownershipCheck()}: for an own-data query, ONE count of the ownership
 *    path its emptiness hangs on ({@see UserOwnershipPaths::probeFor()}) — "does
 *    this user have ANY unit?" — returned as a fact (entity, display name, count).
 *
 *  - {@see isAggregateOverNothing()}: the one-row shape of an empty result (a global
 *    aggregate that matched nothing: a total of 0).
 *
 *  - {@see sourceFallback()}: for the query's entity, the labeled fallback the host
 *    declared in `ai.attention_sources` ({@see AttentionSourceRegistry::fallbackSourceFor()}).
 *    Same contract as the digest: the fallback runs ONLY after the source's own
 *    (primary) query confirmed there is nothing — otherwise its label ("… — none
 *    scheduled") would be asserted without proof — and its rows are returned with
 *    that label, never as primary rows.
 *
 * SECURITY INVARIANT (as DigestRunner): the only path to the graph is
 * QueryExecutorInterface::execute() with the turn's own options (user, provenance,
 * the turn's rate_limit_batch — these executions ride the turn's single charge);
 * a skip_security key is stripped before every execution. A safe-stopped, failed
 * or throwing execution yields null: the honest empty stands, the turn never fails.
 */
final class EmptyResultInspector
{
    public const SCOPE_OWN = 'own';
    public const SCOPE_TEAM = 'team';

    /** Cypher's aggregating functions (language grammar, not vocabulary) — as FanOutCollapse. */
    private const AGGREGATE_CALL = '/\b(count|sum|avg|min|max|collect|stDev|stDevP|percentileCont|percentileDisc)\s*\(/i';

    private readonly AttentionSourceRegistry $registry;
    private readonly UserOwnershipPaths $paths;

    /** Graph executions made since the last takeExecutions() (observation only). */
    private int $executions = 0;

    public function __construct(
        private readonly QueryExecutorInterface $executor,
        ?AttentionSourceRegistry $registry = null,
        ?UserOwnershipPaths $paths = null,
    ) {
        $this->registry = $registry ?? new AttentionSourceRegistry();
        $this->paths = $paths ?? new UserOwnershipPaths();
    }

    /** The number of graph executions made since the last call — and reset (trace evidence). */
    public function takeExecutions(): int
    {
        [$count, $this->executions] = [$this->executions, 0];

        return $count;
    }

    /**
     * The scope the executed query covered: SCOPE_OWN (anchored on the user),
     * SCOPE_TEAM (the tenant filter ran and nothing anchors it on the user), or null.
     */
    public function scope(?string $cypher, QueryExecutionResult $execution, int|string|null $userId): ?string
    {
        if ($cypher === null || trim($cypher) === '') {
            return null;
        }
        if ($this->paths->anchorIn($cypher, $userId) !== null) {
            return self::SCOPE_OWN;
        }

        return ($execution->metadata['security_filter_applied'] ?? false) === true ? self::SCOPE_TEAM : null;
    }

    /**
     * Whether a ONE-row result is an aggregate computed over NOTHING — the other shape
     * of an empty result. A final RETURN made of aggregates only (no grouping key —
     * Cypher's grammar) yields exactly one row whatever it matched: with no match that
     * row holds only empty values (sum / count → 0, collect → [], min / max / avg →
     * null). Live 2026-10-06: "how much is my contribution this year?" came back as
     * `RETURN sum(n.total_amount)` → {0} → "$0.00" for a user who owns no unit.
     *
     * True only for that shape; whether the zero is vacuous (nothing to total) or a
     * real figure is for the caller to establish ({@see ownershipCheck()}).
     *
     * @param array<int, mixed> $rows
     */
    public function isAggregateOverNothing(?string $cypher, array $rows): bool
    {
        $row = count($rows) === 1 ? reset($rows) : null;
        if (!is_array($row) || $row === [] || $cypher === null) {
            return false;
        }
        foreach ($row as $value) {
            $void = $value === null || $value === '' || $value === []
                || ((is_int($value) || is_float($value)) && (float) $value === 0.0);
            if (!$void) {
                return false;
            }
        }

        $canonical = FanOutCollapse::canonical($cypher);
        if ($canonical === null || FanOutCollapse::hasUnionClause($canonical)) {
            return false;
        }
        $masked = FanOutCollapse::mask($canonical);
        $returnPos = FanOutCollapse::finalReturnOffset($masked);
        if ($returnPos === null) {
            return false;
        }
        $projection = substr($masked, $returnPos + strlen('RETURN'));
        if (preg_match('/(?<![.\w$])\b(ORDER\s+BY|SKIP|LIMIT)\b/i', $projection, $clause, PREG_OFFSET_CAPTURE) === 1) {
            $projection = substr($projection, 0, (int) $clause[0][1]);
        }

        $items = FanOutCollapse::splitTopLevel(trim($projection));
        if ($items === []) {
            return false;
        }
        foreach ($items as $item) {
            if (preg_match(self::AGGREGATE_CALL, $item) !== 1) {
                return false; // a grouping key: the row is a record
            }
        }

        return true;
    }

    /**
     * Whether the user has ANY record of the entity an own-data query hangs on.
     *
     * @param array<string, mixed> $execOptions the turn's execution options
     * @return array{entity: string, display_name: string, count: int}|null
     */
    public function ownershipCheck(?string $cypher, int|string|null $userId, array $execOptions): ?array
    {
        $probe = $this->paths->probeFor($cypher, $userId);
        if ($probe === null) {
            return null;
        }

        unset($execOptions['skip_security']);

        try {
            $this->executions++;
            $execution = $this->executor->execute($probe['cypher'], [], $execOptions);
        } catch (\Throwable $e) {
            \Log::warning('Ownership check of an empty own-data result failed — no fact stated: ' . $e->getMessage());

            return null;
        }

        $total = $this->healthy($execution) ? ($execution->data[0]['total'] ?? null) : null;
        if (!is_numeric($total)) {
            return null;
        }

        return [
            'entity' => $probe['entity'],
            'display_name' => $this->displayName($probe['entity']),
            'count' => (int) $total,
        ];
    }

    /**
     * The host-declared fallback rows for an empty result about $entity.
     *
     * @param array<string, mixed> $execOptions the turn's execution options
     * @return array{source: string, label: string, fallback_label: string, entity: string, action_key: ?string, cypher: string, limit: int, execution: QueryExecutionResult}|null
     */
    public function sourceFallback(?string $entity, array $execOptions): ?array
    {
        $source = $this->registry->fallbackSourceFor($entity);
        if ($source === null) {
            return null;
        }

        // Same authority as the digest: no bypass, and the source's own row cap.
        unset($execOptions['skip_security'], $execOptions['limit']);
        $cap = (int) ($source['limit'] ?? $this->registry->defaultRowCap());
        $options = ['limit' => $cap] + $execOptions;

        try {
            $this->executions++;
            $primary = $this->executor->execute($source['cypher'], [], $options);
            if (!$this->healthy($primary) || $primary->data !== []) {
                return null;
            }

            $this->executions++;
            $fallback = $this->executor->execute($source['fallback_cypher'], [], $options);
        } catch (\Throwable $e) {
            \Log::warning("Fallback of attention source '{$source['key']}' for an empty result failed — keeping the honest empty: " . $e->getMessage());

            return null;
        }

        if (!$this->healthy($fallback) || $fallback->data === []) {
            return null;
        }

        return [
            'source' => (string) $source['key'],
            'label' => (string) $source['label'],
            'fallback_label' => (string) $source['fallback_label'],
            'entity' => (string) $source['entity'],
            'action_key' => $source['action_key'],
            'cypher' => (string) $source['fallback_cypher'],
            // The row cap the rows were fetched with (the source's own, else the digest default).
            'limit' => $cap,
            'execution' => $fallback,
        ];
    }

    /** Ran, was not safe-stopped by the scoping layer, and reported no error. */
    private function healthy(QueryExecutionResult $execution): bool
    {
        return $execution->success
            && empty($execution->errors)
            && ($execution->metadata['safe_stop'] ?? false) !== true;
    }

    /** The entity as a person names it: its configured display name, else its label as words. */
    private function displayName(string $label): string
    {
        try {
            $config = EntityLabelIndex::shared()->configForLabel($label) ?? [];
        } catch (\Throwable $e) {
            $config = [];
        }
        $display = $config['metadata']['display_name'] ?? $config['display_name'] ?? null;

        return is_string($display) && trim($display) !== '' ? trim($display) : AliasNormalizer::phrase($label);
    }
}
