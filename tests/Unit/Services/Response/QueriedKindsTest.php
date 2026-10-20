<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\QueriedKinds;
use Condoedge\Ai\Tests\TestCase;

/**
 * QueriedKinds — what an executed query LOOKED FOR (eval p18-B rights t2: the empty answer
 * named Query Understanding's kinds — "union records, and fund records" — not the query's).
 * Structural: the node variables the projections read, never the nodes only walked through.
 */
class QueriedKindsTest extends TestCase
{
    /** The p18-B rights t2 query. */
    private const CONTRIBUTION = 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL '
        . "MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.customer_type = 'unit' AND i.status IN ['2', '3', '4'] "
        . 'RETURN SUM(i.total_amount) AS contribution_amount';

    /** @test */
    public function the_labels_are_those_the_final_return_reads_in_its_order(): void
    {
        $this->assertSame(['Invoice'], QueriedKinds::labels(self::CONTRIBUTION));
        $this->assertSame(
            ['Unit', 'Union', 'Invoice'],
            QueriedKinds::labels('MATCH (i:Invoice)-[:CUSTOMER]->(u:Unit)-[:UNION]->(un:Union) RETURN u.name AS unit, un.name AS union_name, count(i) AS invoices, u.id AS unit_id')
        );
        $this->assertSame(['Unit'], QueriedKinds::labels('MATCH (u:Unit)-[:UNION]->(un:Union) WHERE un.id = 3 RETURN u'));
    }

    /** @test A property, a function name, a parameter or a string is never read as a variable. */
    public function only_variables_count(): void
    {
        $cypher = "MATCH (name:Unit)-[:UNION]->(un:Union) WHERE un.team_id IN \$un RETURN toUpper(un.name) AS label, 'name' AS kind, \$name AS asked";

        $this->assertSame(['Union'], QueriedKinds::labels($cypher));
    }

    /** @test A RETURN that only passes aliases on is read through the WITH that computed them. */
    public function a_return_of_aliases_is_read_through_its_with(): void
    {
        $cypher = 'MATCH (un:Union)<-[:UNION]-(u:Unit) MATCH (i:Invoice)-[:CUSTOMER]->(u) '
            . "WHERE i.number STARTS WITH 'INV' WITH un, sum(i.due_amount) AS due, count(DISTINCT u) AS units RETURN due, units";

        $this->assertSame(['Invoice', 'Unit'], QueriedKinds::labels($cypher), 'the carried variable (un) is not read');
    }

    /** @test */
    public function nothing_is_read_from_no_query_or_an_unreadable_one(): void
    {
        $this->assertSame([], QueriedKinds::labels(null));
        $this->assertSame([], QueriedKinds::labels('  '));
        $this->assertSame([], QueriedKinds::labels('RETURN 1 AS one'));
        $this->assertSame([], QueriedKinds::names(null));
    }

    /** @test The names are the host's: plural, else display name, else the label as words; a structural entity is skipped. */
    public function the_names_are_the_hosts_words(): void
    {
        config([
            'ai.response_generation.property_header_language' => 'en',
            'entities' => [
                'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['display_name' => 'invoice', 'display_name_plural' => 'invoices']],
                'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['display_name' => 'unit']],
                'App\\Models\\Meeting' => ['graph' => ['label' => 'Meeting'], 'metadata' => ['display_name_plural' => ['en' => 'meetings', 'fr' => 'réunions']]],
                'App\\Models\\AccountBalance' => ['graph' => ['label' => 'AccountBalance'], 'metadata' => ['askable' => false]],
            ],
        ]);

        $this->assertSame(['invoices'], QueriedKinds::names(self::CONTRIBUTION));
        $this->assertSame(
            ['unit', 'meetings', 'board member'],
            QueriedKinds::names('MATCH (u:Unit), (m:Meeting), (b:BoardMember), (ab:AccountBalance) RETURN u.name, m.name, b.year, ab.amount')
        );
    }
}
