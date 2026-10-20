<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\Services\Actions\Attach\RecordIdProjection;
use PHPUnit\Framework\TestCase;

/**
 * Actions plan B1 — record rows must carry their id so code can attach the record's link.
 *
 * Measured live 2026-10-01: with the QueryRules line in place, gpt-4o-mini still copied
 * its few-shot examples (`RETURN un.name, u.name, round(u.outstanding_balance,2), count(i)…`)
 * without `u.id` — no record chip for the three "310" units. This deterministic, purely
 * structural projection appends `v.id AS <label>_id` for every labelled node variable the
 * final RETURN uses as a RECORD (a property read outside any aggregate, the variable never
 * aggregated), and leaves every other query untouched.
 */
class RecordIdProjectionTest extends TestCase
{
    private function project(string $cypher, array $labels = ['Unit', 'Union', 'Invoice', 'Supplier', 'Bill', 'Meeting', 'TeamRole']): string
    {
        return (new RecordIdProjection($labels, ['id', '_id', 'neo4j_id', 'uuid']))->apply($cypher);
    }

    /** @test The live "unit 310" query: units and unions grouped, invoices aggregated. */
    public function record_variables_get_their_id_and_aggregated_ones_do_not(): void
    {
        $cypher = "MATCH (u:Unit)-[:UNION]->(un:Union) WHERE u.name = '310' AND un.team_id IN \$ai_team_ids "
            . "OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.status <> '1' "
            . "RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, "
            . "count(i) AS open_invoices, round(sum(i.due_amount), 2) AS invoices_due";

        $this->assertSame($cypher . ', un.id AS union_id, u.id AS unit_id', $this->project($cypher));
    }

    /** @test Inserted before ORDER BY / LIMIT, after a WITH chain that carries the variable. */
    public function the_id_goes_before_order_by_and_limit(): void
    {
        $cypher = 'MATCH (u:Unit)-[:UNION]->(un:Union) WITH u, un, count(*) AS n '
            . 'RETURN un.name AS union_name, u.name AS unit_name, n ORDER BY n DESC LIMIT 100';

        $this->assertSame(
            'MATCH (u:Unit)-[:UNION]->(un:Union) WITH u, un, count(*) AS n '
            . 'RETURN un.name AS union_name, u.name AS unit_name, n, un.id AS union_id, u.id AS unit_id ORDER BY n DESC LIMIT 100',
            $this->project($cypher)
        );
    }

    /** @test A grouped breakdown (`i.status, count(i)`) is NOT a record list — untouched. */
    public function an_aggregated_variable_is_never_a_record(): void
    {
        $cypher = 'MATCH (i:Invoice) RETURN i.status AS status, count(i) AS n';

        $this->assertSame($cypher, $this->project($cypher));
    }

    /** @test Totals, RETURN *, UNION, existing ids, alias collisions and unknown labels are untouched. */
    public function non_record_and_already_identified_queries_are_untouched(): void
    {
        foreach ([
            'MATCH (u:Unit) RETURN count(u) AS units',
            'MATCH (u:Unit) RETURN *',
            'MATCH (u:Unit) RETURN u.name AS name UNION MATCH (u:Unit) RETURN u.code AS name',
            'MATCH (u:Unit) RETURN u.id AS unit_id, u.name AS name',
            'MATCH (u:Unit) RETURN u.name AS unit_id',
            'MATCH (t:Task) RETURN t.title AS title',
            'MATCH (u:Unit) WITH u.name AS name RETURN name',
            'MATCH (s:Supplier) RETURN collect(s.name) AS names',
        ] as $cypher) {
            $this->assertSame($cypher, $this->project($cypher), $cypher);
        }
    }

    /** @test A supplier list with a per-supplier sum gets the supplier id (bills are aggregated). */
    public function a_per_record_aggregate_keeps_the_record_id(): void
    {
        $cypher = 'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) RETURN s.name AS supplier, sum(b.due_amount) AS due ORDER BY due DESC';

        $this->assertSame(
            'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) RETURN s.name AS supplier, sum(b.due_amount) AS due, s.id AS supplier_id ORDER BY due DESC',
            $this->project($cypher)
        );
    }

