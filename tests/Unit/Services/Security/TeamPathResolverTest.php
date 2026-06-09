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
        // Team IDs are bound as parameters, NOT spliced as an inline list.
        $this->assertStringContainsString('$ai_team_ids', $result['filter']);
        $this->assertStringNotContainsString('[', $result['filter']);
        $this->assertSame([1, 3, 7], $result['parameters']['ai_team_ids']);
    }

    /** @test */
    public function binds_team_ids_as_integer_parameters(): void
    {
        config(['entities.Invoice.security.team_path' => 'team_id']);

        // Non-integer / string ids must be coerced to clean integers.
        $result = $this->resolver->resolve('Invoice', 'i', ['1', 3, '7']);

        $this->assertSame([1, 3, 7], $result['parameters']['ai_team_ids']);
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

    // =====================================================================
    // ruleFor(): the team-ID-free clause TEMPLATE shared by the generator
    // prompt section and the acceptance gate (single source of truth).
    // =====================================================================

    /** @test */
    public function rule_for_returns_direct_where_template_without_team_ids(): void
    {
        config(['entities.Invoice.security.team_path' => 'team_id']);

        $rule = $this->resolver->ruleFor('Invoice', 'i');

        $this->assertSame('direct', $rule['type']);
        $this->assertSame('where', $rule['kind']);
        $this->assertSame('i', $rule['alias']);
        $this->assertSame('team_id', $rule['column']);
        // Template references the bound parameter, never inlines ids.
        $this->assertStringContainsString('WHERE i.team_id IN $ai_team_ids', $rule['filter']);
        $this->assertStringNotContainsString('[', $rule['filter']);
    }

    /** @test */
    public function rule_for_returns_relationship_match_template(): void
    {
        config(['entities.Person.security.team_path' => ['rel' => 'MEMBER_OF', 'target' => 'Team']]);

        $rule = $this->resolver->ruleFor('Person', 'p');

        $this->assertSame('relationship', $rule['type']);
        $this->assertSame('match', $rule['kind']);
        $this->assertStringContainsString('MATCH (p)-[:MEMBER_OF]->(t:Team)', $rule['filter']);
        $this->assertStringContainsString('t.id IN $ai_team_ids', $rule['filter']);
    }

    /** @test */
    public function rule_for_returns_parent_match_template(): void
    {
        config(['entities.InvoiceItem.security.team_path' => 'invoice.team_id']);
        config(['entities.InvoiceItem.graph.relationships' => [
            ['type' => 'BELONGS_TO', 'target_label' => 'Invoice'],
        ]]);

        $rule = $this->resolver->ruleFor('InvoiceItem', 'ii');

        $this->assertSame('parent', $rule['type']);
        $this->assertSame('match', $rule['kind']);
        $this->assertStringContainsString('BELONGS_TO', $rule['filter']);
        $this->assertStringContainsString('team_id IN $ai_team_ids', $rule['filter']);
    }

    /** @test */
    public function rule_for_returns_null_when_no_team_path(): void
    {
        config(['entities.SystemSetting.security.team_path' => null]);
        config(['ai.security.default_team_path' => null]);

        $this->assertNull($this->resolver->ruleFor('SystemSetting', 's'));
    }

    /** @test */
    public function resolve_and_rule_for_emit_the_same_filter_text(): void
    {
        config(['entities.Invoice.security.team_path' => 'team_id']);

        $resolved = $this->resolver->resolve('Invoice', 'i', [1, 3, 7]);
        $rule = $this->resolver->ruleFor('Invoice', 'i');

        // Single source of truth: resolve() is reimplemented on ruleFor(), so the
        // clause text is byte-identical (only the bound parameters differ).
        $this->assertSame($resolved['filter'], $rule['filter']);
        $this->assertSame($resolved['type'], $rule['type']);
    }
}
