<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\SensitiveLabelCoverageGate;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Tests\TestCase;

/**
 * Unit contract for the analyzer-independent {@see SensitiveLabelCoverageGate}.
 *
 * It must detect tenant-sensitive labels at EVERY depth (including inside CALL{}),
 * never match a label that only appears inside a string literal, and report a
 * coverage gap whenever the analyzer plan failed to enumerate a sensitive binding
 * the query text actually contains.
 */
class SensitiveLabelCoverageGateTest extends TestCase
{
    private SensitiveLabelCoverageGate $gate;
    private CypherScopeAnalyzer $analyzer;

    public function setUp(): void
    {
        parent::setUp();

        // Invoice is tenant-sensitive (has a team_path); Team is NOT.
        config()->set('ai.security.enabled', true);
        config()->set('entities.Invoice.security.team_path', 'team_id');

        $this->gate = new SensitiveLabelCoverageGate(new TeamPathResolver());
        $this->analyzer = new CypherScopeAnalyzer();
    }

    /** @test */
    public function detects_sensitive_label_at_top_level(): void
    {
        $this->assertTrue($this->gate->referencesSensitiveLabel('MATCH (i:Invoice) RETURN i'));
    }

    /** @test */
    public function detects_sensitive_label_inside_call_subquery(): void
    {
        $this->assertTrue(
            $this->gate->referencesSensitiveLabel('CALL { MATCH (i:Invoice) RETURN i } RETURN i')
        );
    }

    /** @test */
    public function detects_sensitive_label_inside_nested_call(): void
    {
        $this->assertTrue(
            $this->gate->referencesSensitiveLabel('CALL { CALL { MATCH (i:Invoice) RETURN i } RETURN i } RETURN i')
        );
    }

    /** @test */
    public function detects_backtick_quoted_sensitive_label(): void
    {
        $this->assertTrue($this->gate->referencesSensitiveLabel('MATCH (i:`Invoice`) RETURN i'));
    }

    /** @test */
    public function ignores_sensitive_label_inside_a_string_literal(): void
    {
        // The only occurrence of "Invoice" is inside a quoted string → opaque to the
        // lexer → not a node-label position → not flagged.
        $this->assertFalse(
            $this->gate->referencesSensitiveLabel('MATCH (n:Note) WHERE n.body = "see Invoice (x:Invoice)" RETURN n')
        );
    }

    /** @test */
    public function non_sensitive_label_is_not_flagged(): void
    {
        $this->assertFalse($this->gate->referencesSensitiveLabel('MATCH (t:Team) RETURN t'));
    }

    /** @test */
    public function plan_covers_a_simple_enumerated_binding(): void
    {
        $cypher = 'MATCH (i:Invoice) RETURN i';
        $plan = $this->analyzer->analyze($cypher);

        $this->assertTrue($this->gate->planCoversSensitiveBindings($cypher, $plan));
    }

    /** @test */
    public function plan_does_not_cover_a_binding_hidden_in_call_subquery(): void
    {
        // The analyzer rejects CALL{} (unsupported_subquery) and enumerates NO
        // binding, but the label is present → coverage gap.
        $cypher = 'CALL { MATCH (i:Invoice) RETURN i } RETURN i';
        $plan = $this->analyzer->analyze($cypher);

        $this->assertFalse($this->gate->planCoversSensitiveBindings($cypher, $plan));
    }

    /** @test */
    public function plan_does_not_cover_a_label_free_hop_reuse_shape(): void
    {
        $cypher = 'MATCH (i:Invoice) WITH i MATCH (i)-[:HAS_LINE]->(li) RETURN i, li';
        $plan = $this->analyzer->analyze($cypher);

        // The analyzer rejects the label-free hop, so it enumerates no binding even
        // though Invoice is present → coverage gap.
        $this->assertFalse($this->gate->planCoversSensitiveBindings($cypher, $plan));
    }

    /** @test */
    public function no_sensitive_label_is_trivially_covered(): void
    {
        $cypher = 'MATCH (t:Team) RETURN t';
        $plan = $this->analyzer->analyze($cypher);

        $this->assertTrue($this->gate->planCoversSensitiveBindings($cypher, $plan));
    }
}
