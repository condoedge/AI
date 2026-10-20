<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\Support\MessageBubbleRenderer;
use Condoedge\Ai\Kompo\Support\RichDataRenderer;
use Condoedge\Ai\Kompo\Support\SuggestionSubmit;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\BuiltIn\AskFollowUpAction;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\ActionResultPresenter;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * The follow-up question chip on the chat surface.
 *
 * A persisted `ask_follow_up` invocation renders — through the same dispatcher and
 * presenter as every chip — as a suggestion element whose CLICK fills the composer and
 * presses the real send button (SuggestionSubmit, the welcome-screen mechanism). Pinned:
 *
 *  - render runs nothing and posts nothing: one client-side interaction, no server target;
 *  - the question reaches the script JSON-hex-encoded and the label HTML-escaped: hostile
 *    text can neither break out of the script nor inject markup;
 *  - two questions are two chips; a guest, a blank question and a message without
 *    invocations render nothing;
 *  - the bubble shows the follow-ups in their own block, styled as the welcome-screen
 *    suggestions — apart from the page-link chips.
 */
class FollowUpChipRenderTest extends TestCase
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

        // The PRODUCTION (Kompo element) path, whatever ran before (see MessageInvocationRenderTest).
        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/KompoHelpers.php';
        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/HelperUtils.php';
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function renderer(?AiActionRegistry $registry = null, ?ChatThemeInterface $theme = null): MessageBubbleRenderer
    {
        $dispatcher = new ActionDispatcher($registry ?? new AiActionRegistry());

        return new MessageBubbleRenderer(
            $theme ?? Mockery::mock(ChatThemeInterface::class),
            Mockery::mock(ChatSettingsInterface::class),
            Mockery::mock(RichDataRenderer::class),
            new ContentLinkProcessor(new ActionLinkHandler(Mockery::mock(EntityAutoDiscovery::class), $dispatcher)),
            '',
            '',
            $dispatcher,
        );
    }

    private function message(array $invocations, string $content = 'The answer [1].'): object
    {
        return new class(['invocations' => $invocations], $content) {
            public int $id = 42;

            public function __construct(public array $metadata, public string $content)
            {
            }
        };
    }

    /** A persisted record, exactly as the attacher stores it (raw text allowed for the hostile cases). */
    private function ask(string $question, ?string $text = null): array
    {
        return ['key' => 'ask_follow_up', 'entity_type' => null, 'entity_id' => null, 'params' => ['question' => $question], 'text' => $text ?? $question];
    }

    /** @return array<string, mixed> the element as the browser receives it */
    private function serialized(mixed $element): array
    {
        $this->assertInstanceOf(\Kompo\Link::class, $element, 'a follow-up chip is a Kompo link');

        return json_decode((string) json_encode($element), true);
    }

    private function user(): object
    {
        return (object) ['id' => 13];
    }

    // ------------------------------------------------------------- the element

    /** @test */
    public function a_follow_up_invocation_renders_a_chip_that_fills_the_composer_and_sends(): void
    {
        $elements = $this->renderer()->renderPersistedInvocations(
            $this->message([$this->ask('Who pays for the roof?')]),
            ['user' => $this->user()]
        );

        $this->assertCount(1, $elements);
        $chip = $this->serialized($elements[0]);

        $this->assertSame('Who pays for the roof?', $chip['label']);
        $this->assertStringContainsString(ActionResultPresenter::FOLLOW_UP_CLASS, $chip['class']);

        // ONE interaction: a click that runs the suggestion script — the typed-send path.
        $this->assertCount(1, $chip['interactions']);
        $this->assertSame('click', $chip['interactions'][0]['interactionType']);
        $this->assertSame('runJs', $chip['interactions'][0]['action']['actionType']);
        $this->assertSame(
            SuggestionSubmit::fillAndSendJs('Who pays for the roof?'),
            $chip['interactions'][0]['action']['config']['jsFunction']
        );
    }

    /** @test Nothing is posted and no page opens: the click's whole effect is the sent message. */
    public function the_chip_has_no_server_target(): void
    {
        $elements = $this->renderer()->renderPersistedInvocations(
            $this->message([$this->ask('Who pays for the roof?')]),
            ['user' => $this->user()]
        );
        $chip = $this->serialized($elements[0]);
        $json = (string) json_encode($elements[0], JSON_UNESCAPED_SLASHES);

        $this->assertSame('javascript:void(0)', $chip['href']);
        $this->assertStringNotContainsString('X-Kompo-Action', $json);
        $this->assertStringNotContainsString('X-Kompo-Target', $json);
        $this->assertStringNotContainsString('askSuggestion', $json);
    }

    /** @test The label is the reading; the message sent is the question. */
    public function the_label_and_the_question_sent_may_differ(): void
    {
        $elements = $this->renderer()->renderPersistedInvocations(
            $this->message([$this->ask('the balance of my unit', 'The balance of my unit')]),
            ['user' => $this->user()]
        );
        $chip = $this->serialized($elements[0]);

        $this->assertSame('The balance of my unit', $chip['label']);
        $this->assertStringContainsString('input.value = "the balance of my unit";', $chip['interactions'][0]['action']['config']['jsFunction']);
    }

    // ------------------------------------------------------------- hostile text

    /**
     * @test A stored record is data: whatever it holds is sanitized again at render, the
     * question is JSON-hex-encoded into the script and the label is HTML-escaped.
     */
    public function hostile_text_cannot_break_out_of_the_script_or_inject_markup(): void
    {
        $hostile = "What's \"this\" & that </script><img src=x onerror=alert(1)>\n\\\"; alert(2); // now?";

        $elements = $this->renderer()->renderPersistedInvocations(
            $this->message([$this->ask($hostile)]),
            ['user' => $this->user()]
        );
        $chip = $this->serialized($elements[0]);
        $js = $chip['interactions'][0]['action']['config']['jsFunction'];

        // What is sent is the one plain line the sanitizer leaves — no tag, no line break.
        $sent = AskFollowUpAction::sanitize($hostile);
        $this->assertSame('What\'s "this" & that "; alert(2); // now?', $sent);
        $this->assertSame(SuggestionSubmit::fillAndSendJs($sent), $js);

        // Inside the script the text is ONE string literal: every quote, ampersand and angle
        // bracket of it is hex-escaped, so it cannot end the literal or the script element.
        $this->assertSame(1, preg_match('/input\.value = ("(?:[^"\\\\]|\\\\.)*");/', $js, $literal));
        $this->assertSame($sent, json_decode($literal[1]));
        $hex = static fn (string $text): string => trim((string) json_encode(
            $text,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        ), '"');
        $body = substr($literal[1], 1, -1);
        $this->assertSame($hex($sent), $body);
        $this->assertStringContainsString($hex('"this" & that "; alert(2);'), $body);
        foreach (['"', "'", '&'] as $raw) {
            $this->assertStringNotContainsString($raw, $body, 'no raw quote or ampersand inside the literal');
        }
        foreach (['<', '/script', 'onerror', "\n"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js);
        }

        // The label renders through v-html client-side: escaped.
        $this->assertSame(e($sent), $chip['label']);
        $this->assertStringNotContainsString('<', $chip['label']);
        $this->assertStringNotContainsString('"', $chip['label']);
    }

    /** @test A record whose question is nothing but markup renders nothing. */
    public function a_question_that_sanitizes_to_nothing_renders_no_chip(): void
    {
        $records = [
            $this->ask('</script><img src=x onerror=alert(1)>'),
            $this->ask(" \n\t "),
            $this->ask('**``**'),
            ['key' => 'ask_follow_up', 'entity_type' => null, 'entity_id' => null, 'params' => [], 'text' => 'No question at all'],
        ];

        $this->assertSame([], $this->renderer()->renderPersistedInvocations($this->message($records), ['user' => $this->user()]));
    }

    // ------------------------------------------------------------- which chips

    /** @test */
    public function two_questions_are_two_chips_and_a_repeated_one_renders_once(): void
    {
        $elements = $this->renderer()->renderPersistedInvocations(
            $this->message([
                $this->ask('Who pays for the roof?'),
                $this->ask('Who pays for the windows?'),
                $this->ask('Who pays for the roof?'),
            ]),
            ['user' => $this->user()]
        );

        $this->assertSame(
            ['Who pays for the roof?', 'Who pays for the windows?'],
            array_map(fn ($element) => $this->serialized($element)['label'], $elements)
        );
    }

    /** @test */
    public function a_guest_sees_no_follow_up_chip(): void
    {
        $this->assertSame([], $this->renderer()->renderPersistedInvocations(
            $this->message([$this->ask('Who pays for the roof?')]),
            ['user' => null]
        ));
    }

    /** @test Page links first, then the follow-ups — whatever order the record stored them in. */
    public function follow_ups_come_after_the_other_chips(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('meetings', fn () => 'meetings-link'));
        $renderer = $this->renderer($registry);
        $message = $this->message([
            $this->ask('Who pays for the roof?'),
            ['key' => 'meetings', 'entity_type' => null, 'entity_id' => null, 'params' => [], 'text' => 'Meetings'],
        ]);

        $elements = $renderer->renderPersistedInvocations($message, ['user' => $this->user()]);

        $this->assertSame('meetings-link', $elements[0]);
        $this->assertSame('Who pays for the roof?', $this->serialized($elements[1])['label']);

        [$actions, $followUps] = $renderer->persistedInvocationElements($message, ['user' => $this->user()]);
        $this->assertSame(['meetings-link'], $actions);
        $this->assertCount(1, $followUps);
    }

    /** @test */
    public function a_message_without_follow_ups_has_none(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('meetings', fn () => 'meetings-link'));
        $renderer = $this->renderer($registry);

        $this->assertSame([[], []], $renderer->persistedInvocationElements($this->message([]), ['user' => $this->user()]));
        $this->assertSame(
            [['meetings-link'], []],
            $renderer->persistedInvocationElements(
                $this->message([['key' => 'meetings', 'entity_type' => null, 'entity_id' => null, 'params' => [], 'text' => 'Meetings']]),
                ['user' => $this->user()]
            )
        );
        $this->assertNull($renderer->followUpBlock([]));
    }

    // ------------------------------------------------------------- the block

    /** @test Styled as the welcome-screen suggestions, in a block of their own. */
    public function the_bubble_shows_follow_ups_as_suggestions_in_their_own_block(): void
    {
        $theme = Mockery::mock(ChatThemeInterface::class);
        $theme->shouldReceive('primaryLightBgHover')->andReturn('hover:bg-indigo-50');
        $renderer = $this->renderer(null, $theme);

        [, $followUps] = $renderer->persistedInvocationElements(
            $this->message([$this->ask('Who pays for the roof?'), $this->ask('Who pays for the windows?')]),
            ['user' => $this->user()]
        );
        $block = $renderer->followUpBlock($followUps);

        $this->assertInstanceOf(\Kompo\Rows::class, $block);
        $this->assertStringContainsString('cdge-follow-ups', (string) $block->class);
        $this->assertCount(2, $block->elements);
        foreach ($block->elements as $chip) {
            $class = (string) $chip->class;
            foreach (['rounded-xl', 'border-gray-200', 'bg-white', 'text-left', 'hover:bg-indigo-50', ActionResultPresenter::FOLLOW_UP_CLASS] as $expected) {
                $this->assertStringContainsString($expected, $class);
            }
        }
    }

    // ------------------------------------------------------------- the presenter

    /** @test */
    public function the_presenter_turns_a_follow_up_result_into_the_chip(): void
    {
        $presenter = new ActionResultPresenter();

        $chip = $this->serialized($presenter->present(AiActionResult::followUp('Who pays for the roof?', 'Who pays?'), 'ask_follow_up'));
        $this->assertSame('Who pays?', $chip['label']);
        $this->assertSame(SuggestionSubmit::fillAndSendJs('Who pays for the roof?'), $chip['interactions'][0]['action']['config']['jsFunction']);

        $this->assertNull($presenter->present(AiActionResult::followUp('', 'Label'), 'ask_follow_up'), 'no question, no chip');
        $this->assertNull($presenter->present(AiActionResult::error('nope'), 'ask_follow_up'), 'a denied chip renders nothing');
    }
}
