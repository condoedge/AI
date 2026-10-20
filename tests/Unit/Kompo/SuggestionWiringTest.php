<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Kompo\Support\SuggestionSubmit;
use Condoedge\Ai\Kompo\Support\WelcomeSuggestions;
use Condoedge\Ai\Services\Settings\UserChatSettings;
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
 *    MessagesQuery::welcomeState) triggers: it fills
 *    the composer input and clicks the real send button, i.e. the SAME submit handler a typed
 *    send goes through (typed-send parity — no parallel send implementation).
 *  (The per-message follow-up suggestion chips were removed 2026-10-01, actions plan B3:
 *   nothing produced them; next steps are code-attached action chips.)
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
}
