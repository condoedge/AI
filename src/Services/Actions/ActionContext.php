<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

/**
 * ActionContext
 *
 * The typed, readonly context an action's handle()/authorize() receives. It carries the
 * acting identity (user + team) and the render anchor (entity_id, entity_type, text,
 * result_set) so an action reliably receives WHO is acting instead of falling back to a
 * stale static auth() in CLI/queue/eval. $input is the schema-filtered param bag.
 *
 * {@see fromArray()} bridges from the dispatcher's loose context array, keyed by string.
 */
final class ActionContext
{
    /**
     * @param mixed $user        The acting user (nullable in unauthenticated contexts).
     * @param mixed $team        The acting team/tenant (nullable).
     * @param string|null $entityType  Entity label for entity:// actions, else null.
     * @param int|string|null $entityId  Entity id for entity:// actions, else null.
     * @param string|null $text  The link text the LLM emitted (used as a label).
     * @param array<string, mixed> $input  Schema-filtered params.
     * @param array<int, array<string, mixed>> $resultSet  Current/last tabular result set.
     * @param string|null $answer  The FULL composed answer the link was embedded in
     *                             (markdown) — lets document-style actions render what the
     *                             LLM wrote, not just the tabular result set.
     * @param array<int, array<string, mixed>> $documentRows  The composed export document's
     *                             rows as data (Excel document mode writes them directly).
     * @param array<string, string> $documentHeaders  Column key => header of those rows, as
     *                             the composed document's table heads them (empty for
     *                             messages persisted before headers were recorded).
     * @param array{cypher: string, parameters: array<string, mixed>, conversation_id: int|string|null}|null $recordedQuery
     *                             The query RECORDED with the message the action was clicked on
     *                             (its text and its bound parameters), for an export that
     *                             re-runs it as the acting user (export_* `mode=rerun`). Set
     *                             only by a click-time entry point that read it from the stored
     *                             message — never from link params, never at render.
     * @param string|null $resultCypher  The TEXT of the query $resultSet came from (the message's
     *                             own recorded query), for DISPLAY shaping of those stored rows
     *                             only — which columns are one entity's name. Never executed:
     *                             a re-run needs $recordedQuery, which a render never carries.
     */
    public function __construct(
        public readonly mixed $user = null,
        public readonly mixed $team = null,
        public readonly ?string $entityType = null,
        public readonly int|string|null $entityId = null,
        public readonly ?string $text = null,
        public readonly array $input = [],
        public readonly array $resultSet = [],
        public readonly ?string $answer = null,
        public readonly ?string $documentMarkdown = null,
        public readonly array $documentRows = [],
        public readonly array $documentHeaders = [],
        public readonly ?array $recordedQuery = null,
        public readonly ?string $resultCypher = null,
    ) {
    }

    /**
     * Build from the dispatcher's loose context array + the schema-filtered input.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $context, array $input = []): self
    {
        $resultSet = $context['result_set'] ?? $context['rows'] ?? [];

        return new self(
            user: $context['user'] ?? null,
            team: $context['team'] ?? null,
            entityType: isset($context['entity_type']) ? (string) $context['entity_type'] : null,
            entityId: $context['entity_id'] ?? null,
            text: isset($context['text']) ? (string) $context['text'] : null,
            input: $input,
            resultSet: is_array($resultSet) ? $resultSet : [],
            answer: isset($context['answer']) ? (string) $context['answer'] : null,
            documentMarkdown: isset($context['document_markdown'])
                ? (string) $context['document_markdown']
                : (isset($context['documentMarkdown']) ? (string) $context['documentMarkdown'] : null),
            documentRows: is_array($context['document_rows'] ?? null) ? array_values($context['document_rows']) : [],
            // Stored data: only string column keys with a non-blank string header are kept.
            documentHeaders: array_filter(
                is_array($context['document_headers'] ?? null) ? $context['document_headers'] : [],
                static fn ($header, $column): bool => is_string($column) && is_string($header) && trim($header) !== '',
                ARRAY_FILTER_USE_BOTH
            ),
            recordedQuery: self::recordedQuery($context['recorded_query'] ?? null),
            resultCypher: is_string($context['result_cypher'] ?? null) && trim($context['result_cypher']) !== ''
                ? $context['result_cypher']
                : null,
        );
    }

    /**
     * A recorded query is usable only as a non-blank query text; its parameters are the
     * stored bag as-is (the re-run keeps the bindings it trusts) and the conversation id is
     * provenance. Anything else = none recorded.
     *
     * @return array{cypher: string, parameters: array<string, mixed>, conversation_id: int|string|null}|null
     */
    private static function recordedQuery(mixed $recorded): ?array
    {
        if (!is_array($recorded) || !is_string($recorded['cypher'] ?? null) || trim($recorded['cypher']) === '') {
            return null;
        }
        $conversationId = $recorded['conversation_id'] ?? null;

        return [
            'cypher' => $recorded['cypher'],
            'parameters' => is_array($recorded['parameters'] ?? null) ? $recorded['parameters'] : [],
            'conversation_id' => is_int($conversationId) || is_string($conversationId) ? $conversationId : null,
        ];
    }
}
