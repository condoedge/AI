<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\DTOs\PipelineContext;

/**
 * ActionToolGate
 *
 * THE single decision point for how actions are offered to the response model on a turn:
 * native provider tools, the legacy markdown link grammar, or nothing. decide() is consulted
 * once at the top of ResponseGenerator::generate(); the abstention path never consults it,
 * so a model with nothing to ground on is never handed levers.
 *
 *   None     iff the requested style is terse (the markdown section's own suppression rule)
 *   Native   iff config('ai.actions.native_tools') is on (default OFF — the kill switch)
 *            AND the resolved response_generation provider implements ToolCallingLlmInterface
 *            AND the relevance-scoped tool set is non-empty
 *   Markdown otherwise — the pre-existing path, byte-identical
 *
 * The checks run cheapest-first: the registry walk + spec construction is paid ONLY after the
 * style/kill-switch/capability checks pass, so a deployment with native_tools off (the
 * default, 100% of turns) never builds a spec.
 *
 * When native_tools is on but the resolved provider lacks the capability, the gate degrades
 * to Markdown LOUDLY (one warning per turn): per-environment capability drift — a
 * tools-capable default beside a tool-less response_generation role — must never be silent.
 */
final class ActionToolGate
{
    /** Styles that want just the answer; every action channel is suppressed for them. */
    public const TERSE_STYLES = ['minimal', 'concise'];

    public function __construct(
        private AiActionRegistry $registry,
    ) {
    }

    /**
     * The relevance-scoped tool set for this turn — never global. Entity tools only for the
     * focused entity and the entity types actually present in the result rows; knowledge
     * turns with no entities in scope naturally yield generic tools alone.
     *
     * @return ActionToolSpec[]
     */
    public function specsFor(PipelineContext $context): array
    {
        return ActionToolSpec::fromRelevantActions(
            $this->registry->relevantActions($this->focusedEntity($context), $this->resultTypes($context))
        );
    }

    /**
     * THE one decision for a turn: channel + the specs that ride it. Specs are built lazily —
     * only a kill-switch-on, tool-capable turn pays specsFor(); every other outcome returns
     * with no registry walk at all.
     *
     * @param array<string, mixed> $options  Section options (style, ...).
     * @return array{0: ActionToolChannel, 1: ActionToolSpec[]}
     */
    public function decide(LlmProviderInterface $llm, PipelineContext $context, array $options = []): array
    {
        return $this->resolve($llm, fn (): array => $this->specsFor($context), $options);
    }

    /**
     * @param ActionToolSpec[] $toolSpecs
     * @param array<string, mixed> $options  Section options (style, ...).
     */
    public function channel(LlmProviderInterface $llm, array $toolSpecs, array $options = []): ActionToolChannel
    {
        return $this->resolve($llm, static fn (): array => $toolSpecs, $options)[0];
    }

    /**
     * The single decision body, cheapest checks first. $buildSpecs is a closure on purpose:
     * the spec walk only runs once style, kill switch and capability have all passed.
     *
     * @param \Closure(): ActionToolSpec[] $buildSpecs
     * @return array{0: ActionToolChannel, 1: ActionToolSpec[]}
     */
    private function resolve(LlmProviderInterface $llm, \Closure $buildSpecs, array $options): array
    {
        if ($this->isTerseStyle($options)) {
            return [ActionToolChannel::None, []];
        }

        if (!(bool) $this->cfg('ai.actions.native_tools', false)) {
            return [ActionToolChannel::Markdown, []];
        }

        if (!$llm instanceof ToolCallingLlmInterface) {
            $this->warnCapabilityDrift($llm);

            return [ActionToolChannel::Markdown, []];
        }

        $toolSpecs = $buildSpecs();
        if ($toolSpecs === []) {
            return [ActionToolChannel::Markdown, []];
        }

        return [ActionToolChannel::Native, $toolSpecs];
    }

    private function isTerseStyle(array $options): bool
    {
        $style = $options['style'] ?? $this->cfg('ai.response_generation.default_style', 'friendly');

        return in_array($style, self::TERSE_STYLES, true);
    }

    private function focusedEntity(PipelineContext $context): ?string
    {
        $focused = $context->conversationContext['focused_entity'] ?? null;

        return is_string($focused) && $focused !== '' ? $focused : null;
    }

    /**
     * Entity labels in scope for this turn: the entities the conversation mentions plus the
     * entity_type enrichment on the current (or, when empty, last) result rows — the same
     * data precedence the markdown section uses. Structural only, no semantic guessing.
     *
     * @return string[]
     */
    private function resultTypes(PipelineContext $context): array
    {
        $types = [];

        foreach ((array) ($context->conversationContext['mentioned_entities'] ?? []) as $type) {
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            }
        }

        $rows = $context->executionResult->data;
        if ($rows === []) {
            $rows = (array) ($context->conversationContext['last_result_sample'] ?? []);
        }
        foreach ($rows as $row) {
            $type = is_array($row) ? ($row['entity_type'] ?? null) : null;
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    private function warnCapabilityDrift(LlmProviderInterface $llm): void
    {
        try {
            \Log::warning(
                'ai.actions.native_tools is enabled but the resolved response_generation provider does not '
                . 'support tool calling — falling back to the markdown action channel.',
                ['provider' => get_class($llm)]
            );
        } catch (\Throwable $e) {
            // No logger bound — degrading to Markdown is already the safe behavior.
        }
    }

    /**
     * Container-tolerant config read (same guard as AiActionRegistry::cfg) so pure unit
     * contexts fall back to the documented defaults.
     */
    private function cfg(string $key, mixed $default): mixed
    {
        if (function_exists('app') && function_exists('config')) {
            try {
                if (app()->bound('config')) {
                    return config($key, $default);
                }
            } catch (\Throwable $e) {
                // Container/config not available — fall through to default.
            }
        }

        return $default;
    }
}
