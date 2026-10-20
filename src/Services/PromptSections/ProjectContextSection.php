<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

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

    public function format(string $question, PipelineContext $context, array $options = []): string
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

        $rules = $this->relevantRules((array) ($projectConfig['business_rules'] ?? []), $context);
        if ($rules !== []) {
            $output .= "\nBusiness Rules:\n";
            foreach ($rules as $rule) {
                $output .= "  - {$rule}\n";
            }
        }

        $output .= "\n";
        return $output;
    }

    /**
     * The rule texts to render (Task 5.11). A rule is either a string (always
     * rendered) or ['rule' => string, 'entities' => string[]] — rendered only when
     * one of its entities is in the projected schema (all rules render when the
     * context carries no projected schema). Malformed entries are skipped.
     *
     * @return string[]
     */
    private function relevantRules(array $rules, PipelineContext $context): array
    {
        $labels = array_filter((array) ($context->graphSchema['labels'] ?? []), fn ($l) => is_string($l) && $l !== '');
        $selected = $labels === [] ? null : array_fill_keys(array_map('strtolower', $labels), true);

        $texts = [];
        foreach ($rules as $rule) {
            if (is_string($rule) && trim($rule) !== '') {
                $texts[] = $rule;
                continue;
            }
            if (!is_array($rule) || !is_string($rule['rule'] ?? null) || trim($rule['rule']) === '') {
                continue;
            }

            $entities = array_filter((array) ($rule['entities'] ?? []), fn ($e) => is_string($e) && $e !== '');
            if ($selected !== null && $entities !== []) {
                $matches = array_filter($entities, fn ($e) => isset($selected[strtolower($e)]));
                if ($matches === []) {
                    continue;
                }
            }
            $texts[] = $rule['rule'];
        }

        return $texts;
    }

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        $projectConfig = $this->customContext ?? config('ai.project', []);
        return !empty($projectConfig);
    }
}
