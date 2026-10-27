<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Admin;

use Illuminate\Support\Collection;

/**
 * "Copy as eval case" for the admin transcript: one conversation as eval suite JSON
 * ({"conversations":[{"id","tags","turns":[{"ask","expect"}]}]}). The turns are the user's
 * messages; each expect is a skeleton of what its answer's trace knows (branch_in,
 * retrieves_doc, audience_is), to be edited before the case joins a suite.
 *
 * Anonymised: no user id, name or email (the promoter picks a local test user of the
 * audience). The package knows the shape only as JSON text, never the eval harness.
 */
final class EvalCase
{
    /** @param Collection<int, \Condoedge\Ai\Models\AiMessage> $messages in order */
    public static function fromTranscript(object $conversation, Collection $messages): string
    {
        $turns = [];
        $audience = null;
        foreach ($messages->values() as $i => $message) {
            if ($message->role !== 'user') {
                continue;
            }
            $answer = $messages->values()->slice($i + 1)->first(fn ($m) => $m->role !== 'system');
            $metadata = $answer?->role === 'assistant' && is_array($answer->metadata) ? $answer->metadata : null;
            $expect = $metadata !== null ? self::expect($metadata) : [];
            $audience ??= is_string($expect['audience_is'] ?? null) ? $expect['audience_is'] : null;
            $turns[] = ['ask' => trim((string) $message->content), 'expect' => (object) $expect];
        }

        return (string) json_encode([
            'conversations' => [[
                'id' => 'promoted-' . substr((string) $conversation->uuid, 0, 8),
                'tags' => array_values(array_filter(['promoted', $audience !== null ? 'aud:' . $audience : null])),
                'turns' => $turns,
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @param array<string, mixed> $metadata */
    private static function expect(array $metadata): array
    {
        $expect = [];
        $branch = ConversationInsights::traceSummary($metadata, null)['route'];
        if ($branch !== null) {
            $expect['branch_in'] = [$branch];
        }

        $slugs = [];
        foreach ((array) ($metadata['trace']['stages'] ?? []) as $stage) {
            if (is_array($stage) && ($stage['stage'] ?? null) === 'file_context') {
                foreach ((array) ($stage['data']['files'] ?? []) as $file) {
                    // The eval gate's slug: the document's file name without folder or extension.
                    $slug = is_array($file) ? pathinfo(str_replace('\\', '/', (string) ($file['id'] ?? '')), PATHINFO_FILENAME) : '';
                    if ($slug !== '' && !in_array($slug, $slugs, true)) {
                        $slugs[] = $slug;
                    }
                }
            }
        }
        if ($slugs !== []) {
            $expect['retrieves_doc'] = $slugs;
        }

        if (is_array($metadata['audience'] ?? null)) {
            $key = $metadata['audience']['key'] ?? null;
            $expect['audience_is'] = is_string($key) && $key !== '' ? $key : null;
        }

        return $expect;
    }
}
