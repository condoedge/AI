<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\MoneyColumns;
use Condoedge\Ai\Tests\TestCase;

/**
 * P-3 (Phase 8-A review): only columns whose SOURCE PROPERTY is declared money in the
 * entity config (metadata.money_properties) are ever summed. Result columns map to
 * properties through the cypher's projections (`var.prop AS alias`, WITH chains,
 * collected map keys); anything unresolvable is not money.
 */
class MoneyColumnsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['entities' => [
            'App\\Models\\Bill' => ['graph' => ['label' => 'Bill'], 'metadata' => ['money_properties' => ['due_amount', 'total_amount']]],
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['money_properties' => ['outstanding_balance']]],
            'App\\Models\\Tax' => ['graph' => ['label' => 'Tax'], 'metadata' => ['common_properties' => ['rate' => 'Tax rate']]],
        ]]);
    }

    /** @test */
    public function a_projected_money_property_is_money_through_functions_and_aliases(): void
    {
        $money = MoneyColumns::fromCypher(
            'MATCH (u:Unit)-[:UNION]->(un:Union) WITH u, un, count(u) AS n '
            . 'RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, n AS open_invoices'
        );

        $this->assertSame(['balance'], $money);
    }

    /** @test */
    public function with_chains_and_collected_map_keys_resolve_to_the_source_property(): void
    {
        $money = MoneyColumns::fromCypher(
            "MATCH (b:Bill)-[:BELONGS_TO_UNION]->(u:Union) OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) "
            . "WITH s.name AS supplier, count(DISTINCT b) AS open_bills, round(sum(b.due_amount), 2) AS total_payable, min(b.due_at) AS oldest_due "
            . "ORDER BY total_payable DESC "
            . "WITH round(sum(total_payable), 2) AS total_payable_all_suppliers, sum(open_bills) AS open_bills_all_suppliers, "
            . "collect({supplier: supplier, open_bills: open_bills, total_payable: total_payable, oldest_due: oldest_due}) AS per_supplier "
            . "RETURN total_payable_all_suppliers, open_bills_all_suppliers, per_supplier"
        );

        sort($money);
        $this->assertSame(['total_payable', 'total_payable_all_suppliers'], $money);
    }

    /** @test */
    public function undeclared_decimals_and_mixed_expressions_are_never_money(): void
    {
        $this->assertSame([], MoneyColumns::fromCypher('MATCH (t:Tax) RETURN t.rate AS rate'));
        $this->assertSame([], MoneyColumns::fromCypher('MATCH (b:Bill)-[:TAX]->(t:Tax) RETURN b.due_amount * t.rate AS tax'));
        // Unaliased: the result column is keyed "b.due_amount" (Neo4j's own key), never "due_amount".
        $this->assertSame(['b.due_amount'], MoneyColumns::fromCypher('MATCH (b:Bill) RETURN b.due_amount'));
        $this->assertSame([], MoneyColumns::fromCypher('MATCH (b:Bill) RETURN b'));
        $this->assertSame([], MoneyColumns::fromCypher(''));
    }
}
