<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\DTOs\LlmToolCall;
use Condoedge\Ai\DTOs\LlmToolTurn;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * ToolLoopRunner
 *
 * The bounded native tool loop: one provider instance, at most tool_rounds turns of
 * chatWithTools(), every tool call funneled through {@see ActionInvocation} into
 * {@see ActionDispatcher::dispatch()} — the SAME door the markdown link path uses. The runner
 * has no execution call of its own and never branches on whether an action writes: read-only
 * calls come back already run (their summaryForLlm grounds the next round), approval-gated
 * calls come back Pending with the loop-time token DISCARDED — tokens are minted fresh at
 * every render. A source-level tripwire test pins both absences.
 *
 * Failure policy: each round already rides the provider's circuit breaker + transient-only
 * retry; the loop adds no retry of its own (a replay would re-execute read-only tools). A
 * failed round with nothing accumulated propagates — never a markdown fallback call against a
 * possibly-open breaker. A failed round after text/results accumulated composes the answer
 * deterministically from the partial text + the artifacts' summaryForLlm lines, zero further
 * LLM calls, so recorded invocations — and thus a deferred approval — survive an LLM outage.
 * On round exhaustion, one final no-tools call forces closing prose.
 *
 * History entries appended via formatToolResults() are provider-shaped and opaque: the loop
 * only appends and only ever passes them back into the same provider instance.
 */
final class ToolLoopRunner
{
    /**
     * @param int|null $toolRounds    Max tool-enabled rounds; null reads ai.actions.tool_rounds (default 2).
     * @param int|null $maxToolCalls  Max dispatches per run; null reads ai.actions.max_tool_calls (default 5).
     */
    public function __construct(
        private ActionDispatcher $dispatcher,
        private ?int $toolRounds = null,
        private ?int $maxToolCalls = null,
    ) {
    }

    /**
     * @param array $messages   Initial chat messages (system + user prompt roles).
     * @param ActionToolSpec[] $toolSpecs
     * @param array<string, mixed> $context  Dispatch context (user, user_id, result_set, ...).
     * @param array<string, mixed> $options  Provider options, passed through per round.
     */
    public function run(
        ToolCallingLlmInterface $llm,
        array $messages,
        array $toolSpecs,
        array $context = [],
        array $options = [],
    ): ToolLoopResult {
        $specByName = [];
        foreach ($toolSpecs as $spec) {
            if ($spec instanceof ActionToolSpec) {
                $specByName[$spec->name] = $spec;
            }
        }

        $state = [
            'rounds' => 0,
            'received' => 0,
            'dispatched' => 0,
            'degraded' => false,
            'texts' => [],
            'summaries' => [],
            'invocations' => [],
        ];

        $budget = $this->callBudget();
        $maxRounds = $this->maxRounds();

        for ($round = 1; $round <= $maxRounds; $round++) {
            try {
                $turn = $llm->chatWithTools($messages, $toolSpecs, $options);
            } catch (\Throwable $e) {
                if (!$this->hasAccumulated($state)) {
                    throw $e;
                }
                $state['degraded'] = true;

                return $this->finish($this->composedAnswer($state), $state);
            }
            $state['rounds']++;

            if ($turn->toolCalls === []) {
                $answer = trim((string) ($turn->text ?? ''));

                return $this->finish($answer !== '' ? $answer : $this->composedAnswer($state), $state);
            }

            if (is_string($turn->text) && trim($turn->text) !== '') {
                $state['texts'][] = trim($turn->text);
            }

            $results = $this->processTurn($turn, $specByName, $context, $budget, $state);
            $messages = array_merge($messages, $llm->formatToolResults($turn, $results));
        }

        return $this->close($llm, $messages, $options, $state);
    }

