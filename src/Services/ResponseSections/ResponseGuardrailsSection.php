<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\ActionToolChannel;
use Condoedge\Ai\Services\AssistantIdentity;

/**
 * ResponseGuardrailsSection
 *
 * Behavioral guardrails delivered in the SYSTEM role, after the security policy,
 * so they reinforce (never precede) the non-negotiable framing. All are model-agnostic
 * (no domain/English word-list — the action vocabulary is left to the model and the
 * decline is scoped by the configured project name) and token-lean.
 *
 *  1. ANTI-OVER-CLAIM: when no mutating action actually executed (the answer is over a
 *     read-only or empty result), the assistant must never state that it performed an
 *     action it did not perform. It must instead OFFER the action and let the user
 *     confirm it — matching the deferred, approval-gated action design.
 *
 *  2. OUT-OF-DOMAIN SCOPING: an off-topic question with no relevant data or files is
 *     declined as outside what the assistant can help with for {project}, keeping the
 *     persona — never framed as a database "no results, try rephrasing" miss.
 *
 *  3. NO LINK GRAMMAR (native tool channel only): with real tool definitions in hand the
 *     model must never write action:// / entity:// links — the backup behind the render-side
 *     fingerprint dedupe against a history-imitating model. Emitted ONLY when the channel
 *     option says Native, so legacy/markdown/abstention prompts stay byte-identical.
 *
 * Priority: 1100 (immediately after PrivacyAndSecurityGuidelinesSection at 1000).
 */
class ResponseGuardrailsSection extends BaseResponseSection
{
    protected string $name = 'response_guardrails';
    protected int $priority = 1100;

    public function role(): string
    {
        return 'system';
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $project = AssistantIdentity::project();

        $lines = [
            $this->header('RESPONSE GUARDRAILS (non-negotiable)'),
            "1. Never claim you performed an action you did not actually perform. If the result is read-only or empty and no action ran, do NOT state that anything was sent, created, updated, scheduled, or delivered. Instead, offer to perform the action and ask the user to confirm it.",
            "2. If the question is out-of-scope — off-topic, or with no relevant data or files for {$project} — politely say it is outside what you can help with for {$project} and, where useful, point to what you can do. Keep your persona: do not present an out-of-scope question as a database miss and do not tell the user to rephrase.",
        ];

        if (($options[ActionToolChannel::OPTION] ?? null) === ActionToolChannel::Native) {
            $lines[] = "3. Never write action:// or entity:// links in your response — your tools are the only way to propose actions.";
        }

        return implode("\n", $lines) . "\n\n";
    }
}
