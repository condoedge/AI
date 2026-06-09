<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PatternLibrary;

/**
 * PatternLibrarySection
 *
 * Shows available reusable query patterns.
 * Priority: 70
 */
class PatternLibrarySection extends BasePromptSection
{
    protected string $name = 'pattern_library';
    protected int $priority = 70;

    private PatternLibrary $patternLibrary;

    public function __construct(PatternLibrary $patternLibrary)
    {
        $this->patternLibrary = $patternLibrary;
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $patterns = $this->relevantPatterns($context);

        if (empty($patterns)) {
            return '';
        }

        $output = $this->header('AVAILABLE QUERY PATTERNS');
        $output .= "You can use these reusable patterns to construct queries:\n\n";

        foreach ($patterns as $name => $pattern) {
            $output .= "PATTERN: {$name}\n";
            $output .= "  Purpose: {$pattern['description']}\n";
            $output .= "  Template: {$pattern['semantic_template']}\n";

            if (!empty($pattern['parameters'])) {
                $output .= "  Parameters: " . implode(', ', array_keys($pattern['parameters'])) . "\n";
            }

            $output .= "\n";
        }

        return $output;
    }

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        return !empty($this->relevantPatterns($context));
    }

    /**
     * Only the library patterns relevant to the detected scopes, keyed by name.
     *
     * A scope links to a library pattern by its `specification_type` (e.g.
     * relationship_traversal, property_filter) or, for pattern-type scopes, its
     * `pattern` name. With no detected scopes there is no relevance signal, so the
     * section contributes nothing rather than flooding the prompt with every pattern.
     *
     * @return array<string, array> Pattern definitions keyed by pattern name.
     */
    private function relevantPatterns(PipelineContext $context): array
    {
        $all = $this->patternLibrary->getAllPatterns();
        if (empty($all)) {
            return [];
        }

        $wanted = $this->scopePatternNames($context);
        if (empty($wanted)) {
            return [];
        }

        return array_intersect_key($all, array_flip($wanted));
    }

    /**
     * The set of library-pattern names referenced by the detected scopes.
     *
     * @return string[]
     */
    private function scopePatternNames(PipelineContext $context): array
    {
        $scopes = $context->entityMetadata['detected_scopes'] ?? [];
        $names = [];

        foreach ($scopes as $scope) {
            if (!is_array($scope)) {
                continue;
            }

            if (!empty($scope['pattern']) && is_string($scope['pattern'])) {
                $names[] = $scope['pattern'];
            }

            if (!empty($scope['specification_type']) && is_string($scope['specification_type'])) {
                $names[] = $scope['specification_type'];
            }
        }

        return array_values(array_unique($names));
    }
}
