<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\CypherValidator;

/**
 * GraphQueryTool — the read-only run_query tool for the native tool loop.
 *
 * The model calling this tool N times in one turn IS multi-query (manifesto #16: "info about my
 * team AND me" → two run_query calls → one composed answer). It is a plain read-only
 * {@see AiActionInterface}, so it rides the SAME door as every other tool: dispatched through
 * {@see ActionDispatcher}, capped by the loop's per-turn call budget ({@see ToolLoopRunner}),
 * recorded as an invocation. It adds NO second orchestration layer.
 *
 * The one rule that makes it safe: every call funnels its Cypher through the EXISTING
 * {@see QueryExecutorInterface} — so tenant-scope injection, the read-only gate and the
 * {@see QueryExecutionResult} DTO are all REUSED, never re-implemented. The acting user from the
 * dispatch context rides into the executor as $options['user'] so per-query scoping resolves the
 * real identity on CLI/queue/eval paths.
 *
 * A pre-flight write-clause scan (drawn from the SAME {@see CypherValidator::DANGEROUS_KEYWORDS}
 * the executor's read-only gate enforces — one source, never a divergent list) gives the model a
 * clean corrective result before a write ever reaches the executor; the executor's own
 * ReadOnlyViolationException is the second backstop and degrades to the same corrective result.
 *
 * OFF BY DEFAULT: nothing registers or offers this tool unless config('ai.actions.native_query_tool')
 * is on AND the native tool loop itself is on. Flipping it live additionally awaits tenant-scoping
 * sign-off (see config comment + doc 97).
 */
final class GraphQueryTool extends \Condoedge\Ai\Services\Actions\BuiltIn\AbstractBuiltInAction implements AiActionInterface
{
    /** Rows handed back to the model per call — the sampling cap mirrors a result-set preview, not the full set. */
    public const MAX_SAMPLE_ROWS = 50;

    /**
     * Resolved lazily so the registry can instantiate the action with no constructor args
     * (the house-Plugin `new $class()` path); tests inject a recording fake.
     */
    private ?QueryExecutorInterface $executor;

    public function __construct(?QueryExecutorInterface $executor = null)
    {
        $this->executor = $executor;
    }

    public function key(): string
    {
        return 'run_query';
    }

    public function label(): string
    {
        return 'Run a read-only data query';
    }

    public function description(): string
    {
        return 'Run ONE read-only Cypher query against the graph and get its rows back. Call this'
            . ' tool MULTIPLE times in a single turn when the user asks for several disjoint things'
            . ' at once (e.g. "info about my team AND me") — one call per distinct question — then'
            . ' compose a single answer over all the result sets. Read-only only: never use'
            . ' CREATE/MERGE/SET/DELETE/REMOVE/DROP/DETACH.';
    }

    public function inputSchema(): array
    {
        return [
            'purpose' => [
                'type' => 'string',
                'label' => 'What this sub-query answers, in a few words (e.g. "my team members").',
            ],
            'cypher' => [
                'type' => 'string',
                'required' => true,
                'label' => 'A single read-only Cypher query (MATCH ... RETURN ...). No write clauses.',
            ],
        ];
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $cypher = trim((string) ($input['cypher'] ?? ''));
        if ($cypher === '') {
            return AiActionResult::error('No Cypher was provided. Supply a single read-only MATCH ... RETURN query.');
        }

        if ($this->containsWriteClause($cypher)) {
            return AiActionResult::error(
                'This query was rejected: only read-only queries are allowed. Remove any'
                . ' CREATE/MERGE/SET/DELETE/REMOVE/DROP/DETACH clause and return data instead.'
            );
        }

        try {
            $result = $this->executor()->execute($cypher, [], $this->executionOptions($context));
        } catch (\Throwable $e) {
            // The executor's read-only gate (and any backend error) surface here. Never crash the
            // loop: hand the model a corrective line it can recover from on the next round.
            return AiActionResult::error('The query could not be run (read-only/data error): ' . $e->getMessage());
        }

        if (!$result->success) {
            $detail = $result->errors[0] ?? 'unknown error';

            return AiActionResult::error('The query failed: ' . $detail);
        }

        return $this->tableResult($input, $result);
    }

    /**
     * Execution options for the EXISTING executor. read_only is forced on (this tool never
     * writes), and the acting user rides through so per-query tenant scoping resolves the real
     * identity rather than a stale static auth().
     *
     * @return array<string, mixed>
     */
    private function executionOptions(ActionContext $context): array
    {
        $options = ['read_only' => true];
        if ($context->user !== null) {
            $options['user'] = $context->user;
        }

        return $options;
    }

    private function tableResult(array $input, QueryExecutionResult $result): AiActionResult
    {
        $rows = $result->data;
        $total = count($rows);
        $sample = array_slice(array_values($rows), 0, self::MAX_SAMPLE_ROWS);

        $headers = [];
        foreach ($sample as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                if (!in_array($key, $headers, true)) {
                    $headers[] = (string) $key;
                }
            }
        }

        $tableRows = [];
        foreach ($sample as $row) {
            if (!is_array($row)) {
                $tableRows[] = [$this->stringify($row)];
                continue;
            }
            $cells = [];
            foreach ($headers as $header) {
                $cells[] = $this->stringify($row[$header] ?? '');
            }
            $tableRows[] = $cells;
        }

        return AiActionResult::table(
            ['headers' => $headers, 'rows' => $tableRows],
            $this->summary($input, $total, count($tableRows)),
        );
    }

    private function summary(array $input, int $total, int $shown): string
    {
        $purpose = trim((string) ($input['purpose'] ?? ''));
        $lead = $purpose !== '' ? ('"' . $purpose . '": ') : '';

        if ($total === 0) {
            return $lead . 'no rows.';
        }

        $count = $lead . $total . ' row(s).';
        if ($shown < $total) {
            $count .= ' Showing the first ' . $shown . '.';
        }

        return $count;
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Pre-flight write-clause scan drawn from the SAME keyword source the executor's read-only
     * gate enforces — the two lists cannot diverge.
     */
    private function containsWriteClause(string $cypher): bool
    {
        foreach (CypherValidator::DANGEROUS_KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $cypher)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Container-tolerant resolution of the EXISTING executor so the registry's `new $class()`
     * path works while tests inject a fake. There is no second execution path — this returns the
     * same QueryExecutor the rest of the pipeline uses.
     */
    private function executor(): QueryExecutorInterface
    {
        if ($this->executor === null) {
            $this->executor = app(QueryExecutorInterface::class);
        }

        return $this->executor;
    }
}
