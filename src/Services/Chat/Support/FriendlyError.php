<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Support;

use Condoedge\Ai\Exceptions\QueryTimeoutException;
use Illuminate\Database\QueryException;

/**
 * The single user-facing error mapping for chat turns, shared by ChatPresenter (which exposes
 * the KEY) and AskQuestionUseCase (which stores the TRANSLATED text as the assistant message).
 *
 * Classifies by EXCEPTION TYPE, never by message substring: English-keyword matching
 * (stripos($message, 'connection'|'timeout'|'api')) silently falls through for French or any
 * localized/driver-translated message. Mapping by type is locale-independent.
 */
final class FriendlyError
{
    public const CONNECTION = 'ai.chat.error-connection';
    public const TIMEOUT = 'ai.chat.error-timeout';
    public const GENERIC = 'ai.chat.error-generic';

    /**
     * Translation key for the failure: connection | timeout | generic.
     *
     * Provider / LLM / query-pipeline failures (QueryGenerationException,
     * QueryExecutionException, CircuitBreakerOpenException) and any unrecognized Throwable
     * resolve to the generic key.
     */
    public static function key(\Throwable $e): string
    {
        // Database connection / query failures. QueryException covers driver errors;
        // ConnectionException / PDOException cover lower-level connect failures that may
        // surface before a query is built.
        if (
            $e instanceof QueryException
            || $e instanceof \Illuminate\Database\ConnectionException
            || $e instanceof \PDOException
        ) {
            return self::CONNECTION;
        }

        if ($e instanceof QueryTimeoutException) {
            return self::TIMEOUT;
        }

        return self::GENERIC;
    }

    /**
     * The key translated in the current app locale (for text that gets persisted).
     */
    public static function message(\Throwable $e): string
    {
        return (string) __(self::key($e));
    }
}