    /** @test DISTINCT, lowercase keywords, multi-word labels and string literals are handled. */
    public function distinct_lowercase_and_literals(): void
    {
        $this->assertSame(
            "match (m:Meeting) where m.name <> 'RETURN x' return distinct m.name as name, m.id_x as code, m.id as meeting_id",
            $this->project("match (m:Meeting) where m.name <> 'RETURN x' return distinct m.name as name, m.id_x as code, m.id as meeting_id"),
            'an existing m.id is kept as-is'
        );
        $this->assertSame(
            'MATCH (r:TeamRole) RETURN DISTINCT r.role AS role, r.id AS team_role_id',
            $this->project('MATCH (r:TeamRole) RETURN DISTINCT r.role AS role')
        );
    }

    // ------------------------------------------------------------ fix 3: label attribution

    /**
     * @test Fix 3: a variable DROPPED by a WITH and re-bound under another label is attributed
     * to the label it has AT the final RETURN (scope tracking) — never `x.id AS unit_id` for an
     * Owner.
     */
    public function a_variable_rebound_after_a_with_gets_its_current_label(): void
    {
        $cypher = 'MATCH (x:Unit) WITH count(x) AS c MATCH (x:Owner) RETURN x.name AS name, c';

        $this->assertSame($cypher, $this->project($cypher), 'Owner has no action → untouched, never unit_id');
        $this->assertSame($cypher . ', x.id AS owner_id', $this->project($cypher, ['Unit', 'Owner']));
    }

    /** @test Fix 3: two labels for one variable in scope (or a multi-label node) is ambiguous — skipped and flagged. */
    public function an_ambiguously_labelled_variable_is_skipped_and_flagged(): void
    {
        $projection = new RecordIdProjection(['Unit', 'Owner'], ['id']);

        foreach ([
            'MATCH (x:Unit) MATCH (x:Owner) RETURN x.name AS name',
            'MATCH (x:Unit:Owner) RETURN x.name AS name',
            'MATCH (x:Unit) WITH x MATCH (x:Owner) RETURN x.name AS name',
        ] as $cypher) {
            $result = $projection->project($cypher);
            $this->assertSame($cypher, $result['cypher'], $cypher);
            $this->assertTrue($result['ambiguous'], $cypher);
        }

        $clean = $projection->project('MATCH (u:Unit) RETURN u.name AS name');
        $this->assertSame('MATCH (u:Unit) RETURN u.name AS name, u.id AS unit_id', $clean['cypher']);
        $this->assertFalse($clean['ambiguous']);
    }

    /** @test Fix 3: an alias (`WITH u AS v`) and a pattern-comprehension variable are not in scope as records. */
    public function aliases_and_comprehension_variables_are_never_projected(): void
    {
        foreach ([
            'MATCH (u:Unit) WITH u AS v RETURN v.name AS name',
            'MATCH (t:Task) RETURN t.title AS title, [(t)-[:FOR]->(u:Unit) | u.name] AS units',
        ] as $cypher) {
            $this->assertSame($cypher, $this->project($cypher), $cypher);
        }
    }

    // ------------------------------------------------------------ fix 5: tail keywords

    /** @test Fix 5: properties / aliases named limit or skip, backtick names and comments never split the RETURN. */
    public function limit_and_skip_names_backticks_and_comments_are_not_clause_keywords(): void
    {
        $this->assertSame(
            'MATCH (u:Unit) RETURN u.name AS name, u.limit AS cap, u.id AS unit_id',
            $this->project('MATCH (u:Unit) RETURN u.name AS name, u.limit AS cap')
        );
        $this->assertSame(
            'MATCH (u:Unit) RETURN u.name AS `limit`, u.id AS unit_id ORDER BY `limit` LIMIT 5',
            $this->project('MATCH (u:Unit) RETURN u.name AS `limit` ORDER BY `limit` LIMIT 5')
        );
        $this->assertSame(
            "MATCH (u:Unit) // LIMIT 5 RETURN skip\nRETURN u.name AS name, u.id AS unit_id",
            $this->project("MATCH (u:Unit) // LIMIT 5 RETURN skip\nRETURN u.name AS name")
        );
    }
}
