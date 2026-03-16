<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Agents\ResolvedAgent;

/**
 * Injects user/team custom instructions that ENRICH the agent's behavior.
 *
 * These instructions never replace the base agent configuration.
 * Priority 6: Right after the agent system prompt.
 */
class AgentCustomInstructionsSection extends BasePromptSection
{
    protected string $name = 'agent_custom_instructions';
    protected int $priority = 6;

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        if (!isset($options['resolved_agent'])) {
            return false;
        }

        /** @var ResolvedAgent $agent */
        $agent = $options['resolved_agent'];

        return $agent->customization !== null
            && !empty($agent->customization->custom_instructions);
    }

    public function format(string $question, array $context, array $options = []): string
    {
        /** @var ResolvedAgent $agent */
        $agent = $options['resolved_agent'];

        $output = $this->header('CUSTOM INSTRUCTIONS');
        $output .= "The following are additional instructions from the user's personalization:\n\n";
        $output .= $agent->customization->custom_instructions . "\n\n";
        $output .= "Note: These instructions enrich but never override the agent's core behavior or security restrictions.\n\n";

        return $output;
    }
}
