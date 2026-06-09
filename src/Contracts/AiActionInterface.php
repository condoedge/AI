<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * AiAction — a model-agnostic, reusable unit of "doing something" the assistant can offer.
 *
 * This is the typed, registry-backed evolution of the legacy closure entries in
 * config('ai.entity_actions') / config('ai.generic_actions'). It deliberately stays
 * provider-agnostic: it carries enough metadata to (a) advertise the action to the LLM
 * via a native tool spec / the markdown-link grammar, (b) validate + filter the params parsed
 * out of an action:// / entity:// link, (c) gate mutating actions behind a server-side
 * authorization re-check, and (d) return typed DATA + INTENT (never a Kompo element) that one
 * presenter turns into a link, table, download, or approval card.
 *
 * Backward compatibility: today's array/closure config entries are wrapped automatically
 * in {@see \Condoedge\Ai\Services\Actions\ConfigAiAction}, which implements this interface
 * with SAFE DEFAULTS (no mutation, authorize=true, empty schema). Nothing in config is
 * required — absent keys never throw.
 */
interface AiActionInterface
{
    /**
     * Stable machine key used in links and as the native tool name: action://{key} or
     * entity://Type/id/{key}. snake_case.
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
     * Short description of what the action does, advertised to the LLM (tool description AND
     * markdown-fallback awareness).
     */
    public function description(): string;

    /**
     * Typed schema for the params this action accepts. This IS the provider tool/function
     * schema — not a second description of params.
     *
     * Shape: ['param_name' => ['type' => 'string|int|float|bool|array', 'default' => mixed,
     *                          'required' => bool, 'label' => string]].
     * Only params declared here survive parsing; everything else is DROPPED (security).
     * An empty schema means the action takes no params.
     *
     * @return array<string, array<string, mixed>>
     */
    public function inputSchema(): array;

    /**
     * Whether executing this action mutates state. true => the dispatcher defers behind an
     * approval gate + a fresh server-side re-authorize before {@see handle()} runs. Read-only
     * actions return false and render/execute inline.
     */
    public function mutates(): bool;

    /**
     * Server-side authorization gate. Re-checked at render time AND again right before a
     * mutating action executes. Must NEVER be trusted from the client.
     *
     * @param mixed $user  The acting user (nullable for unauthenticated contexts).
     * @param array<string, mixed> $input  The validated input params.
     */
    public function authorize(mixed $user, array $input): bool;

    /**
     * Execute the action. Typed in (filtered $input + {@see ActionContext}) AND typed out
     * ({@see AiActionResult}). Returns DATA + INTENT (an ActionKind + payload), never a Kompo
     * element. For mutating actions this only runs AFTER approval + a fresh authorize() pass.
     *
     * @param array<string, mixed> $input  Validated params (filtered by inputSchema()).
     */
    public function handle(array $input, ActionContext $context): AiActionResult;
}
