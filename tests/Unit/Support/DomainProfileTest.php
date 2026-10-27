<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\DomainProfile;
use Condoedge\Ai\Tests\TestCase;

/**
 * ai.domain is the one place a host puts its domain (examples, vocabularies);
 * DomainProfile is its only reader.
 */
class DomainProfileTest extends TestCase
{
    /** @test */
    public function a_host_value_wins_over_the_package_default(): void
    {
        config(['ai.domain.understanding.property_concept_examples' => '"goal", "amount raised"']);

        $this->assertSame('"goal", "amount raised"', DomainProfile::text('understanding.property_concept_examples'));
    }

    /** @test */
    public function a_missing_or_non_string_slot_reads_as_empty(): void
    {
        config(['ai.domain.understanding.nothing' => ['a']]);

        $this->assertSame('', DomainProfile::text('understanding.unknown'));
        $this->assertSame('', DomainProfile::text('understanding.nothing'));
    }

    /** @test */
    public function a_list_slot_reads_strings_only(): void
    {
        config(['ai.domain.ambiguous_profile_labels' => ['Customer', 3, 'Member']]);
        $this->assertSame(['Customer', 'Member'], DomainProfile::list('ambiguous_profile_labels'));

        config(['ai.domain.ambiguous_profile_labels' => 'Customer']);
        $this->assertSame([], DomainProfile::list('ambiguous_profile_labels'));
    }

    /** @test */
    public function a_map_slot_keeps_string_keys_with_lists_of_strings(): void
    {
        config(['ai.domain.aliases' => ['campaign' => ['campagne', 4], 'bad' => 'x', 0 => ['y']]]);

        $this->assertSame(['campaign' => ['campagne']], DomainProfile::map('aliases'));
    }

    /** @test */
    public function the_understanding_slots_have_package_defaults(): void
    {
        foreach ([
            'understanding.implicit_entity_example',
            'understanding.property_concept_examples',
            'understanding.possessive_examples',
            'understanding.clarified_example.question',
            'understanding.clarified_example.clarified',
            'understanding.no_owner_example.question',
            'understanding.no_owner_example.clarified',
            'understanding.knowledge_example.question',
            'understanding.knowledge_example.clarified',
            'query_rules.join_example',
            'query_rules.record_examples',
            'query_rules.record_id_example',
            'query_rules.row_grain_examples',
            'discovery.persona_examples',
            'discovery.possessive_path_example',
            'discovery.possessive_alias_examples',
            'discovery.question_examples',
            'discovery.relationship_examples',
            'discovery.junction_example',
            'discovery.possessive_chain_example',
        ] as $slot) {
            $this->assertNotSame('', DomainProfile::text($slot), $slot);
        }
    }

    /** @test */
    public function the_package_defaults_name_no_host_domain(): void
    {
        $defaults = json_encode(config('ai.domain'), JSON_UNESCAPED_UNICODE);

        foreach (['rent', 'unit', 'parking', 'apartment', 'tenant', 'insurance', 'wall', 'building'] as $word) {
            $this->assertDoesNotMatchRegularExpression('/\b' . $word . '/i', $defaults, $word);
        }
    }
}
