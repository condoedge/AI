<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * ProjectContextSection
 *
 * Adds project-level context to help the LLM understand the business domain.
 * Priority: 10 (first section)
 */
class ProjectContextSection extends BasePromptSection
{
    protected string $name = 'project_context';
    protected int $priority = 10;

    /**
     * Custom project context that can be set programmatically
     */
    private ?array $customContext = null;

    /**
     * Set custom project context
     *
     * @param array $context Custom context array with keys: name, description, domain, business_rules
     * @return self
     */
    public function setContext(array $context): self
    {
        $this->customContext = $context;
        return $this;
    }

    /**
     * Add a business rule
     *
     * @param string $rule Business rule to add
     * @return self
     */
    public function addBusinessRule(string $rule): self
    {
        if ($this->customContext === null) {
            $this->customContext = config('ai.project', []);
        }
        $this->customContext['business_rules'][] = $rule;
        return $this;
    }

    public function format(string $question, array $context, array $options = []): string
    {
        $projectConfig = $this->customContext ?? config('ai.project', []);

        if (empty($projectConfig)) {
            return '';
        }

        $output = $this->header('PROJECT CONTEXT');

        if (!empty($projectConfig['name'])) {
            $output .= "Project: {$projectConfig['name']}\n";
        }

        if (!empty($projectConfig['description'])) {
            $output .= "Description: {$projectConfig['description']}\n";
        }

        if (!empty($projectConfig['domain'])) {
            $output .= "Domain: {$projectConfig['domain']}\n";
        }

        if (!empty($projectConfig['business_rules'])) {
            $output .= "\nBusiness Rules:\n";
            foreach ($projectConfig['business_rules'] as $rule) {
                $output .= "  - {$rule}\n";
            }
        }

        $output .= "\n";
        return $output;
    }

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        $projectConfig = $this->customContext ?? config('ai.project', []);
        return !empty($projectConfig);
    }
}
