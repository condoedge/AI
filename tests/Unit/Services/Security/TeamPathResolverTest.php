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
    public function an_entity_is_found_by_its_graph_label_when_it_differs_from_its_class_name(): void
    {
        config(['entities' => [
            'App\Models\OrderDetail' => [
                'graph' => ['label' => 'OrderLine', 'relationships' => [['type' => 'BELONGS_TO_ORDER', 'target_label' => 'Order']]],
                'security' => ['team_path' => 'order.campaign.team_id', 'row_scope_path' => 'order.campaign.id'],
            ],
            'App\Models\Order' => [
                'graph' => ['label' => 'Order', 'relationships' => [['type' => 'BELONGS_TO_CAMPAIGN', 'target_label' => 'Campaign']]],
                'security' => ['team_path' => 'campaign.team_id'],
            ],
        ]]);

        $chain = 'MATCH (l)-[:BELONGS_TO_ORDER]->(o:Order) MATCH (o)-[:BELONGS_TO_CAMPAIGN]->(c:Campaign) ';
        $this->assertSame($chain . 'WHERE c.team_id IN $ai_team_ids', $this->resolver->ruleFor('OrderLine', 'l')['filter']);
        $this->assertSame($chain . 'WHERE c.id IN $ai_row_scope_ids', $this->resolver->ruleFor('OrderLine', 'l', true)['filter']);
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
    public function scope_labels_are_the_labels_the_scoping_rule_traverses(): void
    {
        config(['entities.Unit.security.team_path' => 'union.team_id']);
        config(['entities.Unit.graph.relationships' => [['type' => 'UNION', 'target_label' => 'Union']]]);
        config(['entities.Vehicle.security.team_path' => 'unit.union.team_id']);
        config(['entities.Vehicle.graph.relationships' => [['type' => 'UNIT', 'target_label' => 'Unit']]]);
        config(['entities.Person.security.team_path' => ['rel' => 'MEMBER_OF', 'target' => 'Team']]);
        config(['entities.Note.security.team_path' => 'team_id']);

        // Parent path: the hop labels (the same labels the rendered rule MATCHes).
        $this->assertSame(['Union'], $this->resolver->scopeLabels('Unit'));
        $this->assertSame(['Unit', 'Union'], $this->resolver->scopeLabels('Vehicle'));
        // Relationship path: its target.
        $this->assertSame(['Team'], $this->resolver->scopeLabels('Person'));
        // Direct column: the node scopes itself.
        $this->assertSame([], $this->resolver->scopeLabels('Note'));
    }

    /** @test */
    public function scope_labels_are_empty_when_the_rule_cannot_be_built(): void
    {
        // Parent path whose hop relationship is not configured → no rule → no labels.
        config(['entities.Orphan.security.team_path' => 'union.team_id']);
        config(['entities.Orphan.graph.relationships' => []]);
        config(['entities.Loose.security.team_path' => null]);
        config(['ai.security.default_team_path' => null]);

        $this->assertSame([], $this->resolver->scopeLabels('Orphan'));
        $this->assertSame([], $this->resolver->scopeLabels('Loose'));
    }

    /** @test */
    public function a_row_rule_reads_row_scope_path_and_the_row_parameter(): void
    {
        config()->set('entities.Meeting.security', ['team_path' => 'team_id', 'row_scope_path' => 'union_id']);
        config()->set('entities.Motion.security', ['team_path' => 'meeting.team_id', 'row_scope_path' => 'meeting.union_id']);
        config()->set('entities.Motion.graph.relationships', [['type' => 'BELONGS_TO_MEETING', 'target_label' => 'Meeting']]);
        config()->set('entities.Supplier.security', ['team_path' => 'team_id', 'row_scope_path' => ['rel' => 'UNIONS', 'target' => 'Union']]);

        $direct = $this->resolver->ruleFor('Meeting', 'm', true);
        $this->assertSame('WHERE m.union_id IN $ai_row_scope_ids', $direct['filter']);
        $this->assertSame('$ai_row_scope_ids', $direct['param']);

        $parent = $this->resolver->ruleFor('Motion', 'mo', true);
        $this->assertSame('MATCH (mo)-[:BELONGS_TO_MEETING]->(m:Meeting) WHERE m.union_id IN $ai_row_scope_ids', $parent['filter']);
        $this->assertSame('$ai_row_scope_ids', $parent['param']);

        $relationship = $this->resolver->ruleFor('Supplier', 's', true);
        $this->assertSame('MATCH (s)-[:UNIONS]->(t:Union) WHERE t.id IN $ai_row_scope_ids', $relationship['filter']);

        $this->assertSame('$ai_team_ids', $this->resolver->ruleFor('Meeting', 'm')['param'], 'the team rule is unchanged');
        $this->assertSame('WHERE m.team_id IN $ai_team_ids', $this->resolver->ruleFor('Meeting', 'm')['filter']);
    }

    /** @test */
    public function an_entity_without_row_scope_path_has_no_row_rule_even_with_a_default_team_path(): void
    {
        config()->set('ai.security.default_team_path', 'auto');
        config()->set('entities.Supplier.security', ['team_path' => 'team_id']);
        config()->set('entities.Supplier.graph.relationships', [['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id']]);
        config()->set('entities.Auto.security', ['team_path' => 'team_id', 'row_scope_path' => 'auto']);
        config()->set('entities.Auto.graph.relationships', [['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id']]);

        $this->assertNull($this->resolver->ruleFor('Supplier', 's', true));
        $this->assertNull($this->resolver->ruleFor('Auto', 'a', true), "'auto' is not a row path");
    }

    /** @test */
    public function scope_labels_include_the_row_rule_labels(): void
    {
        config()->set('entities.Motion.security', ['team_path' => 'team_id', 'row_scope_path' => 'meeting.union_id']);
        config()->set('entities.Motion.graph.relationships', [['type' => 'BELONGS_TO_MEETING', 'target_label' => 'Meeting']]);

        $this->assertSame(['Meeting'], $this->resolver->scopeLabels('Motion'));
    }

    /** @test */
    public function scope_parameters_are_the_team_and_the_row_parameters(): void
    {
        $this->assertTrue(TeamPathResolver::isScopeParameter('$ai_team_ids'));
        $this->assertTrue(TeamPathResolver::isScopeParameter('$ai_row_scope_ids'));
        $this->assertFalse(TeamPathResolver::isScopeParameter('$ai_other'));
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
