<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Digest;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\Digest\DigestSourceResult;
use Condoedge\Ai\DTOs\QueryExecutionResult;

/**
 * The deterministic heart of the attention digest: walks the host-configured
 * registry and executes every source. Makes ZERO LLM calls.
 *
 * SECURITY INVARIANT (design §0.4, made structural): the runner's ONLY path to
 * the graph is QueryExecutorInterface::execute()/executeCount() with the SAME
 * options the main pipeline builds (user, conversation_id, read-only default) —
 * so tenant scoping, the read-only gate and rate limiting apply identically per
 * source. Hardening: the executor honors $options['skip_security'], so the
 * runner STRIPS that key from whatever options it receives before every
 * execution — the invariant holds even against a poisoned options array.
 *
 * Error semantics: see DigestSourceResult (design §3 step 4 — stated once).
 */
class DigestRunner
{
    private readonly AttentionSourceRegistry $registry;

    public function __construct(
        private readonly QueryExecutorInterface $executor,
        ?AttentionSourceRegistry $registry = null,
    ) {
        $this->registry = $registry ?? new AttentionSourceRegistry();
    }

    public function registry(): AttentionSourceRegistry
    {
        return $this->registry;
    }

    /**
     * Execute every enabled source (config order, capped at max_sources).
     *
     * @param array<string, mixed> $execOptions The SAME options AiManager
     *        threads into every pipeline execution (user, original_question,
     *        conversation_id, ...).
     * @return DigestSourceResult[]
     */
    public function run(array $execOptions): array
    {
        // SECURITY INVARIANT: never forward a security bypass, no matter who
        // built the options array.
        unset($execOptions['skip_security']);

        // ROW-CAP AUTHORITY: the per-source cap (source limit / digest default)
        // is the ONLY limit a digest execution runs with. A caller-supplied
        // options['limit'] would win the array union below — silently replacing
        // every source's cap AND desyncing capHit (computed against the cap)
        // from the actual truncation — so it is stripped like skip_security.
        unset($execOptions['limit']);

        // BUDGETED BATCH (item F — digest self-rate-limit fix): one digest run
        // is ONE user action, so it pays ONE charge on the shared executor
        // limiter instead of ~2x max_sources (which starved the 30/min window
        // on back-to-back digest turns and printed "could not be checked" for
        // real sources). The key is minted HERE, per run, random — a caller-
        // supplied value is stripped first (same trust posture as
        // skip_security) so no caller can join or forge a batch. Every source,
        // fallback and companion count below shares it; QueryExecutor charges
        // the first execution and lets the rest of the batch ride.
        unset($execOptions['rate_limit_batch']);
        $execOptions['rate_limit_batch'] = 'digest:' . bin2hex(random_bytes(8));

        $sources = $this->registry->sourcesToRun();
        if ($sources === []) {
            return [];
        }

        // Amendment B2: cap overflow is LOUD — the registry exposes the skipped
        // keys (ridden into metadata.digest.skipped_sources by the composer) and
        // the runner logs a warning naming them.
        $skipped = $this->registry->skippedSourceKeys();
        if ($skipped !== []) {
            \Log::warning(
                'Digest: enabled attention sources exceed ai.digest.max_sources ('
                . $this->registry->maxSources() . ') — skipped: ' . implode(', ', $skipped)
            );
        }

        $results = [];
        foreach ($sources as $key => $source) {
            $results[] = $this->runSource((string) $key, $source, $execOptions);
        }

        return $results;
    }

