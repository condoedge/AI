<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * NameResolutionSection
 *
 * Adds person name search instructions and property concept mapping to the
 * prompt when the Query Understanding layer has identified resolved names
 * or property concepts. This helps the LLM generate correct CONTAINS/regex
 * searches for person names and map abstract property concepts to the right
 * Neo4j property keys.
 *
 * Priority 18: After CurrentUserContext (17), before Schema (20).
 */
class NameResolutionSection extends BasePromptSection
{
    protected string $name = 'name_resolution';
    protected int $priority = 18;

    /**
     * Only include when QU has provided resolved names or property concepts.
     */
    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        return !empty($context->qu->personNames) || !empty($context->qu->propertyConcepts);
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $output = '';

        $resolvedNames = $context->qu->personNames;
        $propertyConcepts = $context->qu->propertyConcepts;

        if (!empty($resolvedNames)) {
            $output .= $this->header('PERSON NAME SEARCH');
            $output .= "The following person names were identified in the question.\n";
            $output .= "Use CASE-INSENSITIVE partial matching (toLower + CONTAINS) to search for these names.\n";
            $output .= "Search across name-related fields (name, first_name, last_name, full_name, etc.).\n\n";

            foreach ($resolvedNames as $name) {
                $parts = preg_split('/\s+/', trim($name));

                if (count($parts) === 1) {
                    $output .= "- \"{$name}\": Search with toLower(n.name) CONTAINS toLower('{$name}') or similar\n";
                } else {
                    $first = $parts[0];
                    $last = end($parts);
                    $output .= "- \"{$name}\": Search with (toLower(n.name) CONTAINS toLower('{$name}') "
                        . "OR (toLower(n.first_name) CONTAINS toLower('{$first}') "
                        . "AND toLower(n.last_name) CONTAINS toLower('{$last}')))\n";
                }
            }

            $output .= "\n";
        }

        if (!empty($propertyConcepts)) {
            $output .= $this->header('PROPERTY CONCEPT HINTS');
            $output .= "The question involves these data concepts. Map them to the correct Neo4j properties from the schema.\n\n";

            foreach ($propertyConcepts as $concept) {
                $output .= "- {$concept}\n";
            }

            $output .= "\n";
        }

        return $output;
    }
}
