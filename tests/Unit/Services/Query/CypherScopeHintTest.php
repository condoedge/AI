<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Services\Query\CypherScopeHint;
use PHPUnit\Framework\TestCase;

/**
 * Eval p12-1001-A team-roster-then-excel #1: the extension of a collapsed roster
 * (`WITH u, collect(DISTINCT tr.role)… AS role`) projected `t.contact_phone` after
 * that WITH. Neo4j answered "Variable `t` not defined" twice: the repair prompt
 * carried the raw error only, and the model kept `t.contact_phone`. The hint is
 * derived from the error text + the draft's own clauses (Cypher grammar, no
 * vocabulary): WHICH WITH dropped the variable, and how to carry it.
 */
class CypherScopeHintTest extends TestCase
{
    private const LIVE_DRAFT = 'MATCH (t:Team) WHERE t.id = 3 AND t.id IN $ai_team_ids '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
        . 'OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id '
        . 'WITH u, collect(DISTINCT tr.role)[0..11] AS role WHERE u IS NOT NULL '
        . 'RETURN u.name AS member_name, role, u.email AS email, t.contact_phone AS phone LIMIT 100';

    private const LIVE_ERROR = 'Neo.ClientError.Statement.SyntaxError: Variable `t` not defined (line 1, column 418 (offset: 417))';

    /** @test */
    public function the_live_error_names_the_with_that_dropped_the_variable(): void
    {
        $hint = CypherScopeHint::forError(self::LIVE_ERROR, self::LIVE_DRAFT);

        $this->assertNotNull($hint);
        $this->assertStringContainsString('`t`', $hint);
        $this->assertStringContainsString('WITH u, collect(DISTINCT tr.role)[0..11] AS role', $hint);
        $this->assertStringContainsString('does not carry', $hint);
        // Both structural fixes are offered: carry it, or aggregate it in that WITH.
        $this->assertStringContainsString('add `t` to that WITH', $hint);
        $this->assertStringContainsString('collect(', $hint);
    }

    /** @test */
    public function a_with_that_carries_the_variable_or_star_is_not_blamed(): void
    {
        $draft = 'MATCH (t:Team) MATCH (u:User) WITH * WITH t, u, count(*) AS n WITH u, n RETURN t.name, u.name, n';

        $hint = CypherScopeHint::forError('Variable `t` not defined', $draft);

        $this->assertNotNull($hint);
        $this->assertStringContainsString('WITH u, n', $hint);
        $this->assertStringNotContainsString('WITH t, u, count(*) AS n', $hint);
    }

    /** @test */
    public function an_alias_carried_by_the_with_counts_as_carrying_it(): void
    {
        $draft = 'MATCH (u:User) WITH u, u.team AS t WITH u RETURN t';

        $hint = CypherScopeHint::forError('Variable `t` not defined', $draft);

        $this->assertNotNull($hint);
        $this->assertStringContainsString('`WITH u`', $hint);
    }

    /** @test */
    public function starts_with_and_ends_with_operators_are_not_with_clauses(): void
    {
        $draft = "MATCH (t:Team) MATCH (u:User) WHERE u.name STARTS WITH 'A' AND u.email ENDS WITH '.ca' "
            . 'WITH u RETURN t.name, u.name';

        $hint = CypherScopeHint::forError('Variable `t` not defined', $draft);

        $this->assertNotNull($hint);
        $this->assertStringContainsString('`WITH u`', $hint);
    }

    /** @test */
    public function a_variable_never_bound_gets_a_binding_hint(): void
    {
        $hint = CypherScopeHint::forError('Variable `ab` not defined', 'MATCH (i:Invoice) RETURN sum(ab.total) LIMIT 100');

        $this->assertNotNull($hint);
        $this->assertStringContainsString('`ab`', $hint);
        $this->assertStringContainsString('never bound', $hint);
    }

    /** @test */
    public function other_errors_get_no_hint(): void
    {
        $this->assertNull(CypherScopeHint::forError('Unknown function `foo`', self::LIVE_DRAFT));
        $this->assertNull(CypherScopeHint::forError('', self::LIVE_DRAFT));
    }

    /** @test */
    public function a_quoted_with_never_counts_as_a_clause(): void
    {
        $draft = "MATCH (t:Team) MATCH (u:User) WHERE u.note = 'WITH u' RETURN t.name, u.name, x";

        $hint = CypherScopeHint::forError('Variable `x` not defined', $draft);

        $this->assertNotNull($hint);
        $this->assertStringContainsString('never bound', $hint);
    }
}
