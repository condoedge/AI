<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\TenantScopingRulesSection;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Tests\TestCase;

/**
 * The generator-emits-scope instruction section: for every tenant-sensitive label
 * in the schema it renders the EXACT $ai_team_ids predicate the acceptance gate
 * will verify (derived from the same TeamPathResolver template), plus the UNION
 * column-consistency rules. It is a SYSTEM-role section and emits nothing when team
 * security is off or the schema has no tenant-scoped labels.
 */
class TenantScopingRulesSectionTest extends TestCase
{
    private function section(): TenantScopingRulesSection
    {
        return new TenantScopingRulesSection(new TeamPathResolver());
    }

    private function contextWithLabels(array $labels): PipelineContext
    {
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => $labels, 'relationships' => []];
        return $context;
    }

    /** @test */
    public function it_is_a_system_role_section(): void
    {
        $this->assertSame('system', $this->section()->role());
        $this->assertSame('tenant_scoping_rules', $this->section()->getName());
    }

    /** @test */
    public function it_renders_the_direct_predicate_for_a_tenant_scoped_label(): void
    {
        config(['ai.security.enabled' => true]);
        config(['entities.Invoice.security.team_path' => 'team_id']);

        $out = $this->section()->format('q', $this->contextWithLabels(['Invoice']), []);

        // The instruction names the label and the EXACT predicate the gate verifies.
        $this->assertStringContainsString('Invoice', $out);
        $this->assertStringContainsString('team_id IN $ai_team_ids', $out);
        // Bound parameter, never inline ids.
        $this->assertStringNotContainsString('IN [', $out);
    }

    /** @test */
    public function it_renders_the_relationship_traversal_for_a_relationship_scoped_label(): void
    {
        config(['ai.security.enabled' => true]);
        config(['entities.Person.security.team_path' => ['rel' => 'MEMBER_OF', 'target' => 'Team']]);

        $out = $this->section()->format('q', $this->contextWithLabels(['Person']), []);

        $this->assertStringContainsString('MEMBER_OF', $out);
        $this->assertStringContainsString('IN $ai_team_ids', $out);
    }

    /** @test */
    public function it_includes_union_column_consistency_rules(): void
    {
        config(['ai.security.enabled' => true]);
        config(['entities.Invoice.security.team_path' => 'team_id']);

        $out = $this->section()->format('q', $this->contextWithLabels(['Invoice']), []);

        $this->assertStringContainsStringIgnoringCase('UNION', $out);
        $this->assertStringContainsStringIgnoringCase('same column', $out);
    }

    /** @test */
    public function it_emits_nothing_when_security_is_disabled(): void
    {
        config(['ai.security.enabled' => false]);
        config(['entities.Invoice.security.team_path' => 'team_id']);

        $this->assertSame('', $this->section()->format('q', $this->contextWithLabels(['Invoice']), []));
        $this->assertFalse($this->section()->shouldInclude('q', $this->contextWithLabels(['Invoice']), []));
    }

    /** @test */
    public function it_emits_nothing_when_no_schema_label_is_tenant_scoped(): void
    {
        config(['ai.security.enabled' => true]);
        // No team_path and no auto-detectable Team relationship → not tenant-scoped.
        config(['ai.security.default_team_path' => null]);
        config(['entities.SystemSetting.security.team_path' => null]);

        $out = $this->section()->format('q', $this->contextWithLabels(['SystemSetting']), []);

        $this->assertSame('', $out);
    }

    /** @test */
    public function it_skips_labels_that_are_not_tenant_scoped_but_keeps_those_that_are(): void
    {
        config(['ai.security.enabled' => true]);
        config(['ai.security.default_team_path' => null]);
        config(['entities.Invoice.security.team_path' => 'team_id']);
        config(['entities.SystemSetting.security.team_path' => null]);

        $out = $this->section()->format('q', $this->contextWithLabels(['Invoice', 'SystemSetting']), []);

        $this->assertStringContainsString('Invoice', $out);
        // The non-scoped label gets no instruction line.
        $this->assertStringNotContainsString('SystemSetting', $out);
    }
}
