<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Services\Query\AggregateOrderBy;
use Condoedge\Ai\Tests\TestCase;

/**
 * Neo4j refuses an ORDER BY on a property the aggregating RETURN does not project ("it is not
 * possible to access variables declared before the WITH/RETURN"); the model writes it, and its
 * repair writes it again (live 8-data period-this-month). Code projects it instead.
 */
class AggregateOrderByTest extends TestCase
{
    /** @test */
    public function an_order_on_an_unprojected_property_of_a_grouped_record_is_projected(): void
    {
        $cypher = "MATCH (c:Campaign) OPTIONAL MATCH (o:Order)-[:BELONGS_TO_CAMPAIGN]->(c) RETURN c.name AS campaign, "
            . "round(coalesce(sum(o.net_amount), 0), 2) AS sales, c.id AS campaign_id ORDER BY c.start_date DESC LIMIT 100";

        $this->assertSame(
            "MATCH (c:Campaign) OPTIONAL MATCH (o:Order)-[:BELONGS_TO_CAMPAIGN]->(c) RETURN c.name AS campaign, "
            . "round(coalesce(sum(o.net_amount), 0), 2) AS sales, c.id AS campaign_id, c.start_date AS c_start_date ORDER BY c_start_date DESC LIMIT 100",
            AggregateOrderBy::fix($cypher)
        );
    }

    /** @test */
    public function an_order_on_a_property_already_returned_uses_its_alias(): void
    {
        $this->assertSame(
            'MATCH (c:Campaign) RETURN c.id AS id, c.start_date AS started, count(*) AS n ORDER BY started',
            AggregateOrderBy::fix('MATCH (c:Campaign) RETURN c.id AS id, c.start_date AS started, count(*) AS n ORDER BY c.start_date')
        );
    }

    /** @test */
    public function anything_else_is_left_as_written(): void
    {
        foreach ([
            'MATCH (c:Campaign) RETURN c.name AS name ORDER BY c.start_date DESC',
            'MATCH (c:Campaign) RETURN c.name AS name, count(*) AS n ORDER BY n DESC',
            'MATCH (c:Campaign) RETURN c.name AS name, count(*) AS n ORDER BY c.start_date DESC',
            'MATCH (c:Campaign) RETURN c.id AS id, count(*) AS n ORDER BY c.start_date UNION MATCH (c:Campaign) RETURN c.id AS id, 0 AS n',
        ] as $cypher) {
            $this->assertSame($cypher, AggregateOrderBy::fix($cypher), $cypher);
        }
    }
}
