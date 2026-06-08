<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\ResponseSections\ResponseEntityActionsSection;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Verifies action-link (entity://) INSTRUCTIONS are style-aware.
 *
 * This whole section is pure action-link guidance, which contradicts the
 * 'minimal'/'concise' styles ("ONLY the direct answer"). For those styles the
 * section is suppressed entirely (shouldInclude() === false). Non-terse styles
 * are unchanged.
 */
class ResponseEntityActionsSectionStyleTest extends TestCase
{
    private $mockDiscovery;
    private ResponseEntityActionsSection $section;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockDiscovery = Mockery::mock(EntityAutoDiscovery::class);
        $this->app->instance(EntityAutoDiscovery::class, $this->mockDiscovery);

        $this->section = new ResponseEntityActionsSection();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function contextWithEntities(): PipelineContext
    {
        $context = new PipelineContext();
        $context->conversationContext = [
            'focused_entity' => 'Person',
            'last_result_sample' => [
                ['id' => '152463', 'name' => 'John Doe'],
            ],
        ];

        return $context;
    }

    /**
     * @test
     * @dataProvider terseStyles
     */
    public function it_is_suppressed_for_terse_styles(string $style): void
    {
        // For terse styles the section short-circuits before consulting discovery,
        // so no action-link instructions are ever emitted.
        $this->assertFalse(
            $this->section->shouldInclude($this->contextWithEntities(), ['style' => $style])
        );
    }

    /**
     * @test
     * @dataProvider richStyles
     */
    public function it_includes_and_emits_action_links_for_rich_styles(string $style): void
    {
        $this->mockDiscovery->shouldReceive('hasEntityActions')
            ->with('Person')->andReturn(true);
        $this->mockDiscovery->shouldReceive('getEntityActions')
            ->with('Person')->andReturn([
                ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
            ]);
        $this->mockDiscovery->shouldReceive('getGenericActions')->andReturn([]);

        $context = $this->contextWithEntities();

        $this->assertTrue($this->section->shouldInclude($context, ['style' => $style]));

        $output = $this->section->format($context, ['style' => $style]);

        // Action-link (entity://) instructions are present for non-terse styles.
        $this->assertStringContainsString('entity://Person/152463/profile', $output);
        $this->assertStringContainsString('Action Links', $output);
    }

    public static function terseStyles(): array
    {
        return [
            'minimal' => ['minimal'],
            'concise' => ['concise'],
        ];
    }

    public static function richStyles(): array
    {
        return [
            'friendly' => ['friendly'],
            'detailed' => ['detailed'],
        ];
    }
}
