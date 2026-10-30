<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

use Condoedge\Ai\DTOs\QueryGenerationResult;

/**
 * The query the conversation last got rows from, as a source for this turn's data.
 *
 * An answer is written from the rows of its own turn: the rows shown earlier are not available to
 * it. So a turn that needs what was already asked runs the recorded query again, through the same
 * path as a fresh one (scoped executor, chips, record of the turn), and answers from what it returns
 * now. Without this, the same question asked twice declined to query ("already shown") and answered
 * "not found" (measured 2026-10-30: 6 of 6 repeats).
 *
 * PROVENANCE: the query is executed, so it comes ONLY from the pipeline-stamped conversation
 * snapshot, never from raw options. Only `$pii_…` parameters ride along: the snapshot is stored data,
 * so nothing else (a forged `ai_team_ids`) may enter the execution.
 */
final class RecordedQuery
{
    /** Metadata marking a query result that came from the conversation, not from the generator. */
    public const SOURCE = 'recorded_query';

    public const REASON_SAME_QUESTION = 'same_question';
    public const REASON_GENERATOR = 'generator';

    /** @param array<string, string> $parameters */
    private function __construct(
        public readonly string $cypher,
        public readonly array $parameters,
        private readonly ?string $question,
    ) {
    }

    /** Null when the conversation recorded no query. */
    public static function fromConversation(array $conversation): ?self
    {
        $cypher = $conversation['last_cypher_query'] ?? null;
        if (!is_string($cypher) || trim($cypher) === '') {
            return null;
        }

        $parameters = array_filter(
            is_array($conversation['last_cypher_parameters'] ?? null) ? $conversation['last_cypher_parameters'] : [],
            static fn ($value, $key): bool => is_string($key) && preg_match('/^pii_[a-f0-9]{12}$/', $key) === 1 && is_string($value),
            ARRAY_FILTER_USE_BOTH
        );
        $question = $conversation['last_cypher_question'] ?? null;

        return new self($cypher, $parameters, is_string($question) ? $question : null);
    }

    /** Whether this question is, words aside from case and punctuation, the one this query answered. */
    public function answers(string $question): bool
    {
        return $this->question !== null && self::normalized($this->question) !== '' && self::normalized($this->question) === self::normalized($question);
    }

    /** This query as the turn's query, so everything after generation treats it as any other. */
    public function asQueryResult(string $reason): QueryGenerationResult
    {
        return new QueryGenerationResult(
            cypher: $this->cypher,
            explanation: 'The query the conversation recorded, run again.',
            confidence: 1.0,
            metadata: ['source' => self::SOURCE, 'recorded_query_reason' => $reason],
            parameters: $this->parameters,
        );
    }

    private static function normalized(string $text): string
    {
        $words = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)) ?? '';

        return trim($words);
    }
}
