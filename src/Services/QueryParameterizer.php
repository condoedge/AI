<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

/**
 * QueryParameterizer
 *
 * Owns the security-sensitive (de)parameterization of stored example Cypher
 * queries. User-specific values (user_id / team_id and any project-configured
 * fields) are replaced with placeholders before a query is stored as a reusable
 * few-shot example, and replaced back with the *current* actor's values before
 * a stored example is shown to the LLM. This prevents user A's identity from
 * leaking into user B's prompt via similar-query retrieval.
 *
 * Identity-aware: an explicit actor (user id + team id) can be threaded in from
 * the pipeline ($options['user']) so CLI runs, queued jobs, eval runs and
 * impersonation parameterize against the real pipeline user rather than the
 * ambient web session. When no actor is supplied the resolver falls back to the
 * ambient identity (auth()->id() / currentTeamId()), preserving the original
 * behavior.
 *
 * Projects can register additional fields via config('ai.query_parameters').
 */
class QueryParameterizer
{
    /**
     * @param int|string|null $userId Explicit current user id, or null to resolve from auth()
     * @param int|string|null $teamId Explicit current team id, or null to resolve from currentTeamId()
     * @param bool $hasExplicitIdentity Whether an actor was explicitly supplied (suppresses the
     *                                  ambient auth() fallback so a null actor stays null)
     */
    public function __construct(
        private readonly int|string|null $userId = null,
        private readonly int|string|null $teamId = null,
        private readonly bool $hasExplicitIdentity = false,
    ) {
    }

    /**
     * Build a parameterizer bound to a specific pipeline actor.
     *
     * Resolves the user's id and team id from the threaded user object so the
     * stored/served examples are keyed to the real pipeline identity instead of
     * the ambient web session. A null user yields an actor-less instance whose
     * identity stays null (no ambient fallback) — matching the explicit intent
     * "this pipeline has no user".
     *
     * @param mixed $user The pipeline user (typically $options['user']), or null
     */
    public static function forUser(mixed $user): self
    {
        if ($user === null) {
            return new self(null, null, hasExplicitIdentity: true);
        }

        return new self(
            self::extractUserId($user),
            self::extractTeamId($user),
            hasExplicitIdentity: true,
        );
    }

    /**
     * Replace user-specific values with placeholders before storing queries.
     * Prevents user A's data from leaking to user B via similar queries.
     *
     * Uses config('ai.query_parameters') for project-specific fields.
     * Default: user_id → $currentUserId, team_id → $currentTeamId
     */
    public function parameterize(string $cypher): string
    {
        foreach ($this->resolveParameters() as $param) {
            $value = $param['value'];
            if ($value === null) {
                continue;
            }

            $field = $param['field'];
            $placeholder = $param['placeholder'];
            $quotedValue = preg_quote((string) $value, '/');

            // Property syntax: {field: value}
            $cypher = preg_replace(
                '/\b' . preg_quote($field, '/') . '\s*:\s*' . $quotedValue . '\b/',
                $field . ': ' . $placeholder,
                $cypher
            );
            // WHERE syntax: x.field = value
            $cypher = preg_replace(
                '/(\b' . preg_quote($field, '/') . '\s*=\s*)' . $quotedValue . '\b/',
                '${1}' . $placeholder,
                $cypher
            );
        }

        return $cypher;
    }

    /**
     * Replace placeholders with actual current actor values when serving similar
     * queries.
     */
    public function deparameterize(string $cypher): string
    {
        foreach ($this->resolveParameters() as $param) {
            $value = $param['value'];
            if ($value === null) {
                continue;
            }
            $cypher = str_replace($param['placeholder'], (string) $value, $cypher);
        }

        return $cypher;
    }

    /**
     * Resolve the configurable query parameters with their current values.
     *
     * Each entry: ['field' => 'user_id', 'placeholder' => '$currentUserId', 'value' => <id|null>]
     *
     * @return array<int, array{field: string, placeholder: string, value: mixed}>
     */
    public function resolveParameters(): array
    {
        // Built-in parameters
        $params = [
            ['field' => 'user_id', 'placeholder' => '$currentUserId', 'value' => $this->resolveUserId()],
            ['field' => 'team_id', 'placeholder' => '$currentTeamId', 'value' => $this->resolveTeamId()],
        ];

        // Project-specific parameters from config
        foreach (config('ai.query_parameters', []) as $custom) {
            if (!empty($custom['field']) && !empty($custom['placeholder'])) {
                $value = is_callable($custom['resolver'] ?? null) ? ($custom['resolver'])() : ($custom['value'] ?? null);
                $params[] = [
                    'field' => $custom['field'],
                    'placeholder' => $custom['placeholder'],
                    'value' => $value,
                ];
            }
        }

        return $params;
    }

    /**
     * Resolve the effective user id: the explicit actor when one was supplied,
     * otherwise the ambient auth() identity.
     */
    private function resolveUserId(): int|string|null
    {
        if ($this->hasExplicitIdentity) {
            return $this->userId;
        }

        return auth()->id();
    }

    /**
     * Resolve the effective team id: the explicit actor's team when one was
     * supplied, otherwise the ambient currentTeamId() identity.
     */
    private function resolveTeamId(): int|string|null
    {
        if ($this->hasExplicitIdentity) {
            return $this->teamId;
        }

        return function_exists('currentTeamId') ? currentTeamId() : null;
    }

    private static function extractUserId(mixed $user): int|string|null
    {
        if (is_object($user) && isset($user->id)) {
            return $user->id;
        }

        return null;
    }

    private static function extractTeamId(mixed $user): int|string|null
    {
        if (!is_object($user)) {
            return null;
        }

        if (method_exists($user, 'currentTeamId')) {
            return $user->currentTeamId();
        }

        if (method_exists($user, 'currentTeam')) {
            // Property access (not the currentTeam() method) so a belongsTo relation resolves to
            // the related model's id; calling the method returns the relation object, whose ->id
            // is undefined and throws — breaking every data query at parameterization.
            return $user->currentTeam?->id;
        }

        if (isset($user->current_team_id)) {
            return $user->current_team_id;
        }

        return null;
    }
}
