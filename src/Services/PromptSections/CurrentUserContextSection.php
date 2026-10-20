<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Retrieval\EntitySelectionPruner;

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

        // Task 5.11: render only what the selected labels can use. $selected is null
        // when the prompt carries no projected schema (render everything, as before).
        $selected = $this->selectedLabels($context);

        if ($isAuthenticated) {
            $output .= $this->formatIdentityAnchors($currentUserId, $currentTeamId);
            $profile = $this->profileLabel();
            if ($profile !== null && ($selected === null || isset($selected[strtolower($profile)]))) {
                $output .= $this->formatContactDisambiguation($profile);
            }
        }

        // PERSONAL possession ("my unit", "do I have…") gets the user's ownership paths.
        // COLLECTIVE possession ("our meetings", "we owe") is the TEAM's data: it gets only
        // the team-scope guidance — handing it the personal paths made the generator answer
        // "no meetings linked to you" for "When is our next meeting?" (eval p16). The
        // personal/collective judgment is QU's verified possessive_scope, not left to the
        // Cypher model.
        if ($isAuthenticated && $context->qu->isPersonalPossessive()) {
            $output .= $this->formatUserEntityPaths($this->pathLabels($context, $selected));
        } elseif ($isAuthenticated && $context->qu->isCollectivePossessive()) {
            $output .= "COLLECTIVE POSSESSIVE: the question speaks for the user's organization (\"our …\", \"we …\"). "
                . "It is about the TEAM'S data, not this person's own records: anchor the query on the current team scope "
                . "(the Current team ID above / the \$ai_team_ids tenant filter) over the team-level entities, and do NOT "
                . "route it through the user's personal ownership.

";
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
     * The host's PROFILE entity: the label the configured ai.user_entity_paths start
     * from, bound to the current user id (e.g. (c:Contact {user_id: $userId}) → Contact).
     * The most frequent such label, identity labels excluded; null when no path starts
     * from a user-bound node (then there is no profile entity to disambiguate).
     */
    private function profileLabel(): ?string
    {
        $paths = config('ai.user_entity_paths', []);
        if (!is_array($paths) || $paths === []) {
            return null;
        }

        $identity = config('ai.semantic_context.always_include_identity_labels', ['User', 'Team']);
        $identity = array_fill_keys(array_map('strtolower', is_array($identity) ? array_filter($identity, 'is_string') : []), true);

        $counts = [];
        foreach ($paths as $path) {
            $cypher = is_array($path) ? ($path['cypher'] ?? null) : null;
            if (!is_string($cypher)
                || !preg_match('/\(\s*\w*\s*:\s*`?([A-Za-z_][A-Za-z0-9_]*)`?\s*\{[^}]*\$userId[^}]*\}\s*\)/', $cypher, $m)
                || isset($identity[strtolower($m[1])])) {
                continue;
            }
            $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
        }
        if ($counts === []) {
            return null;
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * The profile entity's name ("Contact") is both a normal word and a domain label.
     * Own-profile wording should not become a broad profile-entity query unless the
     * user clearly asks for those records.
     */
    private function formatContactDisambiguation(string $profile): string
    {
        $output = "CONTACT WORDING DISAMBIGUATION:\n";
        $output .= "- Own-profile wording in any language (for example \"my contact information\", \"my profile\", \"my information\", \"my email\", \"my name\", or \"my team\") means this authenticated user and active team context.\n";
        $output .= "- Do not use the {$profile} entity for those own-profile phrases unless the user explicitly asks for {$profile} records, a list of them, another person's record, or a configured ownership path requires the current user's profile row.\n\n";

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
     * Lower-cased labels of the projected schema (what the schema cards show), or
     * null when the context carries none.
     *
     * @return array<string, true>|null
     */
    private function selectedLabels(PipelineContext $context): ?array
    {
        $labels = array_filter((array) ($context->graphSchema['labels'] ?? []), fn ($l) => is_string($l) && $l !== '');
        if ($labels === []) {
            return null;
        }

        return array_fill_keys(array_map('strtolower', $labels), true);
    }

    /**
     * The labels whose ownership paths may render: the selected labels minus those the
     * entity-selection pruner kept only to join or scope the seeds (identity / tenant /
     * closure / ownership) — a label on BoardMember's path is not what "my X" asks for.
     *
     * @param array<string, true>|null $selected
     * @return array<string, true>|null
     */
    private function pathLabels(PipelineContext $context, ?array $selected): ?array
    {
        $tiers = (array) ($context->selectionInfo['selection_tiers'] ?? []);
        if ($selected === null || $tiers === []) {
            return $selected;
        }

        $support = [
            EntitySelectionPruner::TIER_IDENTITY,
            EntitySelectionPruner::TIER_TENANT,
            EntitySelectionPruner::TIER_CLOSURE,
            EntitySelectionPruner::TIER_OWNERSHIP,
        ];
        foreach ($tiers as $label => $tier) {
            if (is_string($label) && in_array($tier, $support, true)) {
                unset($selected[strtolower($label)]);
            }
        }

        return $selected;
    }

    /**
     * Format user entity paths that teach the LLM how to find "my X" entities —
     * only the paths whose target label is selected (all when $selected is null).
     *
     * @param array<string, true>|null $selected
     */
    private function formatUserEntityPaths(?array $selected = null): string
    {
        $paths = config('ai.user_entity_paths', []);

        if (!is_array($paths) || empty($paths)) {
            return '';
        }

        if ($selected !== null) {
            $paths = array_filter(
                $paths,
                fn ($path, $label) => is_string($label) && isset($selected[strtolower($label)]),
                ARRAY_FILTER_USE_BOTH
            );
            if ($paths === []) {
                return '';
            }
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
