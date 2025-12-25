<?php

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;

class SensibleColumnsDiscoveryTest extends TestCase
{
    private EntityAutoDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discovery = app(EntityAutoDiscovery::class);
    }

    /** @test */
    public function it_discovers_sensible_columns_from_model(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'persons';
            protected $sensibleColumns = ['ssn', 'date_of_birth', 'salary'];
        };

        $config = $this->callDiscoverSecurityConfig($model);

        $this->assertEquals(['ssn', 'date_of_birth', 'salary'], $config['sensible_columns']);
    }

    /** @test */
    public function it_returns_empty_array_when_no_sensible_columns(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'events';
            protected $fillable = ['name', 'description'];
        };

        $config = $this->callDiscoverSecurityConfig($model);

        $this->assertEquals([], $config['sensible_columns']);
    }

    /** @test */
    public function it_excludes_sensible_columns_from_embed_fields(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'persons';
            protected $fillable = ['name', 'bio', 'ssn', 'medical_notes'];
            protected $sensibleColumns = ['ssn', 'medical_notes'];
        };

        $config = $this->callDiscoverVector($model);

        // Sensible columns should NOT be in embed_fields
        $this->assertNotContains('ssn', $config['embed_fields']);
        $this->assertNotContains('medical_notes', $config['embed_fields']);
    }

    /**
     * Helper method to call protected discoverSecurityConfig method
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return array
     */
    private function callDiscoverSecurityConfig($model): array
    {
        $reflection = new \ReflectionClass($this->discovery);
        $method = $reflection->getMethod('discoverSecurityConfig');
        $method->setAccessible(true);

        return $method->invoke($this->discovery, $model);
    }

    /**
     * Helper method to call discoverVector method
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return array
     */
    private function callDiscoverVector($model): array
    {
        $reflection = new \ReflectionClass($this->discovery);
        $method = $reflection->getMethod('discoverVector');
        $method->setAccessible(true);

        return $method->invoke($this->discovery, $model);
    }
}
