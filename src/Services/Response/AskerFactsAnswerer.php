<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\TokenUsage;

/**
 * Answers a data question that returned no rows from what the host states about the asker's own
 * records (ai.audiences.facts_resolver), or declines. One short call that holds nothing else: inside
 * the composer's long prompt the facts lost to its other instructions, turn after turn (measured
 * 2026-10-30: the same question took the fact, then the refusal, under each rewording).
 */
class AskerFactsAnswerer
{
    public const STAGE = 'asker_facts';

    private const MAX_TOKENS = 300;

    public function __construct(
        private readonly LlmProviderInterface $llm,
    ) {
    }

    /** The answer, or null when no fact gives what is asked or the call failed: the caller goes on without it. */
    public function answer(string $question, PipelineContext $ctx): ?string
    {
        $messages = [
            ['role' => 'system', 'content' => "You answer one question from the facts below and nothing else: they are what the app shows "
                . "this user about their own records. A fact answers when it gives what is asked, even in other words or another language; "
                . "a simple calculation from a fact is allowed, and you show it. When a fact says that something is not shown to this user, "
                . "say so plainly. When no fact gives what is asked, set answered to false. Answer in the language of the question, "
                . "in one or two sentences, speaking directly to the user, and never mention the facts themselves."],
            ['role' => 'user', 'content' => "FACTS:\n- " . implode("\n- ", $ctx->askerFacts) . "\n\nQUESTION:\n{$question}"],
        ];

        $observer = PipelineObserverScope::for($ctx);
        $observer->promptBuilt(self::STAGE, $messages);
        try {
            $reply = $this->llm->chatJson($messages, [
                'temperature' => 0,
                'max_tokens' => self::MAX_TOKENS,
                'json_schema' => [
                    'name' => 'asker_facts_answer',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['answered' => ['type' => 'boolean'], 'answer' => ['type' => 'string']],
                        'required' => ['answered', 'answer'],
                        'additionalProperties' => false,
                    ],
                ],
            ]);
        } catch (\Throwable) {
            return null;
        } finally {
            TokenUsage::report($observer, self::STAGE, $this->llm);
        }

        $reply = is_object($reply) ? (array) $reply : (is_array($reply) ? $reply : []);
        $answer = is_string($reply['answer'] ?? null) ? trim($reply['answer']) : '';

        return ($reply['answered'] ?? null) === true && $answer !== '' ? $answer : null;
    }
}
