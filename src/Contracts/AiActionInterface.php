<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * AiAction — a model-agnostic, reusable unit of "doing something" the assistant can offer.
 *
 * This is the typed, registry-backed evolution of the legacy closure entries in
 * config('ai.entity_actions') / config('ai.generic_actions'). It deliberately stays
 * provider-agnostic: it carries enough metadata to (a) advertise the action to the LLM
 * via the existing markdown-link grammar, (b) validate + filter the params parsed out of
 * an action:// / entity:// link, (c) gate mutating actions behind a server-side
 * authorization re-check, and (d) render a Kompo element (link, modal, table, export,
 * button, or approval card).
 *
 * Backward compatibility: today's array/closure config entries are wrapped automatically
 * in {@see \Condoedge\Ai\Services\Actions\ConfigAiAction}, which implements this interface
 * with SAFE DEFAULTS (no approval, authorize=true, empty schema). Nothing in config is
 * required — absent keys never throw.
 */
interface AiActionInterface
{
    /**
     * Stable machine key used in links: action://{key} or entity://Type/id/{key}.
     */
    public function key(): string;

    /**
     * Human-readable label shown to the LLM and (optionally) the user.
     */
    public function label(): string;

    /**
     * Natural-language aliases the user might say ("profile link", "open invoice"…).
     *
     * @return string[]
     */
    public function aliases(): array;

    /**
     * Short description of what the action does, advertised to the LLM.
     */
    public function description(): string;

    /**
     * Typed schema for the params this action accepts in its link query string.
     *
     * Shape: ['param_name' => ['type' => 'string|int|float|bool', 'default' => mixed,
     *                          'required' => bool, 'label' => string]].
     * Only params declared here survive parsing; everything else is DROPPED (security).
     * An empty schema means the action takes no params.
     *
     * @return array<string, array<string, mixed>>
     */
    public function inputSchema(): array;

    /**
     * How this action should be rendered.
     *
     * One of: link | modal | table | export | button | approval.
     */
    public function presentation(): string;

    /**
     * Whether executing this action mutates state and therefore must be confirmed +
     * re-authorized server-side before {@see handle()} runs. Read-only actions return false
     * and render/execute inline.
     */
    public function requiresApproval(): bool;

    /**
     * Server-side authorization gate. Re-checked at render time AND again right before a
     * mutating action executes. Must NEVER be trusted from the client.
     *
     * @param mixed $user  The acting user (nullable for unauthenticated contexts).
     * @param array<string, mixed> $input  The validated input params.
     */
    public function authorize(mixed $user, array $input): bool;

    /**
     * Execute the action. For read-only/presentational actions this typically returns a
     * Kompo element (or an AiActionResult wrapping one). For mutating actions, this only
     * runs AFTER approval + a fresh authorize() pass.
     *
     * @param array<string, mixed> $input    Validated params (filtered by inputSchema()).
     * @param array<string, mixed> $context  Ambient context (entity_id, entity_type, text, user…).
     * @return mixed Kompo element, AiActionResult, or any renderable.
     */
    public function handle(array $input, array $context): mixed;
}
