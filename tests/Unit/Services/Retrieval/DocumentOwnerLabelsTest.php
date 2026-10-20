<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Retrieval\DocumentOwnerLabels;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * WHOSE DOCUMENT a passage is, in words: each owner key becomes the record's kind
 * (the entity's display name) and NAME — read by id through the pipeline's scoped
 * executor, one lookup per distinct key — never an id.
 */
class DocumentOwnerLabelsTest extends TestCase
{
    /** @var list<array{0: string, 1: array, 2: array}> */
    private array $executed = [];

    public function setUp(): void
    {
        parent::setUp();
        config(['entities' => [
            'Tests\\Fixtures\\LabelUnit' => [
                'graph' => ['label' => 'Unit', 'properties' => ['id', 'name', 'union_id'], 'relationships' => []],
                'metadata' => ['display_name' => ['en' => 'unit', 'fr' => 'unité']],
            ],
            'Tests\\Fixtures\\LabelUnion' => [
                'graph' => ['label' => 'Union', 'properties' => ['id', 'name'], 'relationships' => []],
                'metadata' => ['display_name' => 'condo association'],
            ],
            'Tests\\Fixtures\\LabelContact' => [
                'graph' => ['label' => 'Contact', 'properties' => ['id', 'first_name', 'last_name', 'sin'], 'relationships' => []],
                'metadata' => ['name_properties' => ['first_name', 'last_name']],
            ],
            'Tests\\Fixtures\\LabelSecret' => [
                'graph' => ['label' => 'Secret', 'properties' => ['id', 'name'], 'relationships' => []],
                'security' => ['sensible_columns' => ['name']],
            ],
        ]]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function labels(array $rows): DocumentOwnerLabels
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturnUsing(function (string $cypher, array $parameters, array $options) use ($rows) {
            $this->executed[] = [$cypher, $parameters, $options];
            preg_match('/\(n:`?(\w+)`?\)/', $cypher, $m);
            $row = $rows[$m[1] . ':' . $parameters['ai_record_id']] ?? null;

            return new QueryExecutionResult(success: true, data: $row === null ? [] : [$row]);
        });

        return new DocumentOwnerLabels($executor);
    }

    /** @test */
    public function a_key_becomes_the_kind_and_the_name_of_its_record(): void
    {
        $labels = $this->labels(['Unit:873' => ['n0' => 'A-101'], 'Union:66' => ['n0' => 'Syndicat Viva Tour 1']])
            ->labels(['unit:873', 'union:66', 'unit:873'], ['user' => (object) ['id' => 433], 'skip_security' => true], 'en');

        $this->assertSame([
            'unit:873' => ['kind' => 'unit', 'name' => 'A-101'],
            'union:66' => ['kind' => 'condo association', 'name' => 'Syndicat Viva Tour 1'],
        ], $labels);

        $this->assertCount(2, $this->executed, 'one lookup per distinct key');
        [$cypher, $parameters, $options] = $this->executed[0];
        $this->assertStringContainsString('MATCH (n:`Unit`)', $cypher);
        $this->assertStringContainsString('n.id = $ai_record_id', $cypher);
        $this->assertSame(['ai_record_id' => 873], $parameters, 'the id is a bound parameter, typed');
        // The turn's own authority: read-only, never a security bypass.
        $this->assertTrue($options['read_only']);
        $this->assertArrayNotHasKey('skip_security', $options);
        $this->assertSame(433, $options['user']->id);
    }

    /** @test */
    public function the_kind_is_said_in_the_answers_language_and_a_split_name_is_joined(): void
    {
        $labels = $this->labels(['Unit:873' => ['n0' => 'A-101'], 'Contact:5' => ['n0' => 'Ada', 'n1' => 'Lovelace']])
            ->labels(['unit:873', 'contact:5'], [], 'fr');

        $this->assertSame(['kind' => 'unité', 'name' => 'A-101'], $labels['unit:873']);
        $this->assertSame(['kind' => 'contact', 'name' => 'Ada Lovelace'], $labels['contact:5']);
    }

    /** @test */
    public function a_record_that_cannot_be_named_gets_no_label_and_never_its_id(): void
    {
        $labels = $this->labels(['Unit:873' => ['n0' => null]])
            // not found for this user · no name · an unconfigured kind · a sensitive name
            ->labels(['unit:999', 'unit:873', 'ghost:1', 'secret:2'], [], 'en');

        $this->assertSame([], $labels);
    }

    /** @test */
    public function a_failing_lookup_never_fails_the_turn(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->andThrow(new \RuntimeException('graph is down'));

        $this->assertSame([], (new DocumentOwnerLabels($executor))->labels(['unit:873'], [], 'en'));
    }
}
