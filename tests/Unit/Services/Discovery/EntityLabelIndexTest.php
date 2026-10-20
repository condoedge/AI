<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Tests\TestCase;

/**
 * Task 1.28 M4: ONE memoized "entity config by graph label" lookup shared by
 * ResponseEntityEnricher, ResultsDataSection and PolymorphicTargetResolver.
 */
class EntityLabelIndexTest extends TestCase
{
    private function entities(): array
    {
        return [
            'App\\Models\\Team' => ['graph' => ['label' => 'Team'], 'metadata' => ['display_name' => 'team']],
            'App\\Models\\Condo\\Unit' => ['graph' => ['label' => 'Lot']],
            'Invoice' => ['graph' => []],
        ];
    }

    /** @test */
    public function it_finds_the_config_by_graph_label_or_key_basename(): void
    {
        config(['entities' => $this->entities()]);
        $index = new EntityLabelIndex();

        $this->assertSame('team', $index->configForLabel('Team')['metadata']['display_name'] ?? null);
        $this->assertSame(['graph' => ['label' => 'Lot']], $index->configForLabel('Lot'));
        $this->assertSame(['graph' => ['label' => 'Lot']], $index->configForLabel('Unit'), 'key basename');
        $this->assertSame(['graph' => []], $index->configForLabel('Invoice'));
        $this->assertNull($index->configForLabel('SomeRandomWord'));
        $this->assertTrue($index->isRegistered('Team'));
        $this->assertFalse($index->isRegistered('SomeRandomWord'));
    }

    /** @test */
    public function it_maps_a_class_to_its_catalog_label_fqcn_first_then_basename(): void
    {
        config(['entities' => $this->entities() + ['Service' => ['graph' => ['label' => 'Service']]]]);
        $index = new EntityLabelIndex();

        $this->assertSame('Lot', $index->labelForClass('App\\Models\\Condo\\Unit'));
        $this->assertSame('Service', $index->labelForClass('App\\Models\\Billing\\Service'));
        $this->assertNull($index->labelForClass('App\\Models\\Nope'));
    }

    /** @test */
    public function a_term_resolves_to_its_label_in_any_spelling_of_the_label_or_of_a_synonym(): void
    {
        config(['entities' => [
            'App\\Models\\Condo\\VisitDetail' => ['graph' => ['label' => 'VisitDetail'], 'metadata' => ['aliases' => ['visit report', 'rapport de visite']]],
            'App\\Models\\Condo\\Access' => ['graph' => ['label' => 'Access'], 'metadata' => ['aliases' => ['accès', 'fob', 'télécommande']]],
            'App\\Models\\Condo\\Unit' => ['graph' => ['label' => 'Lot'], 'metadata' => ['aliases' => ['apartment']]],
        ]]);
        $index = new EntityLabelIndex();

        // None of these spellings is listed in the config.
        foreach (['VisitDetail', 'visit_details', 'Visit Details', 'visitdetail', 'Rapports de visites', 'visit_report'] as $term) {
            $this->assertSame('VisitDetail', $index->labelForTerm($term), $term);
        }
        foreach (['access', 'Accès', 'acces', 'FOBS', 'telecommandes'] as $term) {
            $this->assertSame('Access', $index->labelForTerm($term), $term);
        }
        $this->assertSame('Lot', $index->labelForTerm('lots'));
        $this->assertSame('Lot', $index->labelForTerm('units'), 'the config-key basename is a name of the entity too');
        $this->assertSame('Lot', $index->labelForTerm('Apartments'));

        $this->assertNull($index->labelForTerm('customer'));
        $this->assertNull($index->labelForTerm(''));
    }

