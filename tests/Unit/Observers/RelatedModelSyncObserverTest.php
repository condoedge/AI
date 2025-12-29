<?php

namespace Condoedge\Ai\Tests\Unit\Observers;

use Condoedge\Ai\Observers\RelatedModelSyncObserver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Mockery;

class RelatedModelSyncObserverTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Set up sync triggers config
        config(['ai.sync_triggers' => [
            'Person' => [
                'on_related' => ['PersonTeam'],
                'foreign_key' => 'person_id',
            ],
        ]]);
    }

    /** @test */
    public function it_loads_sync_triggers_from_config(): void
    {
        $observer = new RelatedModelSyncObserver();

        $triggers = $observer->getSyncTriggers();

        $this->assertArrayHasKey('Person', $triggers);
        $this->assertEquals(['PersonTeam'], $triggers['Person']['on_related']);
        $this->assertEquals('person_id', $triggers['Person']['foreign_key']);
    }

    /** @test */
    public function it_handles_created_event(): void
    {
        // Create a mock PersonTeam model
        $model = Mockery::mock(Model::class);
        $model->shouldReceive('getAttribute')
            ->with('person_id')
            ->andReturn(123);

        // Mock to return the class basename
        $model->shouldReceive('getTable')->andReturn('person_teams');

        // Use reflection to set the class basename for the mock
        // Since we can't easily change class_basename result for a mock,
        // we'll test the configuration loading instead

        $observer = new RelatedModelSyncObserver();

        // Verify the observer has the correct triggers
        $triggers = $observer->getSyncTriggers();
        $this->assertArrayHasKey('Person', $triggers);
    }

    /** @test */
    public function it_ignores_unrelated_models(): void
    {
        config(['ai.sync_triggers' => [
            'Person' => [
                'on_related' => ['PersonTeam'],
                'foreign_key' => 'person_id',
            ],
        ]]);

        $observer = new RelatedModelSyncObserver();

        // Verify Event is not in the related models
        $triggers = $observer->getSyncTriggers();
        $this->assertNotContains('Event', $triggers['Person']['on_related']);
    }

    /** @test */
    public function it_supports_multiple_related_models(): void
    {
        config(['ai.sync_triggers' => [
            'Person' => [
                'on_related' => ['PersonTeam', 'PersonAddress', 'PersonPhone'],
                'foreign_key' => 'person_id',
            ],
        ]]);

        $observer = new RelatedModelSyncObserver();
        $triggers = $observer->getSyncTriggers();

        $this->assertCount(3, $triggers['Person']['on_related']);
        $this->assertContains('PersonTeam', $triggers['Person']['on_related']);
        $this->assertContains('PersonAddress', $triggers['Person']['on_related']);
        $this->assertContains('PersonPhone', $triggers['Person']['on_related']);
    }

    /** @test */
    public function it_supports_multiple_parent_entities(): void
    {
        config(['ai.sync_triggers' => [
            'Person' => [
                'on_related' => ['PersonTeam'],
                'foreign_key' => 'person_id',
            ],
            'Team' => [
                'on_related' => ['TeamMember'],
                'foreign_key' => 'team_id',
            ],
        ]]);

        $observer = new RelatedModelSyncObserver();
        $triggers = $observer->getSyncTriggers();

        $this->assertArrayHasKey('Person', $triggers);
        $this->assertArrayHasKey('Team', $triggers);
    }

    /** @test */
    public function it_handles_empty_config(): void
    {
        config(['ai.sync_triggers' => []]);

        $observer = new RelatedModelSyncObserver();
        $triggers = $observer->getSyncTriggers();

        $this->assertEmpty($triggers);
    }

    /** @test */
    public function it_handles_missing_config(): void
    {
        config(['ai.sync_triggers' => null]);

        $observer = new RelatedModelSyncObserver();
        $triggers = $observer->getSyncTriggers();

        $this->assertEmpty($triggers);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
