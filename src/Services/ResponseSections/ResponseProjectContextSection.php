<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

/**
 * ResponseProjectContextSection
 *
 * Adds project context to help the LLM understand the business domain
 * when explaining results.
 * Priority: 20
 */
class ResponseProjectContextSection extends BaseResponseSection
{
    protected string $name = 'project_context';
    protected int $priority = 20;

    private ?array $customContext = null;

    /**
     * Set custom project context
     */
    public function setContext(array $context): self
    {
        $this->customContext = $context;
        return $this;
    }

    public function format(array $context, array $options = []): string
    {
        $projectConfig = $this->customContext ?? config('ai.project', []);

        if (empty($projectConfig)) {
            return '';
        }

        $output = "Project Context:\n";

        if (!empty($projectConfig['name'])) {
            $output .= "- Project: {$projectConfig['name']}\n";
        }

        if (!empty($projectConfig['description'])) {
            $output .= "- Description: {$projectConfig['description']}\n";
        }

        if (!empty($projectConfig['domain'])) {
            $output .= "- Domain: {$projectConfig['domain']}\n";
        }

        return $output . "\n";
    }

    public function shouldInclude(array $context, array $options = []): bool
    {
        $projectConfig = $this->customContext ?? config('ai.project', []);
        return !empty($projectConfig);
    }
}
