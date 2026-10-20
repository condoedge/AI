<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ResponseEntityEnricher;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 1.27 (e): the entity label for a row comes from `_label`, or from a
 * `type` column ONLY when it is a string naming a registered entity. A query
 * column aliased `type` holding a scalar domain value (live: `t.team_type AS
 * type` = 2) must never reach the strict-typed action lookup — it crashed the
 * whole turn with a TypeError.
 */
class ResponseEntityEnricherTest extends TestCase
{
    private EntityAutoDiscovery $discovery;

    public function setUp(): void
    {
        parent::setUp();

        config(['entities' => [
            'App\\Models\\Team' => ['graph' => ['label' => 'Team']],
        ]]);

        $this->discovery = Mockery::mock(EntityAutoDiscovery::class);
        $this->discovery->shouldReceive('getAuthorizedEntityActions')
            ->andReturnUsing(fn (string $label) => [
                ['action_key' => 'view', 'label' => 'View', 'aliases' => []],
            ])
            ->byDefault();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function an_integer_type_column_does_not_crash_and_is_not_an_entity_label(): void
    {
        $enricher = new ResponseEntityEnricher($this->discovery);

        $rows = $enricher->enrichEntityResults([['name' => 'Board', 'type' => 2]]);

        $this->assertSame([['name' => 'Board', 'type' => 2]], $rows);
    }

    /** @test */
    public function a_string_type_that_names_no_registered_entity_produces_no_actions(): void
    {
        $this->discovery->shouldReceive('getAuthorizedEntityActions')->never();
        $enricher = new ResponseEntityEnricher($this->discovery);

        $rows = $enricher->enrichEntityResults([['id' => 5, 'type' => 'SomeRandomWord']]);

        $this->assertSame([['id' => 5, 'type' => 'SomeRandomWord']], $rows);
    }

    /** @test */
    public function a_string_type_naming_a_registered_entity_is_still_enriched(): void
    {
        $enricher = new ResponseEntityEnricher($this->discovery);

        $rows = $enricher->enrichEntityResults([['id' => 5, 'type' => 'Team']]);

        $this->assertTrue($rows[0]['has_actions']);
        $this->assertSame('Team', $rows[0]['entity_type']);
    }

    /** @test */
    public function the_label_column_is_authoritative(): void
    {
        $enricher = new ResponseEntityEnricher($this->discovery);

        $rows = $enricher->enrichEntityResults([['id' => 5, '_label' => 'Team', 'type' => 2]]);

        $this->assertSame('Team', $rows[0]['entity_type']);
        $this->assertSame(2, $rows[0]['type']);
    }
}
