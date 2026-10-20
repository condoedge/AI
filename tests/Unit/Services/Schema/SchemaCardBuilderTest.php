<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Schema;

use Condoedge\Ai\Services\Schema\SchemaCardBuilder;
use Condoedge\Ai\Tests\Fixtures\SchemaCardFixture;
use PHPUnit\Framework\TestCase;

/**
 * Task 5.2 — ONE compact card per selected label replaces the schema rendered
 * 3x as prose (SchemaSection + RelationshipsSection + DetectedEntitiesSection).
 * Edges come from the LIVE graph with direction; property types from the
 * sampled graph; description/aliases/enums/notes from the entity config.
 */
class SchemaCardBuilderTest extends TestCase
{
    private function cards(?array $schema = null, ?array $labels = null, ?array $entities = null): array
    {
        return (new SchemaCardBuilder($entities ?? SchemaCardFixture::entities()))
            ->cards($schema ?? SchemaCardFixture::schema(), $labels ?? SchemaCardFixture::SELECTED);
    }

    private function line(string $card, string $prefix): string
    {
        foreach (explode("\n", $card) as $line) {
            if (str_starts_with(ltrim($line), $prefix)) {
                return $line;
            }
        }

        return '';
    }

    public function test_computed_properties_are_listed_and_described_from_common_properties(): void
    {
        $entities = ['App\\Models\\Bill' => [
            'graph' => [
                'label' => 'Bill',
                'properties' => ['id', 'status'],
                'computed' => ['due_amount' => 'real_due_amount'],
            ],
            'metadata' => [
                'common_properties' => [
                    'due_amount' => 'Amount still owed on the bill after payments, in dollars',
                ],
            ],
        ]];

        $card = $this->cards(['labels' => ['Bill']], ['Bill'], $entities)['Bill'];

        $this->assertStringContainsString('due_amount', $this->line($card, 'props:'));
        $this->assertStringContainsString('due_amount: Amount still owed on the bill after payments', $card);
    }

    public function test_one_card_per_selected_label_in_selection_order(): void
    {
        $cards = $this->cards();

        $this->assertSame(SchemaCardFixture::SELECTED, array_keys($cards));
    }

    public function test_header_has_single_line_description_and_aliases(): void
    {
        $contact = $this->cards()['Contact'];
        $header = explode("\n", $contact)[0];

        $this->assertStringStartsWith('Contact — A person or organization known to the condo: owners, renters', $header);
        // 'contact' / 'contacts' only re-spell the label: not shown.
        $this->assertStringContainsString('(aliases: person, people, resident)', $header);
    }

    public function test_aliases_are_capped(): void
    {
        $header = explode("\n", $this->cards()['Invoice'])[0];

        // 12 configured: 5 only re-spell the label or an earlier alias, the 7 words all fit.
        $this->assertStringContainsString(
            '(aliases: facture client, cotisation, contribution, condo fee, assessment, statement, charges)',
            $header
        );

        $entities = ['App\\Models\\Fund' => [
            'graph' => ['label' => 'Fund', 'properties' => ['id']],
            'metadata' => ['aliases' => ['fonds', 'reserve', 'prévoyance', 'contingency', 'operating', 'exploitation', 'réparation', 'assurance', 'cotisation', 'frais']],
        ]];
        $header = explode("\n", $this->cards(['labels' => ['Fund']], ['Fund'], $entities)['Fund'])[0];

        // 10 real synonyms — the tail is cut at MAX_ALIASES.
        $this->assertSame('Fund  (aliases: fonds, reserve, prévoyance, contingency, operating, exploitation, réparation, assurance)', $header);
    }

