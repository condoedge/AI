<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Another way of answering a chat turn (ai.engine.engines), run in place of the
 * question pipeline for the turns that name it.
 *
 * It receives what the pipeline receives (AI::answerQuestion) and returns the same
 * response array, so the chat turn around it — security gate, trace, persistence,
 * tasks — is unchanged. The asker's rights are the engine's to enforce exactly as
 * the pipeline does: nothing downstream filters what it returns.
 */
interface AnswerEngineInterface
{
    /**
     * @param array<string, mixed> $options style, conversation_id, conversation_context, page_context, user, observer
     * @return array<string, mixed> at least 'answer' and 'metadata'
     */
    public function answer(string $question, array $options = []): array;
}
