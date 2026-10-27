<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\ScopeAcceptanceGate;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Tests\TestCase;

/**
 * The acceptance gate is the PRIMARY safety mechanism for the generator-emits-scope
 * path: given the analyzer's ScopePlan and a per-binding REQUIRED rule (the resolver
 * template), it proves every sensitive binding already carries its $ai_team_ids
 * predicate on its OWN live alias / required path. It ACCEPTS a fully self-scoped
 * query and REJECTS (→ caller fall back to the injector / fail closed) any query
 * with a missing, wrong-alias, inlined, or wrong-shape predicate.
 *
 * It never trusts the generator for safety: a missed binding yields false (reject),
 * never true.
 */
class ScopeAcceptanceGateTest extends TestCase
{
    private CypherScopeAnalyzer $analyzer;
    private ScopeAcceptanceGate $gate;

    public function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CypherScopeAnalyzer();
        $this->gate = new ScopeAcceptanceGate();
    }

    /**
     * Per-binding required rule: a direct-column predicate on the binding's own
     * alias (the common Invoice/Person/Contact case).
     */
    private function directRule(): callable
    {
        return static fn(NodeBinding $b): ?array => [
            'type' => 'direct',
            'kind' => 'where',
            'alias' => $b->alias,
            'column' => 'team_id',
            'filter' => "WHERE {$b->alias}.team_id IN \$ai_team_ids",
        ];
    }

    private function isSelfScoped(string $cypher, ?callable $ruleFor = null): bool
    {
        $plan = $this->analyzer->analyze($cypher);
        return $this->gate->isFullySelfScoped($cypher, $plan, $ruleFor ?? $this->directRule());
    }

    // =====================================================================
    // ACCEPT: a generator-scoped query passes the gate.
    // =====================================================================

    /** @test */
    public function accepts_a_single_binding_scoped_on_its_own_alias(): void
    {
        $this->assertTrue(
            $this->isSelfScoped('MATCH (i:Invoice) WHERE i.team_id IN $ai_team_ids RETURN i')
        );
    }

    /** @test */
    public function a_row_rule_needs_its_own_parameter(): void
    {
        $rowRule = static fn(NodeBinding $b): ?array => [
            'type' => 'direct',
            'kind' => 'where',
            'alias' => $b->alias,
            'column' => 'union_id',
            'filter' => "WHERE {$b->alias}.union_id IN \$ai_row_scope_ids",
            'param' => '$ai_row_scope_ids',
        ];

        $this->assertTrue($this->isSelfScoped('MATCH (m:Meeting) WHERE m.union_id IN $ai_row_scope_ids RETURN m', $rowRule));
        $this->assertFalse($this->isSelfScoped('MATCH (m:Meeting) WHERE m.union_id IN $ai_team_ids RETURN m', $rowRule));
        $this->assertFalse($this->isSelfScoped('MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids RETURN m', $rowRule));
        // A rule without `param` keeps the team parameter.
        $this->assertFalse($this->isSelfScoped('MATCH (i:Invoice) WHERE i.team_id IN $ai_row_scope_ids RETURN i'));
    }

    /** @test */
    public function accepts_when_team_predicate_is_anded_into_an_existing_where(): void
    {
        $this->assertTrue($this->isSelfScoped(
            'MATCH (i:Invoice) WHERE i.status = "active" AND i.team_id IN $ai_team_ids RETURN i'
        ));
    }

    /** @test */
    public function accepts_multi_binding_when_every_binding_is_scoped(): void
    {
        $this->assertTrue($this->isSelfScoped(
            'MATCH (i:Invoice) MATCH (i)-[:HAS]->(li:LineItem) '
            . 'WHERE i.team_id IN $ai_team_ids AND li.team_id IN $ai_team_ids RETURN i, li'
        ));
    }

    /** @test */
    public function accepts_each_union_branch_scoped_independently(): void
    {
        $this->assertTrue($this->isSelfScoped(
            'MATCH (i:Invoice) WHERE i.team_id IN $ai_team_ids RETURN i AS r '
            . 'UNION MATCH (p:Person) WHERE p.team_id IN $ai_team_ids RETURN p AS r'
        ));
    }

    // =====================================================================
    // REJECT: a missing / wrong / inlined / sibling-only predicate.
    // =====================================================================

    /** @test */
    public function rejects_a_binding_with_no_team_predicate(): void
    {
        // The leak-impossibility proof of the NEW path: ONE unscoped sensitive
        // binding → gate refuses (caller then falls back / fails closed).
        $this->assertFalse(
            $this->isSelfScoped('MATCH (i:Invoice) RETURN i')
        );
    }

    /** @test */
    public function rejects_when_one_of_several_bindings_is_unscoped(): void
    {
        // i is scoped, li is NOT → the whole query is rejected (no partial accept).
        $this->assertFalse($this->isSelfScoped(
            'MATCH (i:Invoice) MATCH (i)-[:HAS]->(li:LineItem) '
            . 'WHERE i.team_id IN $ai_team_ids RETURN i, li'
        ));
    }

    /** @test */
    public function rejects_a_predicate_on_the_wrong_alias(): void
    {
        // The predicate references x (not the bound i) → i is unscoped.
        $this->assertFalse($this->isSelfScoped(
            'MATCH (i:Invoice) WHERE x.team_id IN $ai_team_ids RETURN i'
        ));
    }

    /** @test */
    public function rejects_inlined_team_ids_instead_of_the_bound_parameter(): void
    {
        $this->assertFalse($this->isSelfScoped(
            'MATCH (i:Invoice) WHERE i.team_id IN [1, 2] RETURN i'
        ));
    }

    /** @test */
    public function rejects_when_only_a_sibling_union_branch_is_scoped(): void
    {
        // First branch scoped, second branch NOT — the sibling predicate must not
        // satisfy the unscoped branch.
        $this->assertFalse($this->isSelfScoped(
            'MATCH (i:Invoice) WHERE i.team_id IN $ai_team_ids RETURN i AS r '
            . 'UNION MATCH (p:Person) RETURN p AS r'
        ));
    }

    /** @test */
    public function rejects_a_predicate_hidden_in_a_string_literal(): void
    {
        // The only "i.team_id IN $ai_team_ids" appears inside a string literal — it
        // does NOT scope the binding.
        $this->assertFalse($this->isSelfScoped(
            'MATCH (i:Invoice) WHERE i.note = "i.team_id IN $ai_team_ids" RETURN i'
        ));
    }

    /** @test */
    public function rejects_direct_shape_when_a_relationship_path_is_required(): void
    {
        // The label requires a relationship traversal, but the query emitted a plain
        // direct predicate on the alias → wrong shape → reject (fall back to injector).
        $relRule = static fn(NodeBinding $b): ?array => [
            'type' => 'relationship',
            'kind' => 'match',
            'alias' => $b->alias,
            'column' => null,
            'filter' => "MATCH ({$b->alias})-[:MEMBER_OF]->(t:Team) WHERE t.id IN \$ai_team_ids",
        ];

        $this->assertFalse($this->isSelfScoped(
            'MATCH (p:Person) WHERE p.team_id IN $ai_team_ids RETURN p',
            $relRule
        ));
    }

    // =====================================================================
    // Not-tenant-scoped labels need no predicate.
    // =====================================================================

    /** @test */
    public function accepts_a_binding_whose_label_is_not_tenant_scoped(): void
    {
        // ruleFor returns null → no predicate required → satisfied.
        $nullRule = static fn(NodeBinding $b): ?array => null;

        $this->assertTrue($this->isSelfScoped('MATCH (s:SystemSetting) RETURN s', $nullRule));
    }

    /** @test */
    public function accepts_multi_binding_when_every_binding_is_direct_scoped(): void
    {
        // The live multi-binding shape (design case d) reduced to all-direct labels:
        // Contact + Union both scoped directly on their own alias. The gate accepts.
        $rule = static fn(NodeBinding $b): ?array => match ($b->label) {
            'Contact', 'Union' => [
                'type' => 'direct', 'kind' => 'where', 'alias' => $b->alias,
                'column' => 'team_id', 'filter' => "WHERE {$b->alias}.team_id IN \$ai_team_ids",
            ],
            'ContactUnit', 'Unit' => null, // join / non-scoped nodes
            default => null,
        };

        $cypher = 'MATCH (c:Contact)<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) '
            . 'MATCH (u)-[:BELONGS_TO]->(un:Union) '
            . 'WHERE c.team_id IN $ai_team_ids AND un.team_id IN $ai_team_ids RETURN c';

        $this->assertTrue($this->isSelfScoped($cypher, $rule));
    }

    /** @test */
    public function rejects_multi_binding_when_a_direct_binding_is_unscoped(): void
    {
        // Contact is scoped, Union is NOT → the whole query is rejected (→ injector
        // fallback), never partially accepted.
        $rule = static fn(NodeBinding $b): ?array => match ($b->label) {
            'Contact', 'Union' => [
                'type' => 'direct', 'kind' => 'where', 'alias' => $b->alias,
                'column' => 'team_id', 'filter' => "WHERE {$b->alias}.team_id IN \$ai_team_ids",
            ],
            'ContactUnit', 'Unit' => null,
            default => null,
        };

        $cypher = 'MATCH (c:Contact)<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) '
            . 'MATCH (u)-[:BELONGS_TO]->(un:Union) '
            . 'WHERE c.team_id IN $ai_team_ids RETURN c';

        $this->assertFalse($this->isSelfScoped($cypher, $rule));
    }

    /** @test */
    public function defers_relationship_and_parent_kinds_to_the_injector(): void
    {
        // SAFETY POSTURE: a relationship/parent (match-kind) binding is NEVER accepted
        // as self-scoped — its intermediate aliases are the generator's free choice
        // and cannot be proven leak-free by structural matching. The gate returns
        // false so the proven injector re-scopes it, even when the query LOOKS
        // correctly scoped. A false-negative here is harmless; a false-positive would
        // be a leak.
        $relRule = static fn(NodeBinding $b): ?array => match ($b->label) {
            'Person' => [
                'type' => 'relationship', 'kind' => 'match', 'alias' => $b->alias, 'column' => null,
                'filter' => "MATCH ({$b->alias})-[:MEMBER_OF]->(t:Team) WHERE t.id IN \$ai_team_ids",
            ],
            'Team' => null, // ignore the traversal's helper node for this test
            default => null,
        };

        // The query already MATCHes the traversal and constrains the team — yet the
        // gate still defers (returns false) because the shape is match-kind.
        $this->assertFalse($this->isSelfScoped(
            'MATCH (p:Person) MATCH (p)-[:MEMBER_OF]->(tm:Team) WHERE tm.id IN $ai_team_ids RETURN p',
            $relRule
        ));
    }
}