    public function test_outgoing_and_incoming_edges_carry_the_live_direction(): void
    {
        $cards = $this->cards();

        $contactOut = $this->line($cards['Contact'], 'out:');
        $this->assertStringContainsString('(Contact)-[:CONTACT_UNITS]->(ContactUnit)', $contactOut);
        $this->assertStringContainsString('(Contact)-[:BELONGS_TO_TEAM]->(Team)', $contactOut);

        $cuIn = $this->line($cards['ContactUnit'], 'in:');
        $cuOut = $this->line($cards['ContactUnit'], 'out:');
        $this->assertStringContainsString('(Contact)-[:CONTACT_UNITS]->(ContactUnit)', $cuIn);
        $this->assertStringContainsString('(ContactUnit)-[:UNIT]->(Unit)', $cuOut);
        $this->assertStringNotContainsString('CONTACT_UNITS', $cuOut, 'an incoming edge never renders as outgoing');

        $unitIn = $this->line($cards['Unit'], 'in:');
        $this->assertStringContainsString('(ContactUnit)-[:UNIT]->(Unit)', $unitIn);
        $this->assertStringContainsString('(Invoice)-[:CUSTOMER]->(Unit)', $unitIn);
    }

    public function test_polymorphic_config_edge_resolves_to_the_live_concrete_targets(): void
    {
        $out = $this->line($this->cards()['Invoice'], 'out:');

        $this->assertStringContainsString('(Invoice)-[:CUSTOMER]->(Unit)', $out);
        $this->assertStringContainsString('(Invoice)-[:CUSTOMER]->(Contact)', $out);
        $this->assertStringNotContainsString('_polymorphic', implode("\n", $this->cards()));
    }

    public function test_edge_to_unselected_label_only_when_the_card_label_declares_it(): void
    {
        $cards = $this->cards();
        $all = implode("\n", $cards);

        // Declared by Unit's own config, live in the graph → kept.
        $this->assertStringContainsString('(Unit)-[:BELONGS_TO_UNION]->(Union)', $this->line($cards['Unit'], 'out:'));
        // Live but not declared, other end unselected → dropped.
        $this->assertStringNotContainsString('HAS_FILES', $all);
        // Patterns among unselected labels never appear.
        $this->assertStringNotContainsString('BELONGS_TO_SUPPLIER', $all);
        $this->assertStringNotContainsString('Supplier', $all);
        // Bill declares BELONGS_TO_TEAM, but Bill is not selected: no in-edge on Team's card.
        $this->assertStringNotContainsString('Bill', $all);
        $teamIn = $this->line($cards['Team'], 'in:');
        $this->assertStringContainsString('(Contact)-[:BELONGS_TO_TEAM]->(Team)', $teamIn);
        $this->assertStringContainsString('(Invoice)-[:BELONGS_TO_TEAM]->(Team)', $teamIn);
    }

    public function test_properties_carry_their_sampled_types(): void
    {
        $props = $this->line($this->cards()['Invoice'], 'props:');

        $this->assertStringContainsString('calc_due_amount:Float', $props);
        $this->assertStringContainsString('due_at:String(ISO date)', $props);
        $this->assertStringContainsString('customer_id:Integer', $props);
    }

    public function test_property_without_a_sampled_type_is_listed_untyped(): void
    {
        $schema = SchemaCardFixture::schema();
        unset($schema['property_types']['Invoice']['calc_due_amount']);

        $props = $this->line($this->cards($schema)['Invoice'], 'props:');

        $this->assertMatchesRegularExpression('/(^|[\s,])calc_due_amount(,|$)/', trim(substr($props, strpos($props, ':') + 1)));
    }

    public function test_enum_legend_from_the_config_description_is_rendered_inline(): void
    {
        $cards = $this->cards();

        $this->assertStringContainsString(
            'status:String(numeric){1=Draft|2=Approved|3=Sent|4=Partially Paid|5=Paid|10=Voided}',
            $this->line($cards['Invoice'], 'props:')
        );
        $this->assertStringContainsString(
            'link_id:Integer{1=Owner-Occupying|2=Owner-Not-Occupying|3=Renter|4=Occupant}',
            $this->line($cards['ContactUnit'], 'props:')
        );
    }

