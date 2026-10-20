<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Retrieval\EntitySelectionPruner;
use Condoedge\Ai\Services\Schema\SchemaCardBuilder;

/**
 * SchemaCardsSection (Task 5.2)
 *
 * The ONE schema block of the query-generation prompt: a compact card per selected
 * label (description + aliases, typed properties with declared enum legends,
 * informative notes, the live directed edges, and scalar-FK hints for configured
 * edges the graph lacks). Replaces SchemaSection, RelationshipsSection and
 * DetectedEntitiesSection, which rendered the same schema three times as prose.
 *
 * Keeps the module name 'schema' and the "GRAPH SCHEMA" heading so host
 * removeModule('schema') calls and the QueryRulesSection block references hold.
 * Priority 20 (where SchemaSection was).
 */
class SchemaCardsSection extends BasePromptSection
{
    /** Hard cap on full cards (the no-selection fallback projects the whole graph). */
    public const MAX_CARDS = SchemaCardBuilder::MAX_CARDS;

    /**
     * Selection tiers rendered as COMPACT cards: labels kept only to join or scope
     * the seeds (identity / tenant / closure / ownership). A matched calculation's
     * label keeps its full card — live check: a compact Invoice card lost the
     * receivables question to a competing concept on Account.
     */
    private const SUPPORT_TIERS = [
        EntitySelectionPruner::TIER_IDENTITY,
        EntitySelectionPruner::TIER_TENANT,
        EntitySelectionPruner::TIER_CLOSURE,
        EntitySelectionPruner::TIER_OWNERSHIP,
    ];

    protected string $name = 'schema';
    protected int $priority = 20;

    public function __construct(
        private readonly ?SchemaCardBuilder $builder = null,
        ?string $name = null,
        ?int $priority = null
    ) {
        parent::__construct($name, $priority);
    }

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        return $this->labels($context) !== [];
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $labels = $this->labels($context);
        if ($labels === []) {
            return '';
        }

        $carded = array_slice($labels, 0, SchemaCardBuilder::MAX_CARDS);
        $rest = array_slice($labels, SchemaCardBuilder::MAX_CARDS);

        $output = $this->header('GRAPH SCHEMA');
        $output .= "One card per label. props: name:Type, with {code=meaning} for enums. "
            . "out/in: the ONLY edges that exist, with their direction. "
            . "fk (no edge): a scalar id to join with WHERE (x.fk = y.id) — the named edge does NOT exist. "
            . "Never use a label, property, relationship type or direction that is not listed.\n\n";
        $output .= ($this->builder ?? new SchemaCardBuilder())->render($context->graphSchema, $carded, $this->supportLabels($context)) . "\n";

        if ($rest !== []) {
            $output .= "\nOther labels (no card): " . implode(', ', $rest) . "\n";
        }

        return $output . "\n";
    }

    /**
     * Labels the entity-selection pruner kept only to JOIN or SCOPE the seeds
     * (identity / tenant / closure / ownership tiers) — rendered as compact cards.
     *
     * @return string[]
     */
    private function supportLabels(PipelineContext $context): array
    {
        $tiers = (array) ($context->selectionInfo['selection_tiers'] ?? []);

        return array_keys(array_filter(
            $tiers,
            fn ($tier) => in_array($tier, self::SUPPORT_TIERS, true)
        ));
    }

    /**
     * Schema labels, selected entities first (selection order), then the rest.
     *
     * @return string[]
     */
    private function labels(PipelineContext $context): array
    {
        // Same ordering the retriever used to decide which labels to type-probe.
        return SchemaCardBuilder::orderedLabels(
            (array) ($context->graphSchema['labels'] ?? []),
            (array) ($context->selectionInfo['selected_entities'] ?? [])
        );
    }
}