    /**
     * Steps 1-3 of design §3 under one per-source try/catch (step 4): execute,
     * establish the true total on a cap-hit, run the labeled fallback on an
     * honest-empty primary.
     *
     * @param array<string, mixed> $source Normalized registry entry.
     * @param array<string, mixed> $execOptions
     */
    private function runSource(string $key, array $source, array $execOptions): DigestSourceResult
    {
        $cap = $source['limit'] ?? $this->registry->defaultRowCap();

        try {
            // Cap-first union: the left side of + wins, so the per-source cap
            // stays authoritative even if a 'limit' key ever reappears upstream.
            $execution = $this->executor->execute($source['cypher'], [], ['limit' => $cap] + $execOptions);

            $error = $this->executionError($execution);
            if ($error !== null) {
                return $this->errorResult($key, $source, $error);
            }

            $rows = $execution->data;
            $rowCount = count($rows);
            $capHit = $rowCount >= $cap && $rowCount > 0;

            // True-total honesty (companion-count discipline): fires ONLY on a
            // cap-hit; the canonical cypher carries no LIMIT so it counts as-is.
            // A count failure leaves the total unknown — the composer hedges,
            // the source never fails.
            $trueTotal = null;
            if ($capHit) {
                try {
                    $total = $this->executor->executeCount($source['cypher'], [], $execOptions);
                    $trueTotal = $total >= $rowCount ? $total : null;
                } catch (\Throwable $e) {
                    \Log::warning("Digest source '{$key}': companion count failed — total unknown: " . $e->getMessage());
                }
            }

            // Fallback slot: ONLY when the primary succeeded with 0 rows and the
            // host declared a labeled fallback.
            if ($rowCount === 0 && $source['fallback_cypher'] !== null) {
                return $this->runFallback($key, $source, $execOptions, $cap);
            }

            return new DigestSourceResult(
                key: $key,
                label: $source['label'],
                description: $source['description'],
                rows: $rows,
                rowCount: $rowCount,
                trueTotal: $trueTotal,
                capHit: $capHit,
                actionKey: $source['action_key'],
                entity: $source['entity'],
                emptyText: $source['empty_text'],
                cypher: $source['cypher'],
            );
        } catch (\Throwable $e) {
            \Log::warning("Digest source '{$key}' failed: " . $e->getMessage());

            return $this->errorResult($key, $source, $e->getMessage());
        }
    }

    /**
     * The fallback runs through the IDENTICAL executor path (same stripped
     * options, same cap) under its own try/catch. Failure semantics per §3
     * step 4: the primary's honest-empty stands; the failure is a note.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $execOptions
     */
    private function runFallback(string $key, array $source, array $execOptions, int $cap): DigestSourceResult
    {
        try {
            $execution = $this->executor->execute($source['fallback_cypher'], [], ['limit' => $cap] + $execOptions);

            $error = $this->executionError($execution);
            if ($error !== null) {
                return $this->honestEmpty($key, $source, fallbackError: $error);
            }

            $rows = $execution->data;
            if ($rows === []) {
                // Fallback found nothing either: a plain honest-empty.
                return $this->honestEmpty($key, $source);
            }

            return new DigestSourceResult(
                key: $key,
                label: $source['label'],
                description: $source['description'],
                rows: $rows,
                rowCount: count($rows),
                usedFallback: true,
                fallbackLabel: $source['fallback_label'],
                actionKey: $source['action_key'],
                entity: $source['entity'],
                emptyText: $source['empty_text'],
                cypher: $source['fallback_cypher'],
            );
        } catch (\Throwable $e) {
            \Log::warning("Digest source '{$key}': fallback failed — keeping the honest-empty: " . $e->getMessage());

            return $this->honestEmpty($key, $source, fallbackError: $e->getMessage());
        }
    }

    /**
     * Map an execution result to a PRIMARY error, or null when healthy.
     *
     * A coverage-gate safe-stop is misconfiguration and must be LOUD (an error
     * line, never honest-empty) — design §6 risk table.
     */
    private function executionError(QueryExecutionResult $execution): ?string
    {
        if (($execution->metadata['safe_stop'] ?? false) === true) {
            return 'safe_stop: the source query could not be proven tenant-scoped (see authoring rules)';
        }

        if (!empty($execution->errors)) {
            $first = $execution->errors[0];
            $message = is_array($first) ? ($first['message'] ?? null) : (is_string($first) ? $first : null);

            return is_string($message) && $message !== '' ? $message : 'query execution failed';
        }

        // A failed execution with NO error detail is still a failure. The
        // package executor throws instead of returning success=false, but
        // QueryExecutorInterface is a public seam: a host implementation may
        // return success=false with empty errors[] — rendering that as
        // "nothing pending" would mask a broken source as an honest empty
        // (§3 step 4 forbids exactly that).
        if (!$execution->success) {
            return 'query execution failed';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function errorResult(string $key, array $source, string $error): DigestSourceResult
    {
        return new DigestSourceResult(
            key: $key,
            label: $source['label'],
            description: $source['description'],
            actionKey: $source['action_key'],
            entity: $source['entity'],
            emptyText: $source['empty_text'],
            error: $error,
            cypher: $source['cypher'],
        );
    }

    /**
     * @param array<string, mixed> $source
     */
    private function honestEmpty(string $key, array $source, ?string $fallbackError = null): DigestSourceResult
    {
        return new DigestSourceResult(
            key: $key,
            label: $source['label'],
            description: $source['description'],
            actionKey: $source['action_key'],
            entity: $source['entity'],
            emptyText: $source['empty_text'],
            fallbackError: $fallbackError,
            cypher: $source['cypher'],
        );
    }
}