    public function test_enum_is_capped_at_eight_values(): void
    {
        $entities = ['App\\Models\\Fund' => [
            'graph' => ['label' => 'Fund', 'properties' => ['type_id']],
            'metadata' => ['common_properties' => [
                'type_id' => 'Fund type (1=A, 2=B, 3=C, 4=D, 5=E, 6=F, 7=G, 8=H, 9=I, 10=J)',
            ]],
        ]];

        $props = $this->line($this->cards(['labels' => ['Fund']], ['Fund'], $entities)['Fund'], 'props:');

        $this->assertStringContainsString('type_id{1=A|2=B|3=C|4=D|5=E|6=F|7=G|8=H|…}', $props);
        $this->assertStringNotContainsString('9=I', $props);
    }

    public function test_informative_property_descriptions_become_notes_boilerplate_is_dropped(): void
    {
        $cards = $this->cards();
        $all = implode("\n", $cards);

        $this->assertStringContainsString('sold_at: Sale date; NULL means the link is current.', $this->line($cards['ContactUnit'], 'notes:'));
        $this->assertStringContainsString('email: Primary email address used for notices', $this->line($cards['Contact'], 'notes:'));
        $this->assertStringContainsString('name: Unit number as shown to owners (e.g. 310)', $this->line($cards['Unit'], 'notes:'));
        // Recurs across >=3 entities → boilerplate.
        $this->assertStringNotContainsString('Creation timestamp', $all);
        $this->assertStringNotContainsString('Unique identifier', $all);
        // Foreign key of a configured relationship → the edge/fk line says it.
        $this->assertStringNotContainsString('Foreign key to Team', $all);
        // Merely the humanized property name.
        $this->assertStringNotContainsString('first_name: First name', $all);
        $this->assertStringNotContainsString('name: Name', $cards['Team']);
        // Enum descriptions are rendered as the inline legend, not repeated as a note.
        $this->assertStringNotContainsString('status: Status', $all);
    }

    public function test_relationship_description_first_sentence_is_a_note_on_the_declaring_card(): void
    {
        $cards = $this->cards();

        $this->assertStringContainsString(
            '[:CONTACT_UNITS]: All unit links for this contact (current and sold).',
            $this->line($cards['Contact'], 'notes:')
        );
        $this->assertStringNotContainsString('Includes sold ones', implode("\n", $cards));
    }

    public function test_configured_edge_missing_from_the_graph_becomes_a_scalar_fk_hint(): void
    {
        $contact = $this->cards()['Contact'];

        // The absent configured edge type is NAMED: it is the edge the model would invent.
        $this->assertStringContainsString('user_id → User.id (no :CURRENT_USER)', $this->line($contact, 'fk (no edge'));
        $this->assertStringNotContainsString('[:CURRENT_USER]', $contact, 'never rendered as an edge');
    }

    public function test_alias_separator_and_case_variants_collapse(): void
    {
        $entities = ['App\\Models\\TeamRole' => [
            'graph' => ['label' => 'TeamRole', 'properties' => ['id']],
            'metadata' => ['aliases' => ['team_user', 'team user', 'Team-User', 'teamuser', 'team_role', 'member']],
        ]];

        $header = explode("\n", $this->cards(['labels' => ['TeamRole']], ['TeamRole'], $entities)['TeamRole'])[0];

        // One word, shown once, in the spelling a person types.
        $this->assertSame('TeamRole  (aliases: team user, member)', $header);
    }

    public function test_alias_plural_and_accent_variants_collapse(): void
    {
        $entities = ['App\\Models\\Access' => [
            'graph' => ['label' => 'Access', 'properties' => ['id']],
            'metadata' => ['aliases' => [
                'access', 'accès', 'acces', 'clé', 'cle', 'fob', 'télécommande', 'telecommande',
                'clés', 'cles', 'fobs', 'télécommandes', 'telecommandes', 'badge',
            ]],
        ]];

        $header = explode("\n", $this->cards(['labels' => ['Access']], ['Access'], $entities)['Access'])[0];

        // 14 configured, 5 words — 'badge' used to be cut off by the spellings before it.
        $this->assertSame('Access  (aliases: accès, clé, fob, télécommande, badge)', $header);
    }

