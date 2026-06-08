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

        $output = $this->header('CURRENT USER CONTEXT. USE IT TO HAVE CURRENT TEAM AND CURRENT USER CONTEXT');
        $output .= "Current user name: " . ($isAuthenticated ? auth()->user()->name : 'Guest') . "\n";
        $output .= "Current user email: " . ($isAuthenticated ? auth()->user()->email : 'N/A') . "\n";
        $output .= "Current user ID: " . ($isAuthenticated ? auth()->id() : 'N/A') . "\n";
        $output .= "Current team ID: " . ($isAuthenticated ? (safeCurrentTeam()?->id ?? 'N/A') : 'N/A') . "\n";
        $output .= "Current team name: " . ($isAuthenticated ? (safeCurrentTeam()?->team_name ?? 'N/A') : 'N/A') . "\n\n";

        // Include user entity paths when QU detected possessive language
        $isPossessive = $context->qu->isPossessive;
        if ($isAuthenticated && $isPossessive) {
            $output .= $this->formatUserEntityPaths();
        }

        return $output;
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
