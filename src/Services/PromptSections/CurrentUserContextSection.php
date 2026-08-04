<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

class CurrentUserContextSection extends BasePromptSection
{
    protected string $name = 'current_user_context';
    protected int $priority = 17;

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        if (auth()->check()) {
            return true;
        }

        return false;
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $isAuthenticated = auth()->check();
        $isPossessive = $context->qu->isPossessive;
        $user = $isAuthenticated ? auth()->user() : null;
        $currentTeam = $isAuthenticated ? safeCurrentTeam() : null;
        $currentUserId = $isAuthenticated ? auth()->id() : 'N/A';
        $currentTeamId = $isAuthenticated ? ($currentTeam?->id ?? 'N/A') : 'N/A';
        $currentTeamName = $isAuthenticated ? ($this->teamDisplayName($currentTeam) ?? 'N/A') : 'N/A';

        $output = $this->header('CURRENT USER CONTEXT. USE IT TO HAVE CURRENT TEAM AND CURRENT USER CONTEXT');
        $output .= "Current user name: " . ($isAuthenticated ? ($user?->name ?? 'N/A') : 'Guest') . "\n";

        // PII minimization: the user's email is real personal data shipped to a third-party
        // LLM. Include it only when explicitly enabled by config, or when the question is
        // about the user's own data (ownership resolution may rely on it). User ID + team ID
        // already cover the documented "my X" ownership paths.
        if ($isAuthenticated && ($this->emailEnabled() || $isPossessive)) {
            $output .= "Current user email: " . ($user?->email ?? 'N/A') . "\n";
        }

        $output .= "Current user ID: " . $currentUserId . "\n";
        $output .= "Current team ID: " . $currentTeamId . "\n";
        $output .= "Current team name: " . $currentTeamName . "\n\n";

        if ($isAuthenticated) {
            $output .= $this->formatIdentityAnchors($currentUserId, $currentTeamId);
            $output .= $this->formatContactDisambiguation();
        }

        // Include user entity paths when QU detected possessive language
        if ($isAuthenticated && $isPossessive) {
            $output .= $this->formatUserEntityPaths();
        }

        return $output;
    }

    /**
     * Make the current auth/team nodes explicit as graph anchors. The schema
     * retriever also keeps these labels available, but the actual row identity
     * belongs here because it comes from auth(), not from random graph examples.
     */
    private function formatIdentityAnchors(int|string $currentUserId, int|string $currentTeamId): string
    {
        $output = "CURRENT IDENTITY ENTITY ANCHORS:\n";
        $output .= "- The authenticated user maps to User.id = {$currentUserId} when the User label exists.\n";
        $output .= "- The active team maps to Team.id = {$currentTeamId} when the Team label exists.\n";
        $output .= "- If a User node exposes current_team_id and no exact User-to-Team relationship pattern is shown, use User.current_team_id = {$currentTeamId} instead of inventing a relationship.\n";
        $output .= "- Keep these User and Team anchors available even when the main request targets another entity.\n\n";

        return $output;
    }

    /**
     * "Contact" is both a normal word and often a domain entity label. Own-profile
     * wording should not become a broad Contact query unless the user clearly asks
     * for contact records.
     */
    private function formatContactDisambiguation(): string
    {
        $output = "CONTACT WORDING DISAMBIGUATION:\n";
        $output .= "- Own-profile wording in any language (for example \"my contact information\", \"my profile\", \"my information\", \"my email\", \"my name\", or \"my team\") means this authenticated user and active team context.\n";
        $output .= "- Do not use the Contact entity for those own-profile phrases unless the user explicitly asks for contact records, a contacts list, another person's contact, or a configured ownership path requires the current user's profile row.\n\n";

        return $output;
    }

    /**
     * The team's display name, tolerant of the host's column naming: some hosts
     * expose it as team_name, others (e.g. Jetstream-style teams) as name. Reading
     * only team_name rendered "Current team name: N/A" for hosts whose Team model
     * has a name column — the model's magic getter returns null for the absent
     * team_name attribute, so the ?? fell through even though the team exists.
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
     * Whether the user's email may be included in the prompt. Default OFF — the package
     * is safe-by-default when pointed at a third-party LLM. Container-tolerant for unit tests.
     */
    private function emailEnabled(): bool
    {
        if (function_exists('app') && app()->bound('config')) {
            return (bool) config('ai.prompt.include_user_email', false);
        }

        return false;
    }

    /**
     * Format user entity paths that teach the LLM how to find "my X" entities.
     */
    private function formatUserEntityPaths(): string
    {
        $paths = config('ai.user_entity_paths', []);

        if (empty($paths)) {
            return '';
        }

        $output = "IMPORTANT - USER OWNERSHIP PATHS (PERSONAL possessives ONLY):\n";
        $output .= "PERSONAL vs COLLECTIVE possessives — decide FIRST which one the question uses:\n";
        $output .= "- PERSONAL possessives (\"my ...\", \"mine\", the user's own individual records) refer to this ONE person's own data: use the personal ownership paths below.\n";
        $output .= "- COLLECTIVE possessives (\"our ...\", \"we\", \"us\") from a user speaking for their organization refer to the TEAM'S data, NOT this person's personal records: anchor the query on the current team scope (the Current team ID above / the \$ai_team_ids tenant filter) over the team-level entities, and do NOT route it through the personal paths below. A staff member or team lead asking about \"our X\" wants the organization's X even if they personally own none.\n";
        $output .= "- If a personal path returns nothing but the question is about something the organization owns or runs, re-anchor on the team scope instead of concluding the data does not exist.\n\n";
        $output .= "For PERSONAL possessives, use these Cypher patterns to find the user's own entities.\n";
        $output .= "Replace \$userId with the Current user ID shown above.\n\n";

        foreach ($paths as $entityLabel => $pathConfig) {
            $description = $pathConfig['description'] ?? '';
            $cypher = $pathConfig['cypher'] ?? '';

            if (empty($cypher)) {
                continue;
            }

            $output .= "To find the current user's {$entityLabel}:\n";
            if ($description) {
                $output .= "  Description: {$description}\n";
            }
            $output .= "  Cypher: {$cypher}\n\n";
        }

        return $output;
    }
}
