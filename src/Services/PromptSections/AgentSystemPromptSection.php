<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Agents\ResolvedAgent;

/**
 * Injects the agent's system prompt and business rules into the query generation prompt.
 *
 * Priority 5: Appears early to set the agent's persona before other context.
 */
class AgentSystemPromptSection extends BasePromptSection
{
    protected string $name = 'agent_system_prompt';
    protected int $priority = 5;

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        return isset($options['resolved_agent']);
    }

    public function format(string $question, array $context, array $options = []): string
    {
        /** @var ResolvedAgent $agent */
        $agent = $options['resolved_agent'];

        $output = $this->header('AGENT: ' . strtoupper($agent->getName()));
        $output .= $agent->getSystemPrompt() . "\n\n";

        $rules = $agent->getBusinessRules();
        if (!empty($rules)) {
            $output .= "Business Rules:\n";
            foreach ($rules as $rule) {
                $output .= "- {$rule}\n";
            }
            $output .= "\n";
        }

        $allowedEntities = $agent->getAllowedEntities();
        if (!empty($allowedEntities)) {
            $output .= "You are restricted to these entities: " . implode(', ', $allowedEntities) . "\n";
            $output .= "Do NOT query any entities outside this list.\n\n";
        }

        return $output;
    }
}