    /**
     * Round cap exhausted with the model still calling tools: one forced no-tools call for
     * closing prose (tools omitted — a dangling tool_choice would be invalid without them).
     * If that fails too, compose deterministically with zero further LLM calls.
     */
    private function close(ToolCallingLlmInterface $llm, array $messages, array $options, array $state): ToolLoopResult
    {
        unset($options['tool_choice']);

        try {
            $turn = $llm->chatWithTools($messages, [], $options);
            $state['rounds']++;

            $answer = trim((string) ($turn->text ?? ''));
            if ($answer !== '') {
                return $this->finish($answer, $state);
            }
        } catch (\Throwable $e) {
            // Fall through to the deterministic composition.
        }

        $state['degraded'] = true;

        return $this->finish($this->composedAnswer($state), $state);
    }

    /**
     * Produce one tool_result per call — providers require every call id to be answered, so a
     * skipped/duplicate/over-budget call still gets a result, just never a dispatch.
     *
     * @param array<string, ActionToolSpec> $specByName
     * @param array<string, mixed> $context
     * @return array<string, string|array{content: string, is_error: bool}>
     */
    private function processTurn(LlmToolTurn $turn, array $specByName, array $context, int $budget, array &$state): array
    {
        $results = [];
        $turnResults = [];

        foreach ($turn->toolCalls as $call) {
            $state['received']++;
            $results[$call->id] = $this->resultForCall($call, $specByName, $context, $budget, $turnResults, $state);
        }

        return $results;
    }

    /**
     * @param array<string, ActionToolSpec> $specByName
     * @param array<string, mixed> $context
     * @param array<string, string|array{content: string, is_error: bool}> $turnResults  By fingerprint.
     * @return string|array{content: string, is_error: bool}
     */
    private function resultForCall(
        LlmToolCall $call,
        array $specByName,
        array $context,
        int $budget,
        array &$turnResults,
        array &$state,
    ): string|array {
        if ($call->argsError !== null) {
            return $this->error("Invalid arguments for {$call->name}: {$call->argsError}. Correct the arguments and try again.");
        }

        $spec = $specByName[$call->name] ?? null;
        $action = $spec !== null ? $this->resolveAction($spec) : null;
        if ($spec === null || $action === null) {
            // Tool names are hints; the registry is the authority — an unadvertised or
            // unregistered name has no execution path at all.
            return $this->error("Unknown action: {$call->name}.");
        }

        $invocation = $this->invocationFor($spec, $call)->filtered($action, $this->dispatcher->registry());
        $fingerprint = $invocation->fingerprint();

        if (array_key_exists($fingerprint, $turnResults)) {
            return $turnResults[$fingerprint];
        }

        if ($state['dispatched'] >= $budget) {
            return $this->error('Tool call limit reached — answer with what you already have.');
        }

        $result = $this->dispatcher->dispatch($action, $invocation->params, $this->dispatchContext($invocation, $context));
        $state['dispatched']++;

        $content = $result === null
            ? $this->error('This action is unavailable.')
            : $this->contentFor($result, $action, $invocation, $state);

        $turnResults[$fingerprint] = $content;

        return $content;
    }

