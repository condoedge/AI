<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * The package's master switch: config('ai.enabled'), env AI_ENABLED (default true).
 *
 * Off, the assistant is absent from the host: the chat surfaces render nothing, the
 * package registers no route, and the background features (graph auto-sync, file
 * processing, scheduled tasks, the admin review page) are forced off — whatever the host
 * set for them individually. The host's code (views mounting the chat, menu links checking
 * Route::has) needs no change to ship with the assistant off.
 *
 * Off, NOTHING is ingested and nothing leaves the process. The container hands out no-op
 * clients for the graph, the vectors, the embeddings and the LLM (AiServiceProvider,
 * LlmResolver), so no API key or server is needed either. Above them: the commands say they
 * did nothing and succeed (SkippedWhenAiDisabled), the jobs and queued file events already on
 * a queue return, the file events queue nothing, AI::ingest/sync/remove return at once.
 *
 * Read once per process: after changing AI_ENABLED, clear a cached config and restart the
 * queue workers (a running worker keeps the value it started with).
 */
final class AiFeature
{
    /** Config keys forced to false while the master switch is off. */
    public const FORCED_OFF = [
        'ai.auto_sync.enabled',
        'ai.file_processing.enabled',
        'ai.tasks.enabled',
        'ai.admin.enabled',
    ];

    public static function enabled(): bool
    {
        if (!function_exists('app') || !app()->bound('config')) {
            return true;
        }

        $value = config('ai.enabled', true);

        return is_bool($value) ? $value : (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** Force the background features off when the master switch is off. */
    public static function applyTo(\Illuminate\Contracts\Config\Repository $config): void
    {
        if (self::enabled()) {
            return;
        }

        foreach (self::FORCED_OFF as $key) {
            $config->set($key, false);
        }
    }
}
