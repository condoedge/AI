<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\AiChatDrawer;
use Condoedge\Ai\Kompo\AiChatFloating;
use Condoedge\Ai\Kompo\AiChatModal;
use Condoedge\Ai\Kompo\AiChatScreen;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * With `ai.enabled` false every chat surface a host mounts renders nothing and does no
 * work (no availability probe, no conversation lookup): the host's views keep their
 * `new AiChatFloating(...)` lines and the assistant is simply not there (2026-10-28).
 */
class ChatSurfacesMasterSwitchTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Kompo\KompoServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('ai.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Any use of the chat service while disabled fails the test.
        $service = Mockery::mock(AiChatServiceInterface::class);
        $service->shouldNotReceive('isAvailable');
        $this->app->instance(AiChatServiceInterface::class, $service);
        session(['selected_conversation_id' => 123]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function the_floating_button_renders_nothing_and_opens_nothing(): void
    {
        $floating = new AiChatFloating();

        $this->assertNull($floating->render());
        $this->assertNull($floating->openChat());
        $this->assertNull($floating->openChatModal());
    }

    /** @test */
    public function every_surface_renders_nothing_and_skips_its_setup(): void
    {
        foreach ([AiChatModal::class, AiChatDrawer::class, AiChatScreen::class] as $surface) {
            $component = new $surface();
            $this->assertNull($component->render(), $surface);
        }
    }
}
