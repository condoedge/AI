<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Security\SecurityAxesRegistry;

/**
 * SecurityContextSection
 *
 * Communicates the user's security scope to the LLM so it can naturally
 * acknowledge permissions in its responses.
 *
 * Priority 16: Right before CurrentUserContextSection (17).
 */
class SecurityContextSection extends BasePromptSection
{
    protected string $name = 'security_context';
    protected int $priority = 16;

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        return auth()->check() && app()->bound(SecurityAxesRegistry::class);
    }

    public function format(string $question, array $context, array $options = []): string
    {
        $user = auth()->user();
        $registry = app(SecurityAxesRegistry::class);
        $resolvedAxes = $registry->resolveAll($user);

        if (empty($resolvedAxes)) {
            return '';
        }

        $output = $this->header('SECURITY CONTEXT');
        $output .= "The user has access to:\n";

        foreach ($resolvedAxes as $axisName => $ids) {
            $displayName = ucfirst($axisName) . 's';
            $idStr = !empty($ids) ? implode(', ', $ids) : 'none';
            $output .= "- {$displayName}: [{$idStr}]\n";
        }

        $output .= "\nAll query results are automatically filtered by these permissions.\n";
        $output .= "Acknowledge the scope naturally in your response when relevant.\n\n";

        return $output;
    }
}
