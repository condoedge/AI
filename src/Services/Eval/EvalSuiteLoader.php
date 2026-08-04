<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Eval;

/**
 * Parses eval suite files into normalized CONVERSATIONS.
 *
 * Two schemas are accepted:
 *
 *   1. Multi-turn: {"conversations":[{"id","role","turns":[{"ask","expect","standard","tags"}]}]}
 *   2. Legacy flat: {"turns":[{"question": "..."}]} — treated as ONE conversation
 *      of those turns, IN ORDER. This is deliberate: the old loader flattened
 *      turns into independent single-turn questions, which made every
 *      conversation-state bug invisible. Threading legacy files preserves the
 *      file format while fixing that blind spot.
 *
 * "question" is accepted as an alias of "ask" in both schemas.
 *
 * Expect blocks are validated FAIL-FAST here (unknown keys, bad value types)
 * so a typo'd gate kills the run with a clear message instead of silently
 * passing. Validation rules live in {@see ExpectationGate}.
 *
 * Uses native file functions (not the File facade) so it is testable without a
 * container and behaves identically inside artisan and plain PHPUnit.
 */
class EvalSuiteLoader
{
    /**
     * @return array<int, array{id: string, role: ?string, tags: string[], turns: array<int, array{ask: string, expect: array, standard: ?string, tags: string[]}>}>
     */
    public function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Suite file not found: {$path}");
        }

        $raw = file_get_contents($path);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Suite file is not valid JSON: {$path}");
        }

        if (!empty($data['conversations']) && is_array($data['conversations'])) {
            return $this->parseConversations($data['conversations']);
        }

        if (!empty($data['turns']) && is_array($data['turns'])) {
            // Legacy flat schema = ONE conversation of those turns.
            $id = pathinfo($path, PATHINFO_FILENAME) ?: 'legacy-suite';

            return $this->parseConversations([[
                'id' => $id,
                'turns' => $data['turns'],
            ]]);
        }

        throw new \RuntimeException(
            'Invalid suite. Expected {"conversations":[{"turns":[...]}]} or legacy {"turns":[{"question":"..."}]}.'
        );
    }

    /**
     * @param array<int, mixed> $conversations
     * @return array<int, array{id: string, role: ?string, tags: string[], turns: array}>
     */
    private function parseConversations(array $conversations): array
    {
        $out = [];
        foreach (array_values($conversations) as $index => $conversation) {
            if (!is_array($conversation)) {
                throw new \RuntimeException("Conversation #{$index} is not an object.");
            }

            $id = trim((string) ($conversation['id'] ?? '')) ?: 'conversation-' . ($index + 1);
            $turns = $conversation['turns'] ?? null;
            if (!is_array($turns) || $turns === []) {
                throw new \RuntimeException("Conversation '{$id}' has no turns.");
            }

            $parsedTurns = [];
            foreach (array_values($turns) as $turnIndex => $turn) {
                $parsed = $this->parseTurn($turn, $id, $turnIndex);
                if ($parsed !== null) {
                    $parsedTurns[] = $parsed;
                }
            }

            if ($parsedTurns === []) {
                throw new \RuntimeException("Conversation '{$id}' has no non-empty turns.");
            }

            $out[] = [
                'id' => $id,
                'role' => isset($conversation['role']) ? trim((string) $conversation['role']) : null,
                'tags' => array_values(array_map('strval', (array) ($conversation['tags'] ?? []))),
                'turns' => $parsedTurns,
            ];
        }

        return $out;
    }

    /**
     * @return array{ask: string, expect: array, standard: ?string, tags: string[], page_context: ?array}|null
     */
    private function parseTurn(mixed $turn, string $conversationId, int $turnIndex): ?array
    {
        if (!is_array($turn)) {
            throw new \RuntimeException("Turn #{$turnIndex} of conversation '{$conversationId}' is not an object.");
        }

        // "question" is the legacy alias of "ask".
        $ask = trim((string) ($turn['ask'] ?? $turn['question'] ?? ''));
        if ($ask === '') {
            // Skip silently, matching the old loader's tolerance of blank turns.
            return null;
        }

        $expect = $turn['expect'] ?? [];
        if (!is_array($expect)) {
            throw new \RuntimeException(
                "Turn #{$turnIndex} of conversation '{$conversationId}' has a non-object expect block."
            );
        }

        // FAIL FAST on unknown keys / bad values — config errors, not runtime reds.
        ExpectationGate::assertValidValues($expect, "conversation '{$conversationId}' turn #{$turnIndex}");

        return [
            'ask' => $ask,
            'expect' => $expect,
            'standard' => isset($turn['standard']) ? trim((string) $turn['standard']) : null,
            'tags' => array_values(array_map('strval', (array) ($turn['tags'] ?? ['suite']))),
            'page_context' => $this->parsePageContext($turn, $conversationId, $turnIndex),
        ];
    }

    /**
     * Optional per-turn page_context: the PageContext array the chat surface would
     * have sent (route/entity_type/entity_id/label), letting page-scoped probes run
     * through the SAME option the production surfaces thread. Must be an object when
     * present; an empty object normalizes to null (same effect as absent). Fail-fast
     * on a list/scalar — a malformed page prior silently running as a page-less turn
     * would grade the wrong behavior.
     */
    private function parsePageContext(array $turn, string $conversationId, int $turnIndex): ?array
    {
        $pageContext = $turn['page_context'] ?? null;
        if ($pageContext === null || $pageContext === []) {
            return null;
        }

        if (!is_array($pageContext) || array_is_list($pageContext)) {
            throw new \RuntimeException(
                "Turn #{$turnIndex} of conversation '{$conversationId}' has an invalid page_context "
                . '(must be an object of PageContext fields, e.g. {"entity_type":"Unit","entity_id":111}).'
            );
        }

        return $pageContext;
    }
}
