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

        $output = $this->header('CURRENT USER CONTEXT. USE IT TO HAVE CURRENT TEAM AND CURRENT USER CONTEXT');
        $output .= "Current user name: " . ($isAuthenticated ? auth()->user()->name : 'Guest') . "\n";

        // PII minimization: the user's email is real personal data shipped to a third-party
        // LLM. Include it only when explicitly enabled by config, or when the question is
        // about the user's own data (ownership resolution may rely on it). User ID + team ID
        // already cover the documented "my X" ownership paths.
        if ($isAuthenticated && ($this->emailEnabled() || $isPossessive)) {
            $output .= "Current user email: " . auth()->user()->email . "\n";
        }

        $output .= "Current user ID: " . ($isAuthenticated ? auth()->id() : 'N/A') . "\n";
        $output .= "Current team ID: " . ($isAuthenticated ? (safeCurrentTeam()?->id ?? 'N/A') : 'N/A') . "\n";
        $output .= "Current team name: " . ($isAuthenticated ? (safeCurrentTeam()?->team_name ?? 'N/A') : 'N/A') . "\n\n";

        // Include user entity paths when QU detected possessive language
        if ($isAuthenticated && $isPossessive) {
            $output .= $this->formatUserEntityPaths();
        }

        return $output;
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

        $output = "IMPORTANT - USER OWNERSHIP PATHS:\n";
        $output .= "When the user says \"my\", \"mine\", or refers to their own data, use these Cypher patterns to find their entities.\n";
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
