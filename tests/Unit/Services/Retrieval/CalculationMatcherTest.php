<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Services\Retrieval\CalculationMatcher;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;

/**
 * Task 5.11 — which canonical calculation (if any) the question asks for, by
 * embedding similarity between the question and each calculation's label +
 * description: the best match, plus any within a small margin of it, above a floor.
 */
class CalculationMatcherTest extends TestCase
{
    private const CALCS = [
        'team_total_owed' => [
            'label' => 'Total owed to the team (receivables outstanding)',
            'description' => 'What residents still owe.',
            'cypher' => 'MATCH (i:Invoice) WHERE i.due_amount >= 0.01 RETURN sum(i.due_amount)',
        ],
        'supplier_payables' => [
            'label' => 'Total owed TO suppliers',
            'description' => 'Payables.',
            'cypher' => 'MATCH (b:Bill) OPTIONAL MATCH (b)-[:SUPPLIER]->(s:Supplier) RETURN sum(b.due_amount)',
        ],
        'unit_balance' => [
            'label' => 'Outstanding balance of one unit',
            'description' => 'What a unit owes.',
            'cypher' => 'MATCH (u:Unit)-[:UNION]->(un:Union) OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) RETURN u.name',
        ],
    ];

    /** Unit vectors keyed by text: similarity = dot product. */
    private function embedding(array $vectors): EmbeddingProviderInterface
    {
        return new class($vectors) implements EmbeddingProviderInterface {
            public int $calls = 0;

            public function __construct(private array $vectors) {}

            public function embed(string $text): array
            {
                $this->calls++;

                return $this->vectors[$text] ?? [0.0, 0.0, 1.0];
            }

            public function embedBatch(array $texts): array
            {
                $this->calls++;

                return array_map(fn ($t) => $this->vectors[$t] ?? [0.0, 0.0, 1.0], $texts);
            }

            public function getDimensions(): int
            {
                return 3;
            }

            public function getModel(): string
            {
                return 'fake';
            }

            public function getMaxLength(): int
            {
                return 8000;
            }
        };
    }

    private function vectors(array $question): array
    {
        return [
            'Total owed to the team (receivables outstanding) — What residents still owe.' => [1.0, 0.0, 0.0],
            'Total owed TO suppliers — Payables.' => [0.0, 1.0, 0.0],
            'Outstanding balance of one unit — What a unit owes.' => [0.6, 0.8, 0.0],
            'q' => $question,
        ];
    }

    private function matcher(array $question, float $min = 0.45, float $margin = 0.05): CalculationMatcher
    {
        return new CalculationMatcher($this->embedding($this->vectors($question)), new AiLogger(), $min, $margin);
    }

    public function test_the_best_matching_calculation_is_returned(): void
    {
        // cos(q, team) = 0.9, cos(q, unit) = 0.54+0.08 = 0.62, cos(q, supplier) = 0.1
        $matched = $this->matcher([0.9, 0.1, 0.42])->match(self::CALCS, ['q']);

        $this->assertSame(['team_total_owed'], array_keys($matched));
    }

    public function test_near_ties_within_the_margin_are_all_returned_best_first(): void
    {
        // team 0.62, unit 0.6*0.62+0.8*0.6 = 0.852, supplier 0.6
        $matched = $this->matcher([0.62, 0.6, 0.505], margin: 0.3)->match(self::CALCS, ['q']);

        $this->assertSame(['unit_balance', 'team_total_owed', 'supplier_payables'], array_keys($matched));
    }

    public function test_nothing_matches_below_the_floor(): void
    {
        $this->assertSame([], $this->matcher([0.1, 0.1, 0.99])->match(self::CALCS, ['q']));
    }

    public function test_each_calculation_scores_its_best_query_phrasing(): void
    {
        $embedding = $this->embedding($this->vectors([0.0, 0.0, 1.0]) + ['rewrite' => [0.0, 1.0, 0.0]]);
        $matched = (new CalculationMatcher($embedding, new AiLogger()))->match(self::CALCS, ['q', 'rewrite']);

        $this->assertSame(['supplier_payables'], array_keys($matched));
    }

    public function test_it_cannot_judge_when_embedding_fails(): void
    {
        $failing = new class implements EmbeddingProviderInterface {
            public function embed(string $text): array { throw new \RuntimeException('down'); }
            public function embedBatch(array $texts): array { throw new \RuntimeException('down'); }
            public function getDimensions(): int { return 3; }
            public function getModel(): string { return 'fake'; }
            public function getMaxLength(): int { return 8000; }
        };

        // null = "could not judge" (callers fall back to label overlap), unlike [] = "judged: none".
        $this->assertNull((new CalculationMatcher($failing, new AiLogger()))->match(self::CALCS, ['q']));
    }

    public function test_calculation_labels_come_from_entities_or_the_cypher_node_patterns(): void
    {
        $this->assertSame(['Unit', 'Union', 'Invoice'], CalculationMatcher::labelsOf(self::CALCS['unit_balance']));
        $this->assertSame(['Bill', 'Supplier'], CalculationMatcher::labelsOf(self::CALCS['supplier_payables']));
        $this->assertSame(['TeamRole'], CalculationMatcher::labelsOf(['entities' => ['TeamRole'], 'cypher' => 'MATCH (i:Invoice)']));
        $this->assertSame([], CalculationMatcher::labelsOf(['formula' => 'no labels here']));
    }

    private const REACH = [
        'ledger' => ['label' => 'Ledger', 'entities' => ['Account', 'Entry'], 'reachable_alternative' => 'bills'],
        'bills' => ['label' => 'Bills', 'entities' => ['Bill']],
        'vault' => ['label' => 'Vault', 'entities' => ['Vault']],
    ];

    /** @test */
    public function an_unlimited_asker_keeps_every_match(): void
    {
        $this->assertSame(
            ['matched' => ['vault' => 0.5], 'out_of_reach' => false],
            CalculationMatcher::withinReach(['vault' => 0.5], self::REACH, null)
        );
    }

    /** @test */
    public function a_calculation_the_asker_cannot_read_gives_way_to_its_alternative(): void
    {
        $this->assertSame(
            ['matched' => ['bills' => 0.5], 'out_of_reach' => false],
            CalculationMatcher::withinReach(['ledger' => 0.5], self::REACH, ['Bill'])
        );
    }

    /** @test */
    public function nothing_left_to_run_is_out_of_reach_and_no_match_is_not(): void
    {
        $this->assertSame(['matched' => [], 'out_of_reach' => true], CalculationMatcher::withinReach(['vault' => 0.5], self::REACH, ['Bill']));
        $this->assertSame(['matched' => [], 'out_of_reach' => false], CalculationMatcher::withinReach([], self::REACH, ['Bill']));
    }
}
