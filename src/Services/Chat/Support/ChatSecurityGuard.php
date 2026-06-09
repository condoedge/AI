<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Support;

use Condoedge\Ai\Services\Security\InputSanitizer;
use Illuminate\Support\Facades\Log;

/**
 * Pure security gate for chat turns. Knows nothing about Kompo, request()/response(),
 * persistence, or translation strings — it only enforces two rules and reports the result.
 *
 * Rule 1 (precondition): a chat turn MUST have a user unless the caller explicitly opted
 *         into anonymous access. Violations are a programmer/precondition error and throw.
 * Rule 2 (decline-and-continue): if the input looks like a prompt-injection attempt, we do
 *         NOT brick the conversation. assertSafe() returns false so the use case can turn it
 *         into a per-request refusal (ChatTurnResult::failure) and the user can keep chatting
 *         on their next message.
 */
class ChatSecurityGuard
{
    public function __construct(
        private readonly InputSanitizer $inputSanitizer,
    ) {
    }

    /**
     * Assert the turn is safe to process.
     *
     * @param string $question       The raw user question.
     * @param mixed  $user           The acting user (Authenticatable|null).
     * @param bool   $allowAnonymous Whether running without a user is explicitly permitted.
     * @return bool                  True when safe to proceed; false when injection was detected
     *                               (caller should refuse this single request and continue).
     *
     * @throws \InvalidArgumentException When no user is present and anonymous access was not allowed.
     */
    public function assertSafe(string $question, mixed $user, bool $allowAnonymous = false): bool
    {
        // Rule 1 — mandatory-user precondition.
        if ($user === null && !$allowAnonymous) {
            throw new \InvalidArgumentException(
                'User context required for AI queries. Pass user in options or set allow_anonymous=true for public queries.'
            );
        }

        // Rule 2 — prompt-injection scan (decline-and-continue, do not brick the conversation).
        $analysis = $this->inputSanitizer->analyze($question);

        if ($analysis['has_injection_risk'] ?? false) {
            Log::warning('Prompt injection attempt blocked', [
                'user_id' => is_object($user) ? ($user->id ?? null) : null,
                'risk_level' => $analysis['risk_level'] ?? null,
            ]);

            return false;
        }

        return true;
    }
}