    /**
     * Map the dispatch outcome to the tool_result the model sees, recording the invocation for
     * render-time re-dispatch on every non-error kind. Pending means the dispatcher's approval
     * gate deferred the action: nothing executed, and the model is told so in terms it must
     * not paraphrase into a success claim.
     *
     * @return string|array{content: string, is_error: bool}
     */
    private function contentFor(AiActionResult $result, AiActionInterface $action, ActionInvocation $invocation, array &$state): string|array
    {
        if ($result->kind === ActionKind::Error) {
            return $this->error($result->summaryForLlm);
        }

        $this->record($invocation, $result, $state);

        if ($result->kind === ActionKind::Pending) {
            return $this->pendingContent($action);
        }

        $content = $result->summaryForLlm;
        if ($result->kind === ActionKind::Table && is_array($result->payload)) {
            $content = trim($content . "\n" . json_encode([
                'headers' => $result->payload['headers'] ?? [],
                'rows' => $result->payload['rows'] ?? [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $content;
    }

    private function record(ActionInvocation $invocation, AiActionResult $result, array &$state): void
    {
        $fingerprint = $invocation->fingerprint();
        if (!isset($state['invocations'][$fingerprint])) {
            $state['invocations'][$fingerprint] = $invocation;
        }

        $summary = trim($result->summaryForLlm);
        if ($summary !== '') {
            $state['summaries'][] = $summary;
        }
    }

    /**
     * The advertised tool name is a lossy sanitization of {EntityType}__{key}; the spec carries
     * the exact source identity, so re-anchor on it whenever the name parse disagrees instead
     * of trusting a reverse-parse of the wire name.
     */
    private function invocationFor(ActionToolSpec $spec, LlmToolCall $call): ActionInvocation
    {
        $parsed = ActionInvocation::fromToolCall($call);
        if ($parsed->key === $spec->key && $parsed->entityType === $spec->entityType) {
            return $parsed;
        }

        if ($spec->entityType === null) {
            return new ActionInvocation($spec->key, null, null, $call->args, '');
        }

        return new ActionInvocation($spec->key, $spec->entityType, $parsed->entityId, $parsed->params, '');
    }

    private function resolveAction(ActionToolSpec $spec): ?AiActionInterface
    {
        $registry = $this->dispatcher->registry();

        return $spec->entityType !== null
            ? $registry->getEntity($spec->entityType, $spec->key)
            : $registry->getGeneric($spec->key);
    }

    /**
     * The invocation's entity identity rides the dispatch CONTEXT, mirroring the entity://
     * link path's id segment — seedEntityId re-seeds it for actions that declare it, and
     * filterParams semantics stay identical across transports.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function dispatchContext(ActionInvocation $invocation, array $context): array
    {
        if ($invocation->entityType !== null) {
            $context['entity_type'] = $invocation->entityType;
            $context['entity_id'] = $invocation->entityId;
        }

        return $context;
    }

    private function pendingContent(AiActionInterface $action): string
    {
        return (string) json_encode([
            'status' => 'pending_approval',
            'detail' => 'Proposed: ' . $action->label() . '. A confirmation card is shown to the user.'
                . ' The action has NOT been performed and will only run if the user confirms.'
                . ' Do not state or imply that it happened.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{content: string, is_error: bool} */
    private function error(string $message): array
    {
        return ['content' => $message, 'is_error' => true];
    }

    private function hasAccumulated(array $state): bool
    {
        return $state['texts'] !== [] || $state['summaries'] !== [] || $state['invocations'] !== [];
    }

    private function composedAnswer(array $state): string
    {
        return trim(implode("\n\n", array_merge($state['texts'], array_values(array_unique($state['summaries'])))));
    }

    private function finish(string $answer, array $state): ToolLoopResult
    {
        return new ToolLoopResult($answer, array_values($state['invocations']), [
            'channel' => 'native',
            'rounds' => $state['rounds'],
            'tool_calls' => $state['received'],
            'degraded' => $state['degraded'],
        ]);
    }

    private function maxRounds(): int
    {
        return max(1, $this->toolRounds ?? $this->cfgInt('ai.actions.tool_rounds', 2));
    }

    private function callBudget(): int
    {
        return max(1, $this->maxToolCalls ?? $this->cfgInt('ai.actions.max_tool_calls', 5));
    }

    /**
     * Container-tolerant config read (same guard as AiActionRegistry::cfg) so pure unit
     * contexts fall back to the documented defaults.
     */
    private function cfgInt(string $key, int $default): int
    {
        if (function_exists('app') && function_exists('config')) {
            try {
                if (app()->bound('config')) {
                    $value = config($key, $default);
                    if (is_int($value) && $value > 0) {
                        return $value;
                    }
                    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                        return (int) $value;
                    }
                }
            } catch (\Throwable $e) {
                // Container/config not available — fall through to default.
            }
        }

        return $default;
    }
}