    /** @test */
    public function a_synonym_claimed_by_several_entities_resolves_to_none_and_a_label_wins_over_a_synonym(): void
    {
        config(['entities' => [
            'App\\Models\\Announcement' => ['graph' => ['label' => 'Announcement'], 'metadata' => ['aliases' => ['avis', 'notice', 'notifications']]],
            'App\\Models\\Notification' => ['graph' => ['label' => 'Notification'], 'metadata' => ['aliases' => ['avis', 'alerte']]],
        ]]);
        $index = new EntityLabelIndex();

        $this->assertNull($index->labelForTerm('avis'));
        $this->assertSame('Announcement', $index->labelForTerm('notices'));
        $this->assertSame('Notification', $index->labelForTerm('notifications'), 'a label, not the synonym of the other entity');
    }

    /** @test */
    public function the_term_lookup_follows_a_config_change(): void
    {
        config(['entities' => ['App\\Models\\Access' => ['graph' => ['label' => 'Access'], 'metadata' => ['aliases' => ['fob']]]]]);
        $index = new EntityLabelIndex();
        $this->assertSame('Access', $index->labelForTerm('fobs'));

        config(['entities' => ['App\\Models\\Key' => ['graph' => ['label' => 'Key'], 'metadata' => ['aliases' => ['fob']]]]]);

        $this->assertSame('Key', $index->labelForTerm('fobs'));
        $this->assertNull($index->labelForTerm('access'));
    }

    /** @test */
    public function it_names_the_entity_a_free_text_mentions_in_any_spelling_of_a_name_or_alias(): void
    {
        config(['entities' => [
            'App\\Models\\Condo\\VisitDetail' => ['graph' => ['label' => 'VisitDetail'], 'metadata' => ['aliases' => ['rapport de visite']]],
            'App\\Models\\Condo\\Access' => ['graph' => ['label' => 'Access'], 'metadata' => ['aliases' => ['accès', 'fob']]],
            'App\\Models\\Condo\\Unit' => ['graph' => ['label' => 'Lot']],
            'NoMetadata' => ['graph' => []],
        ]]);
        $index = new EntityLabelIndex();

        $this->assertSame('VisitDetail', $index->labelMentionedIn('Show me the visit_details of last week'));
        $this->assertSame('VisitDetail', $index->labelMentionedIn('Les rapports de visites ?'));
        $this->assertSame('Access', $index->labelMentionedIn('how many fobs (active)?'));
        $this->assertSame('Lot', $index->labelMentionedIn('how many units are there'), 'the config-key basename is a name too');
        $this->assertSame('NoMetadata', $index->labelMentionedIn('the no metadata records'));

        $this->assertNull($index->labelMentionedIn('Show me the balance.'));
        $this->assertNull($index->labelMentionedIn(''));
    }

    /** @test */
    public function it_lists_the_record_kinds_by_display_name_with_their_real_synonyms(): void
    {
        config(['entities' => [
            'App\\Models\\Condo\\VisitDetail' => ['graph' => ['label' => 'VisitDetail'], 'metadata' => [
                'aliases' => ['visit_details', 'visit report', 'visit reports', 'rapport de visite'],
            ]],
            'App\\Models\\Team' => ['graph' => ['label' => 'Team'], 'metadata' => ['display_name' => 'management team', 'aliases' => ['teams', 'équipe']]],
            'Invoice' => ['graph' => []],
            'broken' => 'not-an-entity',
        ]]);

        $this->assertSame([
            // The label as the words a person types; the derivable spellings are not synonyms.
            ['name' => 'visit detail', 'synonyms' => ['visit report', 'rapport de visite']],
            ['name' => 'management team', 'synonyms' => ['équipe']],
            ['name' => 'invoice', 'synonyms' => []],
        ], (new EntityLabelIndex())->recordKinds());
    }

    /** @test */
    public function the_memo_follows_a_config_change(): void
    {
        config(['entities' => $this->entities()]);
        $index = new EntityLabelIndex();
        $this->assertFalse($index->isRegistered('Member'));

        config(['entities' => ['App\\Models\\Member' => ['graph' => ['label' => 'Member']]]]);

        $this->assertTrue($index->isRegistered('Member'));
        $this->assertFalse($index->isRegistered('Team'));
    }
}
