<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Tests\TestCase;

/**
 * Recognizer-level tests: the analyzer must accept ONLY in-subset queries and,
 * for every accepted query, enumerate EVERY tenant-sensitive node binding on its
 * own live alias. Out-of-subset shapes must be rejected with a teachable reason
 * (the caller then fails closed).
 *
 * These are the adversarial corpus (§6.2 of the AST tenant-scoping design)
 * expressed as recognizer assertions.
 */
class CypherScopeAnalyzerTest extends TestCase
{
    private CypherScopeAnalyzer $analyzer;

    public function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CypherScopeAnalyzer();
    }

    /** @return list<array{alias:string,label:string}> */
    private function bindingTuples(\Condoedge\Ai\Services\Security\Scope\ScopePlan $plan): array
    {
        $out = [];
        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $b) {
                $out[] = ['alias' => $b->alias, 'label' => $b->label];
            }
        }
        return $out;
    }

    private function assertScoped(string $cypher, array $expectedBindings): void
    {
        $plan = $this->analyzer->analyze($cypher);
        $this->assertTrue(
            $plan->isScopeable,
            "Expected scopeable, got rejected ({$plan->reason}) for: {$cypher}"
        );
        $this->assertEqualsCanonicalizing(
            $expectedBindings,
            $this->bindingTuples($plan),
            "Binding set mismatch for: {$cypher}"
        );
    }

    private function assertRejected(string $cypher, ?string $reason = null): void
    {
        $plan = $this->analyzer->analyze($cypher);
        $this->assertFalse(
            $plan->isScopeable,
            "Expected rejection but query was accepted: {$cypher}"
        );
        if ($reason !== null) {
            $this->assertSame($reason, $plan->reason, "Wrong rejection reason for: {$cypher}");
        }
    }

    // ---- Simple / control shapes (must stay scopeable) ----------------------

    /** @test */
    public function simple_match_return_is_scoped_on_its_alias(): void
    {
        $this->assertScoped('MATCH (i:Invoice) RETURN i', [['alias' => 'i', 'label' => 'Invoice']]);
    }

    /** @test */
    public function match_where_return_is_scoped(): void
    {
        $this->assertScoped(
            'MATCH (i:Invoice) WHERE i.status = "active" RETURN i',
            [['alias' => 'i', 'label' => 'Invoice']]
        );
    }

    /** @test */
    public function scalar_query_has_no_sensitive_binding(): void
    {
        $plan = $this->analyzer->analyze('RETURN 1');
        $this->assertTrue($plan->isScopeable);
        $this->assertFalse($plan->hasSensitiveBindings());
        $this->assertFalse($plan->accessesNodes);
    }

    /** @test */
    public function introspection_call_has_no_sensitive_binding(): void
    {
        $plan = $this->analyzer->analyze('CALL db.labels()');
        $this->assertTrue($plan->isScopeable);
        $this->assertFalse($plan->hasSensitiveBindings());
    }

    // ---- Corpus: G1 leading WITH / anchor placement -------------------------

    /** @test */
    public function corpus_1_leading_with_const_before_match(): void
    {
        // The anchor for the Invoice scope is the RETURN, not the leading WITH 1 AS x.
        $this->assertScoped(
            'WITH 1 AS one MATCH (i:Invoice) RETURN i',
            [['alias' => 'i', 'label' => 'Invoice']]
        );
    }

    // ---- Corpus: 4 label-free primary --------------------------------------

    /** @test */
    public function corpus_4_label_free_primary_is_rejected(): void
    {
        $this->assertRejected('MATCH (n {id: 1}) RETURN n', 'label_free_sensitive');
    }

    // ---- Corpus: 5 sensitive root is 2nd MATCH, RETURN aliases child --------

    /** @test */
    public function corpus_5_scopes_the_invoice_root_not_the_returned_child(): void
    {
        // RETURN projects li (LineItem), but the Invoice root must still be scoped.
        $this->assertScoped(
            'MATCH (i:Invoice) MATCH (i)-[:HAS]->(li:LineItem) RETURN li',
            [
                ['alias' => 'i', 'label' => 'Invoice'],
                ['alias' => 'li', 'label' => 'LineItem'],
            ]
        );
    }

    // ---- Corpus: 6 RETURN hides sensitive root ------------------------------

    /** @test */
    public function corpus_6_scopes_person_even_though_org_is_returned(): void
    {
        $this->assertScoped(
            'MATCH (p:Person)-[:WORKS_AT]->(o:Organization) RETURN o',
            [
                ['alias' => 'p', 'label' => 'Person'],
                ['alias' => 'o', 'label' => 'Organization'],
            ]
        );
    }

    // ---- Corpus: 7 multi-MATCH two roots → both scoped ----------------------

    /** @test */
    public function corpus_7_multi_match_two_roots_both_scoped(): void
    {
        $this->assertScoped(
            'MATCH (i:Invoice) MATCH (p:Person) RETURN i, p',
            [
                ['alias' => 'i', 'label' => 'Invoice'],
                ['alias' => 'p', 'label' => 'Person'],
            ]
        );
    }

    // ---- Corpus: 8 / 9 CALL{} non-count-wrapper → reject --------------------

    /** @test */
    public function corpus_8_arbitrary_call_subquery_is_rejected(): void
    {
        $this->assertRejected(
            'MATCH (t:Team) CALL { MATCH (i:Invoice) RETURN i } RETURN t',
            'unsupported_subquery'
        );
    }

    /** @test */
    public function corpus_9_aggregating_call_subquery_is_rejected(): void
    {
        $this->assertRejected(
            'CALL { MATCH (i:Invoice) WITH i RETURN i } RETURN count(*) AS c, i',
            'unsupported_subquery'
        );
    }

    /** @test */
    public function count_wrapper_is_accepted_and_inner_scoped(): void
    {
        // Our own executeCount() form: outer untouched, inner Invoice scoped.
        $this->assertScoped(
            'CALL { MATCH (i:Invoice) RETURN i } RETURN count(*) AS total',
            [['alias' => 'i', 'label' => 'Invoice']]
        );
    }

    // ---- Corpus: 10 / 11 UNION branches scoped on own bindings --------------

    /** @test */
    public function corpus_10_union_each_branch_scoped_on_own_binding(): void
    {
        $this->assertScoped(
            'MATCH (i:Invoice) RETURN i UNION MATCH (p:Person) RETURN p',
            [
                ['alias' => 'i', 'label' => 'Invoice'],
                ['alias' => 'p', 'label' => 'Person'],
            ]
        );
    }

    /** @test */
    public function corpus_11_union_alias_collision_different_labels(): void
    {
        // Same alias x in both branches but different labels — each scoped on its own.
        $this->assertScoped(
            'MATCH (x:Invoice) RETURN x UNION MATCH (x:Payment) RETURN x',
            [
                ['alias' => 'x', 'label' => 'Invoice'],
                ['alias' => 'x', 'label' => 'Payment'],
            ]
        );
    }

    // ---- Corpus: 12 top-level OR + 2nd MATCH in head ------------------------

    /** @test */
    public function corpus_12_top_level_or_with_following_match(): void
    {
        $plan = $this->analyzer->analyze(
            'MATCH (i:Invoice) WHERE i.a = 1 OR i.b = 2 MATCH (i)-[:HAS]->(li:LineItem) RETURN i'
        );
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
        // OR detection is for the Invoice scope.
        $this->assertTrue($plan->scopes[0]->whereHasTopLevelOr);
    }

    // ---- Corpus: 13 / 14 sensitive comprehension binding → reject -----------

    /** @test */
    public function corpus_13_sensitive_pattern_comprehension_is_rejected(): void
    {
        $this->assertRejected(
            'MATCH (a:Account) RETURN [(a)-->(i:Invoice) | i.total] AS totals',
            'unsupported_pattern_scope'
        );
    }

    /** @test */
    public function corpus_14_comprehension_only_no_top_match_is_rejected(): void
    {
        $this->assertRejected(
            'WITH 1 AS x RETURN [(n)-->(i:Invoice) | i] AS xs',
            'unsupported_pattern_scope'
        );
    }

    // ---- Corpus: 17 OPTIONAL MATCH roots ------------------------------------

    /** @test */
    public function corpus_17_optional_match_single_is_scoped(): void
    {
        $this->assertScoped(
            'OPTIONAL MATCH (i:Invoice) RETURN i',
            [['alias' => 'i', 'label' => 'Invoice']]
        );
    }

    /** @test */
    public function corpus_17_match_plus_optional_match_both_scoped(): void
    {
        $this->assertScoped(
            'MATCH (a:Account) OPTIONAL MATCH (i:Invoice) RETURN a, i',
            [
                ['alias' => 'a', 'label' => 'Account'],
                ['alias' => 'i', 'label' => 'Invoice'],
            ]
        );
    }

    // ---- Corpus: 18 label-free RETURN, no labelled binding -> reject --------

    /** @test */
    public function corpus_18_label_free_node_access_is_rejected(): void
    {
        $this->assertRejected('MATCH (n) RETURN n', 'label_free_sensitive');
    }

    // ---- Corpus: 19 WITH-agg then 2nd sensitive MATCH -----------------------

    /** @test */
    public function corpus_19_with_aggregation_then_second_match(): void
    {
        // Two parts of the same query: i scoped before the WITH boundary; the
        // analyzer must treat the WITH as the anchor for the first scope.
        $plan = $this->analyzer->analyze(
            'MATCH (i:Invoice) WITH i WHERE i.total > 0 RETURN i'
        );
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
        $this->assertEqualsCanonicalizing(
            [['alias' => 'i', 'label' => 'Invoice']],
            $this->bindingTuples($plan)
        );
    }

    /** @test */
    public function with_that_projects_entity_away_is_rejected(): void
    {
        // MATCH (i:Invoice) WITH i.total AS t RETURN t — i is projected away before
        // the RETURN anchor; but the WITH itself is the anchor for the Invoice
        // scope, so i is live AT the WITH and scopeable there.
        $this->assertScoped(
            'MATCH (i:Invoice) WITH i.total AS t RETURN t',
            [['alias' => 'i', 'label' => 'Invoice']]
        );
    }

    // ---- Corpus: 22 UNION inside CALL{} -> reject ---------------------------

    /** @test */
    public function corpus_22_union_inside_call_is_rejected(): void
    {
        $this->assertRejected(
            'CALL { MATCH (i:Invoice) RETURN i UNION MATCH (p:Person) RETURN p } RETURN count(*) AS total',
            'unsupported_subquery'
        );
    }

    // ---- Corpus: 23 label inside string literal -----------------------------

    /** @test */
    public function corpus_23_label_in_string_literal_is_ignored(): void
    {
        // 'Invoice' inside a string must not be treated as a pattern; only n:Note.
        $this->assertScoped(
            'MATCH (n:Note) WHERE n.body = "see Invoice (x:Secret)" RETURN n',
            [['alias' => 'n', 'label' => 'Note']]
        );
    }

    // ---- Corpus: 24 multi-statement -> reject -------------------------------

    /** @test */
    public function corpus_24_multi_statement_is_rejected(): void
    {
        $this->assertRejected('MATCH (i:Invoice) RETURN i; MATCH (p:Person) RETURN p', 'multi_statement');
    }

    /** @test */
    public function trailing_semicolon_is_allowed(): void
    {
        $this->assertScoped('MATCH (i:Invoice) RETURN i;', [['alias' => 'i', 'label' => 'Invoice']]);
    }

    // ---- Corpus: 25 two Invoice roots via Unit ------------------------------

    /** @test */
    public function corpus_25_two_invoice_roots_both_scoped(): void
    {
        $this->assertScoped(
            'MATCH (i:Invoice) MATCH (i2:Invoice) RETURN i, i2',
            [
                ['alias' => 'i', 'label' => 'Invoice'],
                ['alias' => 'i2', 'label' => 'Invoice'],
            ]
        );
    }

    // ---- No-anchor rejection ------------------------------------------------

    /** @test */
    public function no_anchor_is_rejected(): void
    {
        $this->assertRejected('MATCH (i:Invoice)', 'no_anchor');
    }

    // ---- Backtick label (control 20) ---------------------------------------

    /** @test */
    public function backtick_label_is_recognized(): void
    {
        $this->assertScoped('MATCH (i:`Invoice`) RETURN i', [['alias' => 'i', 'label' => 'Invoice']]);
    }
}
