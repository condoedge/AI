<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Admin;

/**
 * Driver-aware SQL fragments over the JSON `metadata` column of ai_messages /
 * ai_conversations, so the insights KPIs are aggregated in ONE SQL pass on MySQL /
 * MariaDB (production) and SQLite (the package tests).
 *
 * Every column argument is a trusted, code-defined identifier (e.g. "m.metadata");
 * paths are code constants. No user input is ever interpolated here.
 */
final class InsightsSql
{
    public function __construct(private readonly string $driver)
    {
    }

    private function sqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /** A scalar string at $path (e.g. '$.feedback'), SQL NULL when absent. */
    public function str(string $column, string $path): string
    {
        return $this->sqlite()
            ? "json_extract({$column}, '{$path}')"
            : "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}'))";
    }

    /** A JSON number at $path, SQL NULL when absent or not a number. */
    public function num(string $column, string $path): string
    {
        return $this->sqlite()
            ? "(CASE WHEN json_type({$column}, '{$path}') IN ('integer', 'real') THEN json_extract({$column}, '{$path}') END)"
            : "(CASE WHEN JSON_TYPE(JSON_EXTRACT({$column}, '{$path}')) IN ('INTEGER', 'UNSIGNED INTEGER', 'DOUBLE', 'DECIMAL') "
                . "THEN CAST(JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}')) AS DECIMAL(16,3)) END)";
    }

    /** True when $path holds JSON true (or 1 / "true"). */
    public function truthy(string $column, string $path): string
    {
        return $this->sqlite()
            ? "(COALESCE(json_extract({$column}, '{$path}'), 0) IN (1, '1', 'true'))"
            : "(COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}')), '') IN ('true', '1'))";
    }

    /** True when one trace stage's data.branch equals $branch (a code constant). */
    public function traceBranchIs(string $column, string $branch): string
    {
        return $this->sqlite()
            ? "EXISTS (SELECT 1 FROM json_each({$column}, '$.trace.stages') AS s WHERE json_extract(s.value, '$.data.branch') = '{$branch}')"
            : "(JSON_CONTAINS(COALESCE(JSON_EXTRACT({$column}, '$.trace.stages[*].data.branch'), JSON_ARRAY()), '\"{$branch}\"') = 1)";
    }

    /**
     * The answer latency in ms: the persisted turn trace's total_ms (present on every
     * traced answer), else the execution_time_ms column (legacy rows).
     */
    public function latency(string $alias = 'm'): string
    {
        return 'COALESCE(' . $this->num("{$alias}.metadata", '$.trace.total_ms') . ", {$alias}.execution_time_ms)";
    }

    /**
     * An answer that FAILED: the turn threw and stored the friendly error reply
     * (metadata.error = true, set by AskQuestionUseCase / AiConversation::addMessage), or
     * the pipeline composed its error response (trace composition branch "error").
     * A stage error the pipeline recovered from (trace.errors non-empty but a normal
     * branch) is NOT a failed answer.
     */
    public function isError(string $alias = 'm'): string
    {
        return '(' . $this->truthy("{$alias}.metadata", '$.error') . ' OR ' . $this->traceBranchIs("{$alias}.metadata", 'error') . ')';
    }

    /** The conversation was produced by the eval harness (metadata.eval = true). */
    public function isEvalConversation(string $alias = 'c'): string
    {
        return $this->truthy("{$alias}.metadata", '$.eval');
    }

    public function feedback(string $alias = 'm'): string
    {
        return $this->str("{$alias}.metadata", '$.feedback');
    }
}
