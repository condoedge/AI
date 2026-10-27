<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\Traits\HasAvatars;
use Orchestra\Testbench\TestCase;

/**
 * The assistant's picture: `ai.chat.assistant_avatar` (a public path or a URL) replaces the
 * default sparkle on the theme gradient in the header, the bubbles and the welcome screen.
 */
class AssistantAvatarImageTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    private function avatars(): object
    {
        return new class {
            use HasAvatars;

            public function __call(string $method, array $args): string
            {
                return $this->{$method}(...$args);
            }
        };
    }

    /** @test */
    public function without_a_picture_the_sparkle_stays(): void
    {
        config(['ai.chat.assistant_avatar' => null]);

        foreach (['assistantAvatarHtml', 'welcomeAvatarHtml'] as $method) {
            $html = $this->avatars()->{$method}();
            $this->assertStringContainsString('<svg', $html);
            $this->assertStringNotContainsString('<img', $html);
        }
    }

    /** @test */
    public function a_public_path_is_shown_as_the_assistant_picture(): void
    {
        config(['ai.chat.assistant_avatar' => 'images/coco.png', 'ai.identity.name' => 'Coco "AI"']);

        foreach (['assistantAvatarHtml', 'welcomeAvatarHtml'] as $method) {
            $html = $this->avatars()->{$method}();
            $this->assertStringContainsString('<img src="' . asset('images/coco.png') . '" alt="Coco &quot;AI&quot;"', $html);
            $this->assertStringNotContainsString('<svg', $html);
        }
    }

    /** @test */
    public function a_url_is_kept_as_given(): void
    {
        config(['ai.chat.assistant_avatar' => 'https://cdn.example.test/bot.webp']);

        $this->assertStringContainsString('src="https://cdn.example.test/bot.webp"', $this->avatars()->assistantAvatarHtml());
    }
}
