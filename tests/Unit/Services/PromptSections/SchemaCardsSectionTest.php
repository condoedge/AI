<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\DetectedScopesSection;
use Condoedge\Ai\Services\PromptSections\SchemaCardsSection;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Tests\Fixtures\LegacySchemaSectionsRenderer;
use Condoedge\Ai\Tests\Fixtures\SchemaCardFixture;
use Condoedge\Ai\Tests\TestCase;

/**
 * Task 5.2 — SchemaCardsSection is the single schema block of the query prompt.
 */
class SchemaCardsSectionTest extends TestCase
{
    private function context(): PipelineContext
    {
        config(['entities' => SchemaCardFixture::entities()]);

        $context = new PipelineContext();
        $context->graphSchema = SchemaCardFixture::schema();
        $context->entityMetadata = SchemaCardFixture::entityMetadata();
        $context->selectionInfo = ['selected_entities' => SchemaCardFixture::SELECTED];

        return $context;
    }

    public function test_renders_one_card_per_selected_label(): void
    {
        $output = (new SchemaCardsSection())->format('q', $this->context());

        $this->assertStringContainsString('=== GRAPH SCHEMA ===', $output);
        // A card starts at column 0 with the label; card body lines are indented.
        preg_match_all('/^(\w+)( —|  \(aliases|$)/m', $output, $m);
        $this->assertSame(SchemaCardFixture::SELECTED, array_values(array_filter($m[1], fn ($l) => in_array($l, SchemaCardFixture::SELECTED, true))));
        $this->assertSame(5, substr_count($output, '  props: '));
    }

    public function test_cards_follow_selection_order_not_schema_order(): void
    {
        $context = $this->context();
        $context->graphSchema['labels'] = ['Team', 'Invoice', 'Unit', 'ContactUnit', 'Contact'];
        $context->selectionInfo = ['selected_entities' => ['Invoice', 'Unit']];

        $output = (new SchemaCardsSection())->format('q', $context);

        $this->assertLessThan(strpos($output, "\nUnit —"), strpos($output, "\nInvoice —"));
        $this->assertLessThan(strpos($output, "\nTeam —"), strpos($output, "\nUnit —"));
    }

    public function test_support_tier_labels_get_a_compact_card(): void
    {
        // Task 5.10/5.11: a label kept only to JOIN or SCOPE the seeds (tenant hop,
        // shortest-path closure, identity anchor, ownership path) needs its props and
        // edges — not its property notes and alias list.
        $context = $this->context();
        $context->selectionInfo['selection_tiers'] = [
            'Contact' => 'seed', 'Unit' => 'seed', 'ContactUnit' => 'closure', 'Invoice' => 'calculation', 'Team' => 'identity',
        ];

        $output = (new SchemaCardsSection())->format('q', $context);
        $cards = [];
        foreach (preg_split('/\n\n+/', $output) as $block) {
            if (preg_match('/^(\w+)( —|$)/', $block, $m)) {
                $cards[$m[1]] = $block;
            }
        }

        // Seed card: full (aliases + notes).
        $this->assertStringContainsString('(aliases:', $cards['Unit']);
        $this->assertStringContainsString('  notes: ', $cards['Unit']);
        // Support card: header + props + edges, no aliases / notes.
        $this->assertStringNotContainsString('(aliases:', $cards['ContactUnit']);
        $this->assertStringNotContainsString('  notes: ', $cards['ContactUnit']);
        $this->assertStringContainsString('  props: ', $cards['ContactUnit']);
        $this->assertStringContainsString('link_id', $cards['ContactUnit']);
        $this->assertStringContainsString('{1=Owner-Occupying', $cards['ContactUnit'], 'enum legends stay');
        $this->assertStringContainsString('(ContactUnit)-[:UNIT]->(Unit)', $cards['ContactUnit']);
        // A matched calculation's label keeps its full card.
        $this->assertStringContainsString('(aliases:', $cards['Invoice']);
    }

    public function test_without_tiers_every_card_is_full(): void
    {
        $output = (new SchemaCardsSection())->format('q', $this->context());

        $this->assertStringContainsString('ContactUnit — Pivot linking a Contact to a Unit with an occupancy role.  (aliases:', $output);
    }

    public function test_is_skipped_without_labels(): void
    {
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => []];

        $this->assertFalse((new SchemaCardsSection())->shouldInclude('q', $context));
        $this->assertSame('', (new SchemaCardsSection())->format('q', $context));
    }

    public function test_caps_full_cards_and_lists_the_rest_by_name(): void
    {
        $context = new PipelineContext();
        $labels = array_map(fn ($i) => 'L' . $i, range(1, SchemaCardsSection::MAX_CARDS + 3));
        $context->graphSchema = ['labels' => $labels];
        config(['entities' => []]);

        $output = (new SchemaCardsSection())->format('q', $context);

        $this->assertStringContainsString('Other labels (no card): L21, L22, L23', $output);
        $this->assertDoesNotMatchRegularExpression('/^L21$/m', $output);
    }

    public function test_keeps_the_schema_module_name_and_priority(): void
    {
        $section = new SchemaCardsSection();

        $this->assertSame('schema', $section->getName());
        $this->assertSame(20, $section->getPriority());
        $this->assertSame('user', $section->role());
    }

    public function test_is_registered_in_place_of_the_three_old_sections(): void
    {
        $sections = config('ai.query_generator_sections');

        $this->assertContains(SchemaCardsSection::class, $sections);
        foreach (['SchemaSection', 'RelationshipsSection', 'DetectedEntitiesSection'] as $old) {
            $this->assertNotContains('Condoedge\\Ai\\Services\\PromptSections\\' . $old, $sections);
        }

        $builder = new SemanticPromptBuilder();
        $this->assertInstanceOf(SchemaCardsSection::class, $builder->getModule('schema'));
        $this->assertNull($builder->getModule('relationships'));
        $this->assertNull($builder->getModule('detected_entities'));
        // Scopes still render after the schema.
        $this->assertGreaterThan(20, (new DetectedScopesSection())->getPriority());
    }

    public function test_schema_block_is_smaller_than_the_old_three_sections_combined(): void
    {
        $context = $this->context();

        $old = LegacySchemaSectionsRenderer::render($context);
        $new = (new SchemaCardsSection())->format('q', $context);

        $this->assertNotSame('', $old);
        $this->assertLessThan(
            strlen($old),
            strlen($new),
            sprintf('cards %d chars vs old %d chars', strlen($new), strlen($old))
        );
    }
}
