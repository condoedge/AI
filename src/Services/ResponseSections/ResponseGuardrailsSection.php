<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
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

        // Host-specific domain statement (config 'ai.guardrails.domain_scope') so the decline
        // can NAME what is in scope for this software instead of only the project name.
        $scope = trim((string) config('ai.guardrails.domain_scope', ''));
        if ($scope !== '') {
            $lines[] = "   The domain of {$project} is: {$scope} Anything outside it is out-of-scope per rule 2.";
        }

        return implode("\n", $lines) . "\n\n";
    }
}
