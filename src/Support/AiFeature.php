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
