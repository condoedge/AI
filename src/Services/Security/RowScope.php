<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Contracts\RowScopeResolverInterface;
use Illuminate\Support\Facades\Log;

/**
 * The host's row scope for a user (ai.security.row_scope_resolver): null when the host narrows nobody or
 * not this user, the permitted ids otherwise. A resolver that fails narrows to nothing (fail closed).
 */
final class RowScope
{
    /** Container key of the per-request (scoped) memo: a queue worker resolves again on its next job. */
    public const MEMO = 'ai.row_scope.memo';

    /** @return list<int>|null */
    public static function idsFor(mixed $user): ?array
    {
        $class = config('ai.security.row_scope_resolver');
        if (!is_string($class) || $class === '') {
            return null;
        }

        $key = is_object($user) && isset($user->id) ? get_class($user) . ':' . $user->id : null;
        if ($key === null || !app()->bound(self::MEMO)) {
            return self::resolve($class, $user);
        }

        $memo = app(self::MEMO);
        if (!$memo->offsetExists($key)) {
            $memo[$key] = self::resolve($class, $user);
        }

        return $memo[$key];
    }

    /** @return list<int>|null */
    private static function resolve(string $class, mixed $user): ?array
    {
        try {
            $resolver = app($class);
            $ids = $resolver instanceof RowScopeResolverInterface ? $resolver->rowScopeIds($user) : [];
        } catch (\Throwable $e) {
            Log::channel(config('ai.security.log_channel', 'ai-security'))->warning('AI row scope failed', ['error' => $e->getMessage()]);

            return [];
        }

        return $ids === null ? null : array_values(array_map('intval', array_filter($ids, 'is_numeric')));
    }
}
