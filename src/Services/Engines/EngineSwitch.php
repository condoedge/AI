<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Engines;

use Condoedge\Ai\Contracts\AnswerEngineInterface;
use Condoedge\Ai\Facades\AI;

/**
 * Which engine answers a chat turn (ai.engine).
 *
 *   'engine' => ['default' => 'v1', 'engines' => ['v2' => App\Ai\EngineV2::class]]
 *
 * PIPELINE is the question pipeline and is always there. A turn runs the engine its
 * caller names (pipeline option `engine`), else the default; a name that is not
 * registered falls back to the pipeline, never to an error. Unconfigured, every turn
 * is the pipeline and carries no engine name.
 */
final class EngineSwitch
{
    public const PIPELINE = 'v1';

    /** Whether another engine is registered: without one nothing is stamped on a turn. */
    public static function configured(): bool
    {
        return self::registered() !== [];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_values(array_unique([self::PIPELINE, ...array_keys(self::registered())]));
    }

    public static function resolve(mixed $requested = null): string
    {
        foreach ([$requested, config('ai.engine.default')] as $name) {
            if (is_string($name) && in_array($name, self::names(), true)) {
                return $name;
            }
        }

        return self::PIPELINE;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function answer(string $engine, string $question, array $options): array
    {
        $response = $engine === self::PIPELINE
            ? AI::answerQuestion($question, $options)
            : app(self::registered()[$engine])->answer($question, $options);

        if (self::configured()) {
            $response['metadata'] = ['engine' => $engine] + (is_array($response['metadata'] ?? null) ? $response['metadata'] : []);
        }

        return $response;
    }

    /** @return array<string, class-string<AnswerEngineInterface>> */
    private static function registered(): array
    {
        return array_filter(
            (array) config('ai.engine.engines', []),
            static fn ($class, $name): bool => is_string($name) && $name !== self::PIPELINE
                && is_string($class) && is_subclass_of($class, AnswerEngineInterface::class),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
