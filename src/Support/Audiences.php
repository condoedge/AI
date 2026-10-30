<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Condoedge\Ai\Contracts\AskerFactsInterface;
use Condoedge\Ai\Contracts\AudienceResolverInterface;
use Condoedge\Ai\DTOs\ResolvedAudience;

/**
 * Who is asking, as the host sees it (ai.audiences) — the only reader of that block.
 *
 * Access is code's decision, never the model's: the documents a user may read are the
 * ones whose `audience` is in their map entry's `documents`, plus the ones written for
 * everyone (no audience). Without a resolver configured nothing is filtered or said.
 *
 *   'audiences' => [
 *       'resolver' => App\Ai\AudienceResolver::class,
 *       'map' => ['staff' => ['documents' => ['staff', 'buyer'], 'insider_voice' => true,
 *                             'prompt' => 'Works for the organisation; role {role}.', 'example_questions' => [...],
 *                             'data_entities' => ['Meeting', 'Motion']]], // omitted = every entity the team may read
 *   ]
 */
final class Audiences
{
    public static function configured(): bool
    {
        return is_string(config('ai.audiences.resolver')) && config('ai.audiences.resolver') !== '';
    }

    /** The asker's audience; null when unconfigured, unresolved, or the resolver failed. */
    public static function of(mixed $user): ?ResolvedAudience
    {
        if (!self::configured()) {
            return null;
        }

        try {
            $resolver = app(config('ai.audiences.resolver'));

            return $resolver instanceof AudienceResolverInterface ? $resolver->resolve($user) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The document audiences the asker may read: null = no filter (unconfigured);
     * [] = only the documents written for everyone.
     *
     * @return list<string>|null
     */
    public static function documents(mixed $user): ?array
    {
        if (!self::configured()) {
            return null;
        }
        $documents = self::entry($user)['documents'] ?? [];

        return is_array($documents) ? array_values(array_filter($documents, 'is_string')) : [];
    }

    /** The asker's role text for the answering prompts ({role} filled in); '' for none. */
    public static function prompt(mixed $user): string
    {
        $audience = self::of($user);
        $prompt = self::entry($user, $audience)['prompt'] ?? '';

        return is_string($prompt) ? trim(str_replace('{role}', (string) ($audience?->role ?? ''), $prompt)) : '';
    }

    /** The role text as the answering prompts print it; '' for none. */
    public static function promptBlock(mixed $user): string
    {
        $prompt = self::prompt($user);

        return $prompt === '' ? '' : "THE USER'S ROLE (how to frame the answer for them):\n{$prompt}";
    }

    /** Whether the answer speaks from inside the user's own organisation (the package default). */
    public static function insiderVoice(mixed $user): bool
    {
        return (bool) (self::entry($user)['insider_voice'] ?? true);
    }

    /** @return list<string>|null The audience's own welcome suggestions; null = the chat default. */
    public static function exampleQuestions(mixed $user): ?array
    {
        $questions = self::entry($user)['example_questions'] ?? null;

        return is_array($questions) ? array_values(array_filter($questions, 'is_string')) : null;
    }

    /** @return list<string>|null The entity labels the asker may query; null = no audience limit. */
    public static function dataEntities(mixed $user): ?array
    {
        $entities = self::entry($user)['data_entities'] ?? null;

        return is_array($entities) ? array_values(array_filter($entities, 'is_string')) : null;
    }

    /** @return list<string> What the host states about the asker's own records (ai.audiences.facts_resolver); never throws. */
    public static function askerFacts(mixed $user): array
    {
        $resolver = config('ai.audiences.facts_resolver');
        if (!is_string($resolver) || $resolver === '') {
            return [];
        }

        try {
            $facts = app($resolver);

            return $facts instanceof AskerFactsInterface ? array_values(array_filter($facts->factsFor($user), 'is_string')) : [];
        } catch (\Throwable $e) {
            \Log::warning('Asker facts failed', ['resolver' => $resolver, 'error' => get_class($e)]);

            return [];
        }
    }

    private static function entry(mixed $user, ?ResolvedAudience $audience = null): array
    {
        $audience ??= self::of($user);
        $entry = $audience !== null ? config('ai.audiences.map.' . $audience->key) : null;

        return is_array($entry) ? $entry : [];
    }
}
