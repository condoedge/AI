<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Tests\TestCase;

class TeamPathResolverTest extends TestCase
{
    private TeamPathResolver $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TeamPathResolver();
    }

    /** @test */
    public function resolves_direct_team_id_path(): void
    {
        config(['entities.Invoice.security.team_path' => 'team_id']);

        $result = $this->resolver->resolve('Invoice', 'i', [1, 3, 7]);

        $this->assertEquals('direct', $result['type']);
        $this->assertStringContainsString('i.team_id IN', $result['filter']);
        $this->assertStringContainsString('1, 3, 7', $result['filter']);
    }

    /** @test */
    public function resolves_parent_path(): void
    {
        config(['entities.InvoiceItem.security.team_path' => 'invoice.team_id']);
        config(['entities.InvoiceItem.graph.relationships' => [
            ['type' => 'BELONGS_TO', 'target_label' => 'Invoice'],
        ]]);

        $result = $this->resolver->resolve('InvoiceItem', 'ii', [1, 3]);

        $this->assertEquals('parent', $result['type']);
        $this->assertStringContainsString('BELONGS_TO', $result['filter']);
        $this->assertStringContainsString('Invoice', $result['filter']);
        $this->assertStringContainsString('team_id IN', $result['filter']);
    }

    /** @test */
    public function resolves_relationship_path(): void
    {
        config(['entities.Person.security.team_path' => ['rel' => 'MEMBER_OF', 'target' => 'Team']]);

        $result = $this->resolver->resolve('Person', 'p', [1, 3]);

        $this->assertEquals('relationship', $result['type']);
        $this->assertStringContainsString('MEMBER_OF', $result['filter']);
        $this->assertStringContainsString('Team', $result['filter']);
    }

    /** @test */
    public function auto_detects_from_relationships_config(): void
    {
        config(['entities.Invoice.security.team_path' => 'auto']);
        config(['entities.Invoice.graph.relationships' => [
            ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id'],
        ]]);

        $result = $this->resolver->resolve('Invoice', 'i', [1, 3, 7]);

        $this->assertEquals('direct', $result['type']);
        $this->assertStringContainsString('team_id', $result['filter']);
    }

    /** @test */
    public function returns_null_when_no_team_path(): void
    {
        config(['entities.SystemSetting.security.team_path' => null]);

        $result = $this->resolver->resolve('SystemSetting', 's', [1, 3]);

        $this->assertNull($result);
    }

    /** @test */
    public function uses_default_team_path_when_not_configured(): void
    {
        config(['ai.security.default_team_path' => 'team_id']);
        config(['entities.Invoice.security' => []]);  // No team_path set

        $result = $this->resolver->resolve('Invoice', 'i', [1, 3]);

        $this->assertEquals('direct', $result['type']);
    }
}
