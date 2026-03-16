<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Agents\ResolvedAgent;
use Condoedge\Ai\Security\SecurityAxesRegistry;

class CurrentUserContextSection extends BasePromptSection
{
    protected string $name = 'current_user_context';
    protected int $priority = 17;

    /**
     * Only include when there is user context available (authenticated user or context data)
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        // Include if there's user info in context
        if (!empty($context['current_user'])) {
            return true;
        }

        // Include if user is authenticated
        // Check if auth is available first to prevent early resolution errors
        if (auth()->check()) {
            return true;
        }

        return false;
    }

    public function format(string $question, array $context, array $options = []): string
    {
        $isAuthenticated = auth()->check();

        $output = $this->header('CURRENT USER CONTEXT. USE IT TO HAVE CURRENT TEAM AND CURRENT USER CONTEXT');
        $output .= "Current user name: " . ($isAuthenticated ? auth()->user()->name : 'Guest') . "\n\n";
        $output .= "Current user email: " . ($isAuthenticated ? auth()->user()->email : 'N/A') . "\n\n";
        $output .= "Current user ID: " . ($isAuthenticated ? auth()->id() : 'N/A') . "\n\n";
        $output .= "Current team ID: " . ($isAuthenticated ? (safeCurrentTeam()?->id ?? 'N/A') : 'N/A') . "\n\n";
        $output .= "Current team name: " . ($isAuthenticated ? (safeCurrentTeam()?->team_name ?? 'N/A') : 'N/A') . "\n\n";

        // Enrich with security axes values
        if ($isAuthenticated && app()->bound(SecurityAxesRegistry::class)) {
            $user = auth()->user();
            $registry = app(SecurityAxesRegistry::class);
            $resolvedAxes = $registry->resolveAll($user);

            if (!empty($resolvedAxes)) {
                $output .= "User access axes:\n";
                foreach ($resolvedAxes as $axisName => $ids) {
                    $idStr = !empty($ids) ? implode(', ', $ids) : 'none';
                    $output .= "  {$axisName}: [{$idStr}]\n";
                }
                $output .= "\n";
            }
        }

        // Include active agent info
        if (isset($options['resolved_agent'])) {
            /** @var ResolvedAgent $agent */
            $agent = $options['resolved_agent'];
            $output .= "Active agent: {$agent->getName()} ({$agent->getId()})\n\n";
        }

        return $output;
    }
}