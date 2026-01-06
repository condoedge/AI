<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\ResponseActionLinkProcessor;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Contracts\EntityAccessCheckerInterface;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class ResponseActionLinkProcessorSecurityTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_filters_out_inaccessible_entity_links(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id) => 'link');
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(fn() => 'link');

        $mockAccessChecker = Mockery::mock(EntityAccessCheckerInterface::class);
        $mockAccessChecker->shouldReceive('canAccess')
            ->with('Person', '123', Mockery::any())
            ->andReturn(true); // Can access 123
        $mockAccessChecker->shouldReceive('canAccess')
            ->with('Person', '456', Mockery::any())
            ->andReturn(false); // Cannot access 456

        $processor = new ResponseActionLinkProcessor($mockDiscovery, $mockAccessChecker);

        $response = 'Here are profiles: [John](entity://Person/123/profile) and [Jane](entity://Person/456/profile)';

        $user = (object) ['id' => 1];
        $links = $processor->extractActionLinks($response, $user);

        // Should only include link for entity 123
        $this->assertCount(1, $links);
        $this->assertEquals('123', $links[0]['entity_id']);
    }

    /** @test */
    public function it_includes_all_links_when_no_access_checker_provided(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id) => 'link');

        $processor = new ResponseActionLinkProcessor($mockDiscovery); // No access checker

        $response = '[John](entity://Person/123/profile) [Jane](entity://Person/456/profile)';

        $links = $processor->extractActionLinks($response);

        // Both links included when no access checker
        $this->assertCount(2, $links);
    }

    /** @test */
    public function it_always_includes_generic_action_links(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id) => 'link');
        $mockDiscovery->shouldReceive('getGenericActionResolver')
            ->andReturn(fn() => 'link');

        $mockAccessChecker = Mockery::mock(EntityAccessCheckerInterface::class);
        $mockAccessChecker->shouldReceive('canAccess')
            ->andReturn(false); // Block all entity access

        $processor = new ResponseActionLinkProcessor($mockDiscovery, $mockAccessChecker);

        $response = '[Settings](action://settings) [John](entity://Person/123/profile)';

        $user = (object) ['id' => 1];
        $links = $processor->extractActionLinks($response, $user);

        // Generic link always included, entity link filtered
        $this->assertCount(1, $links);
        $this->assertEquals('generic', $links[0]['type']);
    }
}
