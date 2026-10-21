<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\Support\MessageBubbleRenderer;
use Condoedge\Ai\Kompo\Support\RichDataRenderer;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * The copy / helpful / not-helpful / … buttons under an assistant message (2026-10-21):
 *  - the feedback confirmation showed the raw key "translate.feedback-received-successfully"
 *    and the copy confirmation "translate.copied-to-clipboard": neither key exists in any
 *    language file — they are package lines now;
 *  - the copy button pasted the answer RAW into a JS string literal, so an answer with a
 *    quote or a line break broke the button, and answer text (which can carry document
 *    content) could close the string and run as script — it is JSON-encoded now.
 */
class MessageActionBarTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('entities', []);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/KompoHelpers.php';
        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/HelperUtils.php';
        // The utils macros the bar used to rely on (copyToClipboard) — loaded if present, so
        // the test also proves the bar no longer NEEDS them.
        foreach (glob(__DIR__ . '/../../../vendor/condoedge/utils/src/Helpers/elements/*.php') ?: [] as $file) {
            require_once $file;
        }
        // _Sax() reads its SVG from public/icons: give the test app the three the bar uses.
        $icons = public_path('icons');
        if (!is_dir($icons)) {
            mkdir($icons, 0777, true);
        }
        foreach (['copy', 'like-1', 'dislike'] as $icon) {
            if (!is_file($icons . '/' . $icon . '.svg')) {
                file_put_contents($icons . '/' . $icon . '.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
            }
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function bar(string $content): string
    {
        $settings = Mockery::mock(ChatSettingsInterface::class);
        $settings->shouldReceive('enableCopy')->andReturn(true);
        $settings->shouldReceive('enableFeedback')->andReturn(true);
        $settings->shouldReceive('enableRegenerate')->andReturn(false);
        $settings->shouldReceive('enableEdit')->andReturn(false);
        $settings->shouldIgnoreMissing();

        $theme = Mockery::mock(ChatThemeInterface::class)->shouldIgnoreMissing();
        $dispatcher = new ActionDispatcher(new AiActionRegistry());
        $renderer = new MessageBubbleRenderer(
            $theme,
            $settings,
            Mockery::mock(RichDataRenderer::class),
            new ContentLinkProcessor(new ActionLinkHandler(Mockery::mock(EntityAutoDiscovery::class), $dispatcher)),
            '',
            '',
            $dispatcher,
        );

        $message = new class($content) {
            public int $id = 42;
            public array $metadata = [];

            public function __construct(public string $content)
            {
            }
        };

        return json_encode($renderer->messageActionBar($message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @test */
    public function no_action_shows_an_untranslated_translate_key(): void
    {
        $json = $this->bar('The answer.');

        $this->assertStringNotContainsString('translate.feedback-received-successfully', $json);
        $this->assertStringNotContainsString('translate.copied-to-clipboard', $json);
    }

    /** @test */
    public function the_confirmations_are_package_lines_in_every_shipped_language(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $lines = json_decode((string) file_get_contents(__DIR__ . '/../../../resources/lang/' . $locale . '.json'), true);
            $this->assertNotEmpty($lines['ai.messages.feedback-received'] ?? null, $locale);
            $this->assertNotEmpty($lines['ai.messages.copied'] ?? null, $locale);
        }
    }

    /** @test */
    public function an_answer_with_quotes_line_breaks_or_a_script_cannot_break_out_of_the_copy_button(): void
    {
        $content = "He said \"no\".\nLine two '); alert(1); //</script>";
        $element = json_decode($this->bar($content), true);

        // The JS the copy button runs, as the browser receives it.
        $js = null;
        array_walk_recursive($element, function ($value, $key) use (&$js) {
            if ($key === 'jsFunction' && is_string($value) && str_contains($value, 'writeText')) {
                $js = $value;
            }
        });
        $this->assertIsString($js, 'the copy button runs a clipboard write');

        // Its argument is ONE JSON string literal: no raw quote, line break or tag can end it…
        $this->assertSame(1, preg_match('/writeText\((".*")\)/s', $js, $arg));
        $this->assertStringNotContainsString('</script>', $js);
        $this->assertStringNotContainsString("\n", $arg[1], 'a line break is escaped, not pasted');
        // …and the text copied is exactly the answer.
        $this->assertSame($content, json_decode($arg[1]));
    }
}
