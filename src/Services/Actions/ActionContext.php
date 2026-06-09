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
     */
    public function __construct(
        public readonly mixed $user = null,
        public readonly mixed $team = null,
        public readonly ?string $entityType = null,
        public readonly int|string|null $entityId = null,
        public readonly ?string $text = null,
        public readonly array $input = [],
        public readonly array $resultSet = [],
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
        );
    }
}
