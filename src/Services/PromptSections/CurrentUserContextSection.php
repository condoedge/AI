<?php

namespace Condoedge\Ai\Services\PromptSections;

class CurrentUserContextSection extends BasePromptSection
{
    protected string $name = 'current_user_context';
    protected int $priority = 17;

    public function format(string $question, array $context, array $options = []): string
    {
        $output = $this->header('CURRENT USER CONTEXT');
        $output .= "Current user name: " . (auth()->check() ? auth()->user()->name : 'Guest') . "\n\n";
        $output .= "Current user email: " . (auth()->check() ? auth()->user()->email : 'N/A') . "\n\n";
        $output .= "Current user ID: " . (auth()->check() ? auth()->id() : 'N/A') . "\n\n";
        $output .= "Current team ID: " . (auth()->check() ? safeCurrentTeam()?->id : 'N/A') . "\n\n";
        $output .= "Current team name: " . (auth()->check() ? safeCurrentTeam()?->team_name : 'N/A') . "\n\n";

        return $output;
    }
}