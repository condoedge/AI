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
 * Neo4j property keys — and PROJECT those requested attributes as RETURN
 * columns rather than only filtering on them.
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
            // Eval p12-1001-A: "phone: RETURN the mapped property as a column" plus
            // a PRECEDENCE line over every description made the generator satisfy a
            // phone the User card says is not recorded with the TEAM's
            // t.contact_phone. A requested attribute is demanded only where a
            // property holds it FOR the records being listed; otherwise it is
            // omitted and reported as not recorded — never taken from another
            // entity. Which property holds a concept is semantic, so it stays an
            // instruction; the subject entity is named structurally when known.
            $label = $this->subjectLabel($context);
            $subject = $label !== null ? "the {$label} records being listed" : 'the records being listed';
            $notRecordedFor = $label !== null ? "not recorded for {$label}" : 'not recorded for those records';

            $output .= $this->header('REQUESTED ATTRIBUTES');
            $output .= "The user asked to see these data concepts about {$subject} (one row per record). Map each "
                . "to the Neo4j property that holds it FOR those records — a property on their GRAPH SCHEMA card, "
                . "or on a related entity that describes them (e.g. a 1:N detail such as their roles or their "
                . "contact entries) — then PROJECT it: every mapped property MUST appear in the RETURN clause "
                . "as an output column (in addition to an identifying column such as a name or id), not only "
                . "in a WHERE filter.\n";
            $output .= "NOT RECORDED: when no property holds a concept for {$subject} (no such property, or their "
                . "card says it is not on record), do NOT return a column for it — omit it; the answer states "
                . "it is {$notRecordedFor}. NEVER fill it from another entity's field: a related record's own "
                . "attribute of the same kind (its own phone, its own email) describes THAT record, not "
                . "{$subject}.\n";
            $output .= "PRECEDENCE: these requested attributes OVERRIDE any column list suggested by an entity "
                . "description elsewhere in this prompt. A description's \"columns like ...\" is only a default "
                . "for when the user did not ask for specific attributes — when a requested attribute of the "
                . "listed records lives on a related entity, JOIN through the schema's relationships to reach "
                . "it rather than dropping the attribute. PRECEDENCE never overrides a schema card that says an "
                . "attribute is not recorded.\n"
                // Eval p13-A excel: "contact info" came back as `u.email AS contact` — a column
                // named after the user's word hid what it holds (the export header read "Contact"
                // beside a Role column whose value is mostly "contact").
                . "COLUMN NAMES: alias each returned column after the PROPERTY it holds (u.email AS email), "
                . "never after the word the user used for it.\n\n";

            foreach ($propertyConcepts as $concept) {
                $output .= "- {$concept}: RETURN it as a column when a property holds it for {$subject}; "
                    . "otherwise omit it ({$notRecordedFor})\n";
            }

            $output .= "\n";
        }

        return $output;
    }

    /**
     * The label of the records a FOLLOW-UP operates on: the anchor label recorded
     * with the previous result (ConversationContextManager last_anchor_label, the
     * companion count's anchor — structural). Null on a fresh question or when
     * the previous rows had no proven anchor: QU's entity types / the focus are
     * NOT used (live p12: [Team, TeamRole, User] / focus Team for a member list).
     */
    private function subjectLabel(PipelineContext $context): ?string
    {
        if (!$context->qu->followUpReference) {
            return null;
        }
        $label = $context->conversationContext['last_anchor_label'] ?? null;

        return is_string($label) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', trim($label)) === 1 ? trim($label) : null;
    }
}
