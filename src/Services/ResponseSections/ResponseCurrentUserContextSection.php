<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Kompo\Support\WelcomeSuggestions;
use Condoedge\Ai\Services\Response\PiiMasker;
use Condoedge\Ai\Support\Audiences;

/**
 * ResponseCurrentUserContextSection
 *
 * Response-stage twin of the query-side CurrentUserContextSection: the SAME identity
 * facts (auth user name, PII-gated email, user ID, current team ID/name — sourced
 * from auth() + safeCurrentTeam(), exactly like the query-generation prompt) are
 * rendered for the ANSWERING LLM, which otherwise has no idea who it is talking to.
 *
 * Without this the answer model can tell a team's own lead to "reach the team" at
 * her own email address. The INSIDER-VOICE rule below closes that class of failure
 * generically (no host-specific strings).
 *
 * Email gating is identical to the query side: config('ai.prompt.include_user_email')
 * OR a possessive question (the user asking about their own data).
 *
 * Priority: 25 (after project context 20 / page description 22, before the original
 * question at 30 — the model learns who is asking before it reads the question).
 */
class ResponseCurrentUserContextSection extends BaseResponseSection
{
    protected string $name = 'current_user_context';
    protected int $priority = 25;

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return $this->isAuthenticated();
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        if (!$this->isAuthenticated()) {
            return '';
        }

        $user = auth()->user();
        $currentTeam = $this->currentTeam();
        $teamName = $this->teamDisplayName($currentTeam);

        $output = $this->header('CURRENT USER CONTEXT');
        $output .= "You are answering THIS user — they are the person asking the question:\n";
        $output .= '- Name: ' . ($user?->name ?? 'N/A') . "\n";

        // PII minimization — same gate as the query-generation side: the email ships
        // only when explicitly enabled by config, or when the question is about the
        // user's own data (so the model can recognize the user's own records).
        // When email is an identifying field (ai.access_control.identifying_fields)
        // it ships as the turn's token — the SAME token the user's own rows carry —
        // so the model can still recognise the user's records without the value.
        if ($this->emailEnabled() || $context->qu->isPossessive) {
            $email = $user?->email ?? null;
            $output .= '- Email: ' . (is_string($email) && $email !== ''
                ? (string) PiiMasker::for($context)->maskField('email', $email)
                : 'N/A') . "\n";
        }

        $output .= '- User ID: ' . (auth()->id() ?? 'N/A') . "\n";
        $output .= '- Current team ID: ' . ($currentTeam?->id ?? 'N/A') . "\n";
        $output .= '- Current team name: ' . ($teamName ?? 'N/A') . "\n\n";

        // The host's audiences (ai.audiences): who this user is to the organisation. One
        // that is not its staff (a buyer, a supplier) is not spoken to from inside it.
        $role = Audiences::promptBlock($user);
        if ($role !== '') {
            $output .= $role . "\n\n";
        }
        if ($context->askerFacts !== []) {
            $output .= "WHAT THE APP SHOWS THIS USER ABOUT THEIR OWN RECORDS (current; for these topics they come before any document):\n- "
                . implode("\n- ", $context->askerFacts) . "\n\n";
        }
        if ($context->dataOutOfReach) {
            $output .= "THIS USER'S ACCESS DOES NOT REACH THE DATA THIS QUESTION ASKS FOR. Say in one sentence that their access does not "
                . "show it; never give, estimate or compute a figure. "
                . "A help passage may still say where the app shows it or who can answer. A question that only asks how to "
                . "do something or where to find something is answered normally.\n\n";
        }
        // The tasks (ai.tasks) this user may start: only their button starts one, never the answer.
        $tasks = WelcomeSuggestions::tasks($user);
        if ($tasks !== []) {
            $output .= 'TASKS THIS USER CAN START: "' . implode('", "', $tasks) . '". The assistant does one with the user only once they click '
                . "its button, which comes with the answer when it applies; you do not run it, and never say a task was done.

";
        }
        if (!Audiences::insiderVoice($user)) {
            return $output;
        }

        $output .= "INSIDER VOICE (how to speak to this user):\n";
        if ($teamName !== null && $teamName !== '') {
            $output .= "- The current user is a member of \"{$teamName}\". Answer from INSIDE their organization.\n";
        } else {
            $output .= "- Answer from INSIDE the user's own organization.\n";
        }
        $output .= "- Never refer the user to their own team, management, or organization as if it were an external party they should contact.\n";
        $output .= "- Never present the user's own contact details (their own name, email, or phone appearing in the data) as the way to \"reach\" or \"contact\" their team — they ARE that person. If a contact in the data resolves to the current user themself, say so naturally (e.g. \"that's you\") instead of telling them to contact themselves.\n\n";

        return $output;
    }

    /**
     * The identity VALUES this section gives the model as words — the user's own name and the
     * current team's name, exactly as {@see format()} prints them (never the email, which is
     * PII-gated, nor an id). Code treats them as KNOWN when it judges whether a lead only
     * restates (eval p20-A roster: "Here are the members of your team, Acme Gestion, along with
     * their roles:" — DataAnswerPlan::knownIdentity()). [] without an authenticated user.
     *
     * @return list<string>
     */
    public function identityValues(): array
    {
        if (!$this->isAuthenticated()) {
            return [];
        }

        try {
            $name = auth()->user()?->name ?? null;
        } catch (\Throwable $e) {
            $name = null;
        }

        return array_values(array_filter(
            [$name, $this->teamDisplayName($this->currentTeam())],
            static fn ($value): bool => is_string($value) && trim($value) !== ''
        ));
    }

    /**
     * Container-tolerant auth check (mirrors the query-side section's gate).
     */
    private function isAuthenticated(): bool
    {
        try {
            return function_exists('auth') && auth()->check();
        } catch (\Throwable $e) {
            return false; // no auth context (console/queue) — render nothing
        }
    }

    /**
     * The team's display name, tolerant of the host's column naming — the SAME
     * team_name-then-name fallback as the query-side CurrentUserContextSection:
     * some hosts expose team_name, others (Jetstream-style teams) name. Reading
     * only team_name rendered "Current team name: N/A" although the team exists.
     */
    private function teamDisplayName(mixed $team): ?string
    {
        if ($team === null) {
            return null;
        }

        foreach (['team_name', 'name'] as $attribute) {
            try {
                $value = $team->{$attribute} ?? null;
            } catch (\Throwable $e) {
                $value = null;
            }

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * SAME team source the query-generation prompt uses (safeCurrentTeam()),
     * guarded for host-less contexts where the helper is not loaded.
     */
    private function currentTeam(): mixed
    {
        if (!function_exists('safeCurrentTeam')) {
            return null;
        }

        try {
            return safeCurrentTeam();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether the user's email may be included in the prompt — the SAME config key
     * the query-side CurrentUserContextSection reads. Default OFF (safe-by-default
     * when pointed at a third-party LLM). Container-tolerant for unit tests.
     */
    private function emailEnabled(): bool
    {
        if (function_exists('app') && app()->bound('config')) {
            return (bool) config('ai.prompt.include_user_email', false);
        }

        return false;
    }
}
