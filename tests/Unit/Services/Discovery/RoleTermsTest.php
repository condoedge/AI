<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\TraversalScopeGenerator;
use Condoedge\Ai\Tests\TestCase;

/**
 * ai:discover words a relationship scope as a role ("People who are members") when
 * its name is a generic role word or one of the host's (ai.domain.role_terms).
 */
class RoleTermsTest extends TestCase
{
    private function concept(string $scopeName): string
    {
        $scopes = (new TraversalScopeGenerator())->generateFromRelationship(
            'Person',
            'PersonTeam',
            'BELONGS_TO_PERSON',
            ['field' => 'role', 'values' => [1 => $scopeName]]
        );

        return $scopes[$scopeName]['concept'];
    }

    /** @test */
    public function generic_role_words_need_no_config(): void
    {
        config(['ai.domain.role_terms' => []]);

        $this->assertSame('People who are members', $this->concept('members'));
        $this->assertSame('People with scouts role', $this->concept('scouts'));
    }

    /** @test */
    public function the_host_adds_its_role_words(): void
    {
        config(['ai.domain.role_terms' => ['scouts', 'benevoles']]);

        $this->assertSame('People who are scouts', $this->concept('scouts'));
        $this->assertContains('benevoles', TraversalScopeGenerator::roleNames());
        $this->assertContains('customers', TraversalScopeGenerator::roleNames());
    }
}
