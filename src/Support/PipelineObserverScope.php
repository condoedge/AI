<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\DTOs\PipelineContext;

/**
 * The AMBIENT observer of the turn currently running.
 *
 * The primary carrier of the turn's observer is PipelineContext::$observer.
 * A few LLM call sites never receive the context (the
 * empty-result abstention); AiManager::answerQuestion
 * runs the whole turn inside run(), so those sites can still report through
 * current(). run() always restores the previous observer (try/finally), so a
 * long-lived worker never carries one turn's observer into the next. Every
 * observer handed out is wrapped in GuardedPipelineObserver.
 *
 * The scope also collects the turn's silent FALLBACKS (AiLogger::fallback —
 * e.g. semantic selection degrading to keyword matching because the vector
 * index was unreachable). They are observation only: the understanding stage
 * summary reports them as data 'fallbacks'; nothing reads them back.
 */
final class PipelineObserverScope
{
    private static ?PipelineObserverInterface $current = null;

    private static ?NullPipelineObserver $null = null;

    /** Per-turn fallback buffer cap (a degraded store can fall back once per angle). */
    public const MAX_FALLBACKS = 50;

    /** Each fallback reason is trimmed to this many characters. */
    public const MAX_REASON_CHARS = 500;

    /** Fallbacks recorded in the running turn; null outside a turn. */
    private static ?array $fallbacks = null;

    /**
     * Run $fn with $observer as the ambient turn observer.
     */
    public static function run(PipelineObserverInterface $observer, callable $fn): mixed
    {
        $previous = self::$current;
        $previousFallbacks = self::$fallbacks;
        self::$current = GuardedPipelineObserver::wrap($observer);
        self::$fallbacks = [];

        try {
            return $fn();
        } finally {
            self::$current = $previous;
            self::$fallbacks = $previousFallbacks;
        }
    }

    /**
     * The ambient observer, or the shared null observer outside a turn.
     */
    public static function current(): PipelineObserverInterface
    {
        return self::$current ?? (self::$null ??= new NullPipelineObserver());
    }

    /**
     * The observer for a call site: the context's own observer when it has a
     * real one, else the ambient turn observer. Always guarded (isolation).
     */
    public static function for(?PipelineContext $context): PipelineObserverInterface
    {
        if ($context !== null && !$context->observer instanceof NullPipelineObserver) {
            return GuardedPipelineObserver::wrap($context->observer);
        }

        return self::current();
    }

    /**
     * Record a silent fallback of the running turn (no-op outside a turn).
     *
     * @param array{service: string, from: string, to: string, reason: string} $fallback
     */
    public static function recordFallback(array $fallback): void
    {
        if (self::$fallbacks === null || count(self::$fallbacks) >= self::MAX_FALLBACKS) {
            return;
        }

        $fallback['reason'] = mb_substr(self::maskEndpoints((string) ($fallback['reason'] ?? '')), 0, self::MAX_REASON_CHARS);
        self::$fallbacks[] = $fallback;
    }

    /**
     * Store exception text often embeds the endpoint (scheme://host:port/path,
     * a bare host:port, an IP). Mask them before the reason reaches any trace.
     * Purely structural patterns — no semantic matching.
     */
    private static function maskEndpoints(string $text): string
    {
        $text = preg_replace('~\b[a-z][a-z0-9+.\-]*://[^\s\'"<>()]+~i', '[url]', $text) ?? $text;
        $text = preg_replace('~\b\d{1,3}(?:\.\d{1,3}){3}(?::\d{1,5})?\b~', '[host]', $text) ?? $text;

        return preg_replace('~\b(?:[a-z0-9\-]+\.)*[a-z0-9\-]*[a-z][a-z0-9\-]*:\d{2,5}\b~i', '[host]', $text) ?? $text;
    }

    /**
     * The fallbacks recorded so far in the running turn ([] outside a turn).
     *
     * @return array<int, array{service: string, from: string, to: string, reason: string}>
     */
    public static function fallbacks(): array
    {
        return self::$fallbacks ?? [];
    }
}
