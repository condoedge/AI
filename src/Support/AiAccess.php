<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * Whether the assistant is open to THIS asker: config('ai.access.authorize'), a callable
 * `fn (mixed $user): bool` the host writes (an account that has not accepted the terms of the
 * assistant, a plan without it). Unset, everyone the master switch ({@see AiFeature}) lets in.
 *
 * Closed, the chat surfaces render nothing and a question is refused before anything is read or
 * sent to a model. A rule that throws closes the assistant.
 */
final class AiAccess
{
    /**
     * Whether a record of the host (an uploaded file) may be read and indexed by the assistant:
     * config('ai.access.authorize_record'), `fn (mixed $record): bool`. Indexing sends the text of a
     * document to the embedding provider, so an account the assistant is closed to indexes nothing.
     */
    public static function allowsRecord(mixed $record): bool
    {
        return self::decide(config('ai.access.authorize_record'), $record);
    }

    public static function allows(mixed $user): bool
    {
        return self::decide(config('ai.access.authorize'), $user);
    }

    private static function decide(mixed $rule, mixed $subject): bool
    {

        if (!is_callable($rule)) {
            return true;
        }

        try {
            return $rule($subject) === true;
        } catch (\Throwable $e) {
            \Log::warning('AiAccess: the host rule failed, the assistant stays closed: ' . $e->getMessage());

            return false;
        }
    }
}
