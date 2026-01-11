<?php

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\ResponseActionLinkProcessor;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Orchestra\Testbench\TestCase;
use Mockery;

class ResponseActionLinkProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_extracts_entity_action_links(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $discovery->shouldReceive('getEntityActionResolver')
            ->with('Person', 'profile')
            ->andReturn(fn($id) => "resolver");

        $processor = new ResponseActionLinkProcessor($discovery);

        $response = 'You can [view John](entity://Person/123/profile) here.';
        $links = $processor->extractActionLinks($response);

        $this->assertCount(1, $links);
        $this->assertEquals('entity', $links[0]['type']);
        $this->assertEquals('view John', $links[0]['text']);
        $this->assertEquals('Person', $links[0]['entity_type']);
        $this->assertEquals('123', $links[0]['entity_id']);
        $this->assertEquals('profile', $links[0]['action_key']);
        $this->assertTrue($links[0]['has_resolver']);
    }

    /** @test */
    public function it_extracts_generic_action_links(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $discovery->shouldReceive('getGenericActionResolver')
            ->with('settings')
            ->andReturn(fn() => "resolver");

        $processor = new ResponseActionLinkProcessor($discovery);

        $response = 'Go to [settings page](action://settings).';
        $links = $processor->extractActionLinks($response);

        $this->assertCount(1, $links);
        $this->assertEquals('generic', $links[0]['type']);
        $this->assertEquals('settings page', $links[0]['text']);
        $this->assertEquals('settings', $links[0]['action_key']);
        $this->assertTrue($links[0]['has_resolver']);
    }

    /** @test */
    public function it_extracts_multiple_links(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $discovery->shouldReceive('getEntityActionResolver')->andReturn(fn($id) => "r");
        $discovery->shouldReceive('getGenericActionResolver')->andReturn(fn() => "r");

        $processor = new ResponseActionLinkProcessor($discovery);

        $response = 'See [John](entity://Person/123/profile) and [Jane](entity://Person/456/profile), or go to [dashboard](action://dashboard).';
        $links = $processor->extractActionLinks($response);

        $this->assertCount(3, $links);
        $this->assertEquals('entity', $links[0]['type']);
        $this->assertEquals('entity', $links[1]['type']);
        $this->assertEquals('generic', $links[2]['type']);
    }

    /** @test */
    public function it_returns_empty_array_for_no_links(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $processor = new ResponseActionLinkProcessor($discovery);

        $response = 'This is a regular message with no action links.';
        $links = $processor->extractActionLinks($response);

        $this->assertCount(0, $links);
    }

    /** @test */
    public function it_enriches_response_array(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $discovery->shouldReceive('getEntityActionResolver')->andReturn(fn($id) => "r");

        $processor = new ResponseActionLinkProcessor($discovery);

        $response = [
            'answer' => 'Check [profile](entity://Person/123/profile).',
            'other_key' => 'preserved',
        ];

        $enriched = $processor->enrichResponse($response);

        $this->assertTrue($enriched['has_action_links']);
        $this->assertCount(1, $enriched['action_links']);
        $this->assertEquals('preserved', $enriched['other_key']);
    }

    /** @test */
    public function it_creates_slots_with_fallback_text(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $processor = new ResponseActionLinkProcessor($discovery);

        $html = '<p>See <a href="entity://Person/123/profile">View John</a> here.</p>';
        // Note: processActionLinks works on markdown patterns, not HTML anchors
        // So we test with the markdown pattern directly
        $content = 'See [View John](entity://Person/123/profile) here.';
        $result = $processor->processActionLinks($content);

        // Should create slot with fallback text
        $this->assertStringContainsString('data-action-slot="entity-Person-123-profile"', $result);
        $this->assertStringContainsString('data-fallback-text="View John"', $result);
    }

    /** @test */
    public function it_escapes_fallback_text_properly(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $processor = new ResponseActionLinkProcessor($discovery);

        $content = 'See [John "The Boss" Doe](entity://Person/123/profile) here.';
        $result = $processor->processActionLinks($content);

        // Should escape quotes in fallback text
        $this->assertStringContainsString('data-fallback-text="John &quot;The Boss&quot; Doe"', $result);
    }

    /** @test */
    public function it_strips_action_links_to_plain_text(): void
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $processor = new ResponseActionLinkProcessor($discovery);

        $content = 'See [John](entity://Person/123/profile) or go to [settings](action://settings).';
        $result = $processor->stripActionLinks($content);

        $this->assertEquals('See John or go to settings.', $result);
    }

    /** @test */
    public function it_creates_action_elements_for_direct_rendering(): void
    {
        $mockElement = new \stdClass();

        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $discovery->shouldReceive('getEntityActionResolver')
            ->with('Person', 'profile')
            ->andReturn(fn($id, $text) => $mockElement);

        $processor = new ResponseActionLinkProcessor($discovery);

        $content = 'See [View Profile](entity://Person/123/profile) here.';
        $elements = $processor->createActionElements($content);

        $this->assertCount(1, $elements);
        $this->assertSame($mockElement, $elements[0]);
    }

    /** @test */
    public function it_processes_for_direct_rendering(): void
    {
        $mockElement = new \stdClass();

        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        $discovery->shouldReceive('getEntityActionResolver')
            ->with('Person', 'profile')
            ->andReturn(fn($id, $text) => $mockElement);

        $processor = new ResponseActionLinkProcessor($discovery);

        $content = 'See [View Profile](entity://Person/123/profile) here.';
        $result = $processor->processForDirectRendering($content);

        $this->assertEquals('See View Profile here.', $result['content']);
        $this->assertCount(1, $result['elements']);
        $this->assertTrue($result['has_actions']);
    }
}
