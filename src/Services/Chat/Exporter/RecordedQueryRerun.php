<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Exporter;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Services\Query\FanOutCollapse;
use Condoedge\Ai\Services\Response\ExportResponseComposer;

/**
 * RecordedQueryRerun — the rows of a FULL export, fetched by re-running a query that
 * was recorded with an answer.
 *
 * Why (eval p18-A roster): an answer shows 25 of 523 members and its stored rows are the
 * executor's first 100, so a file written from them is silently partial. The recorded
 * query, re-run without its DISPLAY cap up to `ai.export.document_row_limit`, holds every
 * row — what the follow-up "give me that as an Excel" already does
 * (AiManager::previousResultExportRows).
 *
 * DUPLICATION TO CONSOLIDATE: {@see self::run()} is the minimal re-execution logic of
 * AiManager::previousResultExportRows() (`$pii_…` replay, FanOutCollapse::uncappedQuery
 * widening, joinLists) — copied, because AiManager could not be edited when this was
 * written. AiManager should call this service; its companion counts (true total, anchor
 * total) and sample fallback stay there.
 *
 * SECURITY — every guarantee is by construction:
 *  - ONE path to the graph: QueryExecutorInterface::execute(), whose security layer scopes
 *    the query to the ACTING user's tenants; there is no raw graph call here.
 *  - The acting user is an argument of {@see self::run()} — the person asking NOW. Nothing
 *    recorded (ids, team lists, options) is threaded into the execution: the options sent
 *    are built here from scratch, read-only always.
 *  - Recorded parameters are stored data: only `$pii_…` bindings of the exact shape the
 *    pipeline mints are replayed ({@see self::boundParameters()}).
 *  - One un-batched execution: one charge on the acting user's rate limit.
 */
final class RecordedQueryRerun
{
    public function __construct(private readonly ?QueryExecutorInterface $executor = null)
    {
    }

    /**
     * Whether a re-run of $cypher would hold EVERY one of the result's $total rows: the
     * query can be widened past the display cap, the document may hold that many rows, and
     * every parameter the query was bound to ($parameters) is one a re-run replays
     * ({@see self::boundParameters()}) — a query bound to anything else cannot be re-run
     * from what is recorded. Structural (the query's own clauses, its bindings and two
     * limits) — no vocabulary.
     *
     * @param string|null $format 'pdf' applies the tighter PDF document limit
     * @param array<string|int, mixed> $parameters the parameters the query ran with
     */
    public static function coversAll(?string $cypher, int $total, ?string $format = null, array $parameters = []): bool
    {
        $limit = self::documentLimit($format);

        return $total > 0
            && $total <= $limit
            && count(self::boundParameters($parameters)) === count($parameters)
            && self::widened($cypher, $limit) !== null;
    }

    /**
     * The recorded query without its display cap, bounded by an explicit `LIMIT $limit`,
     * or null when it must not be widened: no query, a document no larger than the display
     * cap, the user's own top-N, a page (SKIP), unanalyzable text
     * ({@see FanOutCollapse::uncappedQuery()}).
     */
    public static function widened(?string $cypher, int $limit): ?string
    {
        if (!is_string($cypher) || trim($cypher) === '') {
            return null;
        }
        $displayCap = self::displayCap();

        return $limit > $displayCap ? FanOutCollapse::uncappedQuery($cypher, $displayCap, $limit) : null;
    }

    /**
     * The recorded parameters a re-run may bind: `$pii_…` tokens of the exact shape the
     * pipeline mints, with string values. Anything else in the stored bag (a forged
     * `ai_team_ids`, a positional key) is dropped.
     *
     * @return array<string, string>
     */
    public static function boundParameters(mixed $recorded): array
    {
        return array_filter(
            is_array($recorded) ? $recorded : [],
            static fn ($value, $key): bool => is_string($key)
                && preg_match('/^pii_[a-f0-9]{12}$/', $key) === 1
                && is_string($value),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Re-run a recorded query AS $user and return every row a document may hold.
     *
     * The query is fetched with one row more than the document limit, so `complete` is a
     * fact, not a guess: more rows than the limit = the file is cut there. A query that
     * must not be widened runs as recorded; `complete` then means "fewer rows than the
     * executor's cap came back".
     *
     * @param array<string, mixed> $parameters the recorded parameter bag (filtered here)
     * @param mixed $user the ACTING user: scopes the query and pays the rate-limit charge
     * @param array{format?: ?string, conversation_id?: mixed, original_question?: ?string} $options
     *        provenance and the document format only — no execution option is taken from the caller
     * @return array{rows: list<array<string, mixed>>, complete: bool, cypher: string}
     *         `cypher` is the query as executed (before tenant scoping). No rows when the
     *         query is blank, the security layer stopped it, it failed or it matched nothing.
     * @throws \Throwable whatever the executor throws (rate limit, read-only violation, timeout…)
     */
    public function run(string $cypher, array $parameters, mixed $user, array $options = []): array
    {
        // No acting user = nobody to scope the query to. Never left to the executor's
        // auth() fallback: the caller states who is asking, or nothing runs.
        if (trim($cypher) === '' || !is_object($user)) {
            return ['rows' => [], 'complete' => false, 'cypher' => $cypher];
        }

        $documentLimit = self::documentLimit(is_string($options['format'] ?? null) ? $options['format'] : null);
        $widened = self::widened($cypher, $documentLimit + 1);
        $query = $widened ?? $cypher;
        $fetch = $widened !== null ? $documentLimit + 1 : self::displayCap();

        $result = $this->executor()->execute($query, self::boundParameters($parameters), [
            'user' => $user,
            'read_only' => true,
            'limit' => $fetch,
            'original_question' => is_string($options['original_question'] ?? null) && trim($options['original_question']) !== ''
                ? $options['original_question']
                : 'export of a recorded result',
            'conversation_id' => $options['conversation_id'] ?? null,
        ]);

        if (!$result->success || !empty($result->errors) || empty($result->data)) {
            return ['rows' => [], 'complete' => false, 'cypher' => $query];
        }

        // A recorded per-anchor (collapsed) query returns list columns.
        $rows = array_values(array_filter(
            FanOutCollapse::joinLists($result->data, FanOutCollapse::cellLimitOf($query)),
            'is_array'
        ));
        $fetched = count($rows);

        if ($widened === null) {
            return ['rows' => $rows, 'complete' => $fetch <= 0 || $fetched < $fetch, 'cypher' => $query];
        }

        return [
            'rows' => array_slice($rows, 0, $documentLimit),
            'complete' => $fetched <= $documentLimit,
            'cypher' => $query,
        ];
    }

    /** Rows a generated document may carry (the document export's own limit). */
    public static function documentLimit(?string $format = null): int
    {
        return ExportResponseComposer::documentRowLimit($format);
    }

    /** The executor's display cap: the LIMIT the system itself puts on a list. */
    private static function displayCap(): int
    {
        return (int) config('ai.query_execution.default_limit', 100);
    }

    private function executor(): QueryExecutorInterface
    {
        return $this->executor ?? app(QueryExecutorInterface::class);
    }
}
