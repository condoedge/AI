<?php

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;

class SensibleColumnsDiscoveryTest extends TestCase
{
    private EntityAutoDiscovery $discovery;

    public function setUp(): void
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
}
