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
    public function it_includes_only_the_tenant_specific_union_rules(): void
    {
        config(['ai.security.enabled' => true]);
        config(['entities.Invoice.security.team_path' => 'team_id']);

        $out = $this->section()->format('q', $this->contextWithLabels(['Invoice']), []);

        // Per-branch team filter placement stays here...
        $this->assertStringContainsString('$ai_team_ids filter INSIDE that branch', $out);
        // ...the generic column-alias rule moved to QueryRulesSection (review M-3).
        $this->assertStringNotContainsStringIgnoringCase('same column', $out);
    }

    /** @test Task 5.8: the UNION rules were duplicated verbatim in SemanticPromptBuilder. */
    public function the_assembled_prompt_carries_the_union_rules_exactly_once(): void
    {
        config(['ai.security.enabled' => true]);
        config(['ai.security.enforce_scopeable_subset' => true]);
        config(['entities.Invoice.security.team_path' => 'team_id']);

        $prompts = (new \Condoedge\Ai\Services\SemanticPromptBuilder())
            ->buildPrompts('list invoices', $this->contextWithLabels(['Invoice']));
        $whole = $prompts['system'] . "\n" . $prompts['user'];

        $this->assertSame(1, substr_count($whole, 'UNION RULES'));
        $this->assertSame(1, substr_count($whole, 'complete, independent query'));
        $this->assertSame(1, substr_count($whole, 'same column names/aliases'), 'one generic UNION column rule');
        // The non-duplicated shape rules stay in the builder's system framing.
        $this->assertStringContainsString('QUERY SHAPE RULES', $prompts['system']);
    }

    /** @test Review M-3: with tenant scoping OFF the generic UNION column rule still ships, once. */
    public function the_generic_union_column_rule_survives_when_scoping_is_off(): void
    {
        config(['ai.security.enabled' => false]);

        $prompts = (new \Condoedge\Ai\Services\SemanticPromptBuilder())
            ->buildPrompts('list invoices', $this->contextWithLabels(['Invoice']));
        $whole = $prompts['system'] . "\n" . $prompts['user'];

        $this->assertSame(1, substr_count($whole, 'same column names/aliases'));
        $this->assertStringNotContainsString('UNION RULES', $whole);
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
    public function a_narrowed_user_is_told_the_row_rule_and_not_the_team_rule(): void
    {
        config(['ai.security.enabled' => true]);
        config(['entities.Meeting.security' => ['team_path' => 'team_id', 'row_scope_path' => 'union_id']]);
        config(['entities.Supplier.security' => ['team_path' => 'team_id']]);
        $unnarrowed = $this->section()->format('q', $this->contextWithLabels(['Meeting', 'Supplier']), []);

        $this->app->instance('test.row_scope', new class implements \Condoedge\Ai\Contracts\RowScopeResolverInterface {
            public function rowScopeIds(mixed $user): ?array { return [8]; }
        });
        config(['ai.security.row_scope_resolver' => 'test.row_scope']);
        $out = $this->section()->format('q', $this->contextWithLabels(['Meeting', 'Supplier']), ['user' => (object) ['id' => 35]]);

        $this->assertStringContainsString('(m:Meeting) → WHERE m.union_id IN $ai_row_scope_ids', $out);
        $this->assertStringContainsString('using the SAME bound parameter $ai_row_scope_ids:', $out);
        $this->assertStringContainsString('within the records this user may see', $out);
        $this->assertStringNotContainsString('$ai_team_ids', $out);
        $this->assertStringNotContainsString('Supplier', $out, 'no row path: not offered');

        config(['ai.security.row_scope_resolver' => null]);
        $this->assertSame($unnarrowed, $this->section()->format('q', $this->contextWithLabels(['Meeting', 'Supplier']), ['user' => (object) ['id' => 35]]));
        $this->assertStringContainsString('WHERE m.team_id IN $ai_team_ids', $unnarrowed);
        $this->assertStringContainsString('within the user\'s teams', $unnarrowed);
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
