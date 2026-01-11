<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ResponseActionLinkProcessor;
use Condoedge\Ai\Tests\TestCase;
use Kompo\Link;
use Mockery;

/**
 * Tests for action link processing and direct rendering.
 */
class ActionLinkWiringTest extends TestCase
{
    /** @test */
    public function it_creates_action_elements_for_direct_rendering(): void
    {
        $mockLink = (new Link('View Profile'))->href('/person/123');

        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->with('Person', 'profile')
            ->andReturn(fn($id, $text) => (new Link($text))->href("/person/{$id}"));

        $processor = new ResponseActionLinkProcessor($mockDiscovery);

        $content = '[View John](entity://Person/123/profile)';
        $elements = $processor->createActionElements($content);

        $this->assertCount(1, $elements);
        $this->assertInstanceOf(Link::class, $elements[0]);
    }

    /** @test */
    public function it_strips_action_links_to_plain_text(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $processor = new ResponseActionLinkProcessor($mockDiscovery);

        $content = 'See [John](entity://Person/123/profile) and [settings](action://settings).';
        $result = $processor->stripActionLinks($content);

        $this->assertEquals('See John and settings.', $result);
    }

    /** @test */
    public function it_processes_for_direct_rendering_with_elements_and_clean_content(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->with('Person', 'profile')
            ->andReturn(fn($id, $text) => (new Link($text))->href("/person/{$id}"));

        $processor = new ResponseActionLinkProcessor($mockDiscovery);

        $content = 'See [View John](entity://Person/123/profile) here.';
        $result = $processor->processForDirectRendering($content);

        $this->assertEquals('See View John here.', $result['content']);
        $this->assertCount(1, $result['elements']);
        $this->assertTrue($result['has_actions']);
    }

    /** @test */
    public function it_handles_multiple_action_links(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id, $text) => (new Link($text))->href("/view/{$id}"));

        $processor = new ResponseActionLinkProcessor($mockDiscovery);

        $content = '[John](entity://Person/123/profile) and [Jane](entity://Person/456/profile)';
        $elements = $processor->createActionElements($content);

        // Should have 2 unique elements
        $this->assertCount(2, $elements);
    }

    /** @test */
    public function it_deduplicates_same_action_links(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(fn($id, $text) => (new Link($text))->href("/view/{$id}"));

        $processor = new ResponseActionLinkProcessor($mockDiscovery);

        // Same link twice (same entity/id/action)
        $content = '[John](entity://Person/123/profile) [John Again](entity://Person/123/profile)';
        $elements = $processor->createActionElements($content);

        // Should deduplicate to 1 element
        $this->assertCount(1, $elements);
    }

    /** @test */
    public function it_returns_empty_when_no_resolver_configured(): void
    {
        $mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $mockDiscovery->shouldReceive('getEntityActionResolver')
            ->andReturn(null);

        $processor = new ResponseActionLinkProcessor($mockDiscovery);

        $content = '[John](entity://Person/123/profile)';
        $elements = $processor->createActionElements($content);

        // No resolver = no elements
        $this->assertCount(0, $elements);
    }
}
