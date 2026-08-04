<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Kompo\Support\RichDataRenderer;
use Condoedge\Ai\Kompo\Support\SuggestionSubmit;
use Condoedge\Ai\Kompo\Support\WelcomeSuggestions;
use Condoedge\Ai\Services\Settings\UserChatSettings;
use Condoedge\Ai\Services\UI\ConfigChatThemeFactory;
use Orchestra\Testbench\TestCase;

/**
 * Welcome-suggestion resolution + click wiring for suggested questions.
 *
 * The env constraint of this repo (the condoedge/utils chat kit is not installed in vendor,
 * so MessagesQuery/ChatMessageForm cannot be autoloaded in tests) is exactly why the logic
 * lives in the plain, kit-free collaborators exercised here:
 *
 *  - WelcomeSuggestions — page_examples-vs-global resolution, show_suggestions gate,
 *    max_suggestions cap (MessagesQuery::resolveExampleQuestions delegates to it verbatim).
 *  - SuggestionSubmit  — the run()-JS payload every suggestion element (welcome examples in
 *    MessagesQuery::welcomeState and follow-up chips in RichDataRenderer) triggers: it fills
 *    the composer input and clicks the real send button, i.e. the SAME submit handler a typed
 *    send goes through (typed-send parity — no parallel send implementation).
 *  - RichDataRenderer::renderSuggestions — asserts the actual komponent wiring: the chip's
 *    click interaction is a runJs of SuggestionSubmit::fillAndSendJs, NOT the old
 *    selfPost('askSuggestion') that waited for the whole LLM turn with no optimistic echo.
 *
 * The browser-side optimistic behavior itself (bubble + typing indicator appearing before the
 * answer) is a MANUAL CHECK — see the workflow report.
 */
class SuggestionWiringTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // Kompo only: el-helpers (_Link/_Rows/_Flex) for the renderer wiring assertions.
        return [
            \Kompo\KompoServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    private function settings(array $overrides = []): UserChatSettings
    {
        return new UserChatSettings($overrides);
    }

    private function unitPageContext(): PageContext
    {
        return PageContext::fromArray(['entity_type' => 'Unit', 'entity_id' => 42]);
    }

    /* ---------------- WelcomeSuggestions resolution ---------------- */

    /** @test */
    public function welcome_uses_page_examples_when_entity_type_matches_a_config_key(): void
    {
        config(['ai.chat.page_examples' => ['Unit' => ['Unit question 1', 'Unit question 2']]]);

        $resolved = WelcomeSuggestions::resolve(
            $this->unitPageContext(),
            $this->settings(['example_questions' => ['Global question'], 'max_suggestions' => 5]),
        );

        $this->assertSame(['Unit question 1', 'Unit question 2'], $resolved);
    }

    /** @test */
    public function welcome_falls_back_to_global_examples_when_the_entity_type_is_not_configured(): void
    {
        config(['ai.chat.page_examples' => ['Invoice' => ['Invoice question']]]);

        $resolved = WelcomeSuggestions::resolve(
            $this->unitPageContext(),
            $this->settings(['example_questions' => ['Global question'], 'max_suggestions' => 5]),
        );

        $this->assertSame(['Global question'], $resolved);
    }

    /** @test */
    public function welcome_falls_back_to_global_examples_without_page_context(): void
    {
        config(['ai.chat.page_examples' => ['Unit' => ['Unit question']]]);

        $resolved = WelcomeSuggestions::resolve(
            null,
            $this->settings(['example_questions' => ['Global question'], 'max_suggestions' => 5]),
        );

        $this->assertSame(['Global question'], $resolved);
    }

    /** @test */
    public function welcome_suggestions_are_hidden_when_show_suggestions_is_off(): void
    {
        config(['ai.chat.page_examples' => ['Unit' => ['Unit question']]]);

        $resolved = WelcomeSuggestions::resolve(
            $this->unitPageContext(),
            $this->settings([
                'show_suggestions' => false,
                'example_questions' => ['Global question'],
            ]),
        );

        $this->assertSame([], $resolved);
    }

    /** @test */
    public function welcome_suggestions_are_capped_at_max_suggestions(): void
    {
        config(['ai.chat.page_examples' => ['Unit' => ['Q1', 'Q2', 'Q3', 'Q4', 'Q5']]]);

        $resolved = WelcomeSuggestions::resolve(
            $this->unitPageContext(),
            $this->settings(['max_suggestions' => 2]),
        );

        $this->assertSame(['Q1', 'Q2'], $resolved);
    }

    /** @test */
    public function welcome_suggestions_are_empty_when_nothing_is_configured(): void
    {
        config(['ai.chat.page_examples' => []]);

        $resolved = WelcomeSuggestions::resolve(
            $this->unitPageContext(),
            $this->settings(['example_questions' => []]),
        );

        $this->assertSame([], $resolved);
    }

    /** @test */
    public function welcome_suggestions_drop_non_string_and_blank_entries_from_sloppy_host_config(): void
    {
        config(['ai.chat.page_examples' => ['Unit' => ['  Valid question  ', '', null, 42]]]);

        $resolved = WelcomeSuggestions::resolve(
            $this->unitPageContext(),
            $this->settings(['max_suggestions' => 5]),
        );

        $this->assertSame(['Valid question'], $resolved);
    }

    /** @test */
    public function welcome_falls_back_to_global_examples_when_page_examples_has_only_invalid_entries(): void
    {
        // Sanitize-before-fallback: a page_examples list that is non-empty but contains ONLY
        // blank/non-string entries must NOT suppress the documented fallback chain
        // (page_examples -> example_questions -> nothing).
        config(['ai.chat.page_examples' => ['Unit' => ['', null, 42, '   ']]]);

        $resolved = WelcomeSuggestions::resolve(
            $this->unitPageContext(),
            $this->settings(['example_questions' => ['Global question'], 'max_suggestions' => 5]),
        );

        $this->assertSame(['Global question'], $resolved);
    }

    /* ---------------- SuggestionSubmit: typed-send parity JS ---------------- */

    /** @test */
    public function suggestion_js_targets_the_same_composer_input_and_send_button_as_a_typed_send(): void
    {
        $js = SuggestionSubmit::fillAndSendJs('How much do I owe?');

        // Same DOM ids ChatMessageForm assigns via SuggestionSubmit::INPUT_ID / SEND_BUTTON_ID
        // (single source of truth), so the click flows through the exact typed-send chain.
        $this->assertStringContainsString('getElementById("' . SuggestionSubmit::INPUT_ID . '")', $js);
        $this->assertStringContainsString('getElementById("' . SuggestionSubmit::SEND_BUTTON_ID . '")', $js);

        // Fill + model sync + programmatic submit of the real send button.
        $this->assertStringContainsString('input.value = "How much do I owe?"', $js);
        $this->assertStringContainsString('input.dispatchEvent(new Event("input", { bubbles: true }))', $js);
        $this->assertStringContainsString('btn.click()', $js);

        // No parallel send: the JS never posts on its own.
        $this->assertStringNotContainsString('askSuggestion', $js);
    }

    /** @test */
    public function suggestion_js_ids_match_the_literal_dom_ids_the_composer_has_always_used(): void
    {
        // Guard against drift: these literals are baked into the shipped chat markup/JS.
        $this->assertSame('chat-message-input', SuggestionSubmit::INPUT_ID);
        $this->assertSame('chat-send-btn', SuggestionSubmit::SEND_BUTTON_ID);
    }

    /** @test */
    public function suggestion_js_encodes_hostile_question_text_safely(): void
    {
        $js = SuggestionSubmit::fillAndSendJs('What\'s "this" <script>alert(1)</script> & more?');

        // Quotes/tags/ampersands are \uXXXX-escaped by json_encode's HEX flags, so the
        // config-provided text can neither terminate the string literal nor inject markup.
        $this->assertStringNotContainsString('<script>', $js);
        $this->assertStringNotContainsString("What's", $js);
        $bs = chr(92); // backslash — avoids editor/tooling escape ambiguity in this file
        $this->assertStringContainsString($bs . 'u0027', $js); // apostrophe, hex-escaped
        $this->assertStringContainsString($bs . 'u0022', $js); // double quote, hex-escaped
        $this->assertStringContainsString($bs . 'u003C', $js); // '<', hex-escaped
    }

    /* ---------------- RichDataRenderer: follow-up chip wiring ---------------- */

    /** @test */
    public function follow_up_chips_trigger_the_composer_submit_not_a_separate_endpoint(): void
    {
        $renderer = new RichDataRenderer(
            (new ConfigChatThemeFactory())->create(),
            $this->settings(['max_suggestions' => 3]),
        );

        $rendered = $renderer->renderSuggestions(['Show me more details']);

        $chips = $this->elementsWithInteractions($rendered);
        $this->assertCount(1, $chips, 'Expected exactly one interactive chip');

        [$interactionType, $actionType, $jsFunction] = $this->firstInteraction($chips[0]);

        $this->assertSame('click', $interactionType);
        $this->assertSame('runJs', $actionType, 'Chip must run client JS, not post to a separate endpoint');
        $this->assertSame(
            SuggestionSubmit::fillAndSendJs('Show me more details'),
            $jsFunction,
            'Chip click must be the exact fill-composer-and-click-send payload (typed-send parity)',
        );
    }

    /** @test */
    public function follow_up_chips_are_capped_at_max_suggestions(): void
    {
        $renderer = new RichDataRenderer(
            (new ConfigChatThemeFactory())->create(),
            $this->settings(['max_suggestions' => 2]),
        );

        $rendered = $renderer->renderSuggestions(['A', 'B', 'C', 'D']);

        $this->assertCount(2, $this->elementsWithInteractions($rendered));
    }

    /** @test */
    public function follow_up_chip_labels_are_html_escaped_for_the_v_html_link_sink(): void
    {
        // Kompo Link labels render through v-html client-side, and follow-up suggestions come
        // from persisted LLM output (message metadata) — the label must be entity-escaped
        // while the click payload keeps the RAW question (JSON_HEX_*-escaped separately).
        $hostile = '<img src=x onerror=alert(1)> question';

        $renderer = new RichDataRenderer(
            (new ConfigChatThemeFactory())->create(),
            $this->settings(['max_suggestions' => 3]),
        );

        $chips = $this->elementsWithInteractions($renderer->renderSuggestions([$hostile]));
        $this->assertCount(1, $chips);

        $this->assertSame(e($hostile), $chips[0]->label, 'Chip label must be HTML-escaped');
        $this->assertStringNotContainsString('<img', $chips[0]->label);

        [, , $jsFunction] = $this->firstInteraction($chips[0]);
        $this->assertSame(
            SuggestionSubmit::fillAndSendJs($hostile),
            $jsFunction,
            'Click payload must carry the raw question, not the entity-encoded label',
        );
    }

    /* ---------------- helpers ---------------- */

    /**
     * Depth-first collect of every element in a Kompo tree carrying interactions.
     *
     * @return array<int, object>
     */
    private function elementsWithInteractions($element): array
    {
        $found = [];

        if (!is_object($element)) {
            return $found;
        }

        // property_exists guards: Kompo elements have a magic __get that THROWS on unknown
        // properties, so `?? []` would not save us here.
        if (property_exists($element, 'interactions') && !empty($element->interactions)) {
            $found[] = $element;
        }

        $children = property_exists($element, 'elements') && is_array($element->elements)
            ? $element->elements
            : [];
        foreach ($children as $child) {
            $found = array_merge($found, $this->elementsWithInteractions($child));
        }

        return $found;
    }

    /**
     * @return array{0:string,1:?string,2:?string} [interactionType, actionType, jsFunction]
     */
    private function firstInteraction(object $element): array
    {
        $interaction = $element->interactions[0];

        return [
            $interaction->interactionType,
            $interaction->action->actionType ?? null,
            $interaction->action->config('jsFunction') ?? null,
        ];
    }
}