    public function test_without_pattern_evidence_config_edges_render_and_polymorphic_is_unlabeled(): void
    {
        $entities = ['App\\Models\\File' => [
            'graph' => [
                'label' => 'File',
                'properties' => ['id', 'parent_id'],
                'relationships' => [
                    ['type' => 'PARENT', 'target_label' => '_polymorphic', 'foreign_key' => 'parent_id', 'direction' => 'outgoing'],
                    ['type' => 'ABOUT', 'target_label' => 'Note', 'direction' => 'incoming'],
                    ['type' => 'GONE', 'target_label' => 'Team', 'direction' => 'outgoing'],
                ],
            ],
        ]];
        $schema = ['labels' => ['File'], 'all_relationship_types' => ['PARENT', 'ABOUT']];

        $card = $this->cards($schema, ['File'], $entities)['File'];

        $this->assertStringContainsString('(File)-[:PARENT]->()', $this->line($card, 'out:'));
        $this->assertStringContainsString('(Note)-[:ABOUT]->(File)', $this->line($card, 'in:'));
        $this->assertStringNotContainsString('GONE', $card, 'a type absent from the graph is never advertised');
        $this->assertStringNotContainsString('_polymorphic', $card);
    }

    /**
     * Review I-1: a failed pattern scan reaches the builder as null (unknown) — or, via
     * the projected slice, as [] while relationship types exist. Neither is evidence
     * that the graph has no edges: fall back to the configured edges, and never mark
     * configured FKs "(no :TYPE)".
     *
     * @dataProvider unknownPatternShapes
     */
    public function test_unknown_patterns_fall_back_to_configured_edges(array $overrides): void
    {
        $schema = array_merge(SchemaCardFixture::schema(), $overrides);

        $cards = $this->cards($schema);

        $this->assertStringContainsString('(Contact)-[:CONTACT_UNITS]->(ContactUnit)', $this->line($cards['Contact'], 'out:'));
        $this->assertStringContainsString('(ContactUnit)-[:UNIT]->(Unit)', $this->line($cards['ContactUnit'], 'out:'));
        $this->assertStringContainsString('(Invoice)-[:CUSTOMER]->()', $this->line($cards['Invoice'], 'out:'));
        // A type the graph HAS is never declared missing...
        $this->assertStringNotContainsString('(no :BELONGS_TO_TEAM)', implode("\n", $cards));
        $this->assertStringNotContainsString('(no :BELONGS_TO_UNION)', implode("\n", $cards));
        // ...only a type absent from the graph's type list is.
        $this->assertStringContainsString('user_id → User.id (no :CURRENT_USER)', $this->line($cards['Contact'], 'fk (no edge'));
    }

    public static function unknownPatternShapes(): array
    {
        return [
            'full evidence null, projected slice []' => [['all_relationship_patterns' => null, 'relationshipPatterns' => []]],
            'no full key, projected [] with types present' => [['relationshipPatterns' => [], 'all_relationship_patterns' => []]],
        ];
    }

    public function test_label_without_config_still_gets_a_card_from_the_graph(): void
    {
        $schema = SchemaCardFixture::schema();
        $schema['property_types']['Union'] = ['id' => 'Integer', 'name' => 'String'];

        $cards = $this->cards($schema, ['Unit', 'Union']);

        $this->assertStringStartsWith('Union', $cards['Union']);
        $this->assertStringContainsString('id:Integer, name:String', $this->line($cards['Union'], 'props:'));
        $this->assertStringContainsString('(Unit)-[:BELONGS_TO_UNION]->(Union)', $this->line($cards['Union'], 'in:'));
    }

    public function test_render_joins_cards(): void
    {
        $builder = new SchemaCardBuilder(SchemaCardFixture::entities());
        $rendered = $builder->render(SchemaCardFixture::schema(), SchemaCardFixture::SELECTED);

        foreach (SchemaCardFixture::SELECTED as $label) {
            $this->assertMatchesRegularExpression('/^' . $label . '( —|$)/m', $rendered);
        }
    }
}
