<?php

namespace Condoedge\Ai\Eval;

/**
 * Whether a turn measured the assistant at all. 'void': the model provider refused service
 * (quota, 429, circuit breaker), so the answer is a canned one. 'infra': the query ran out of
 * time, or the search index could not be reached. Neither is a wrong answer: the turn is left
 * ungraded and shown as one to run again. Read from the error texts only, never from the answer.
 */
class TurnLabel
{
    public const VOID = 'void';
    public const INFRA = 'infra';

    protected const VOID_PATTERN = '/\b429\b|insufficient_quota|\bquota\b|rate[\s_-]?limit|too many requests|circuit breaker|CircuitBreakerOpen/i';

    protected const TIMEOUT_PATTERN = '/timed?[\s_-]?out|exceeded (the )?timeout|QueryTimeout/i';

    protected const SEARCH_PATTERN = '/\b(408|5\d\d)\b|timed?[\s_-]?out|timeout|connection (refused|reset|aborted|timed out)|could not resolve|ssl|curl error|failed to generate embedding|network error/i';

    // The stages that reach the search index: a transport error there leaves the answer without its sources.
    protected const SEARCH_STAGES = ['understanding', 'file_context', 'retrieval'];

    /**
     * @param array<int, array{stage?: string, class?: string, message?: string}> $stageErrors the trace's errors
     * @return array{0: ?string, 1: ?string} the label and the text it was read from
     */
    public static function of(?string $error, array $stageErrors = []): array
    {
        $texts = array_filter(array_merge([$error], array_map(fn ($e) => static::text($e), $stageErrors)));

        if ($found = static::first(static::VOID_PATTERN, $texts)) {
            return [static::VOID, $found];
        }

        $execution = array_map(fn ($e) => static::text($e), array_filter($stageErrors, fn ($e) => ($e['stage'] ?? null) == 'execution'));

        if ($found = static::first(static::TIMEOUT_PATTERN, $execution)) {
            return [static::INFRA, $found];
        }

        $search = array_map(fn ($e) => static::text($e), array_filter($stageErrors, fn ($e) => in_array($e['stage'] ?? null, static::SEARCH_STAGES, true)));

        if ($found = static::first(static::SEARCH_PATTERN, $search)) {
            return [static::INFRA, $found];
        }

        return [null, null];
    }

    protected static function text(mixed $error): string
    {
        return is_array($error) ? trim(($error['stage'] ?? '?').': '.($error['class'] ?? '').' '.($error['message'] ?? '')) : (string) $error;
    }

    protected static function first(string $pattern, array $texts): ?string
    {
        foreach ($texts as $text) {
            if (preg_match($pattern, (string) $text)) {
                return mb_substr((string) $text, 0, 300);
            }
        }

        return null;
    }
}
