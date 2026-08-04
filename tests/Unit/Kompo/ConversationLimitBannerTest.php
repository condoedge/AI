<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\Support\ConversationLimitBanner;
use Condoedge\Ai\Models\AiConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * Gate + clipboard-JS contracts of the conversation-length banner (the plain, kit-free
 * collaborator MessagesQuery::limitBanner delegates to — the same env constraint that keeps
 * MessagesQuery itself out of tests keeps the logic here).
 *
 * The limit is SOFT: shouldShow only decides banner visibility; nothing here (or in the
 * component) ever disables the composer.
 */
class ConversationLimitBannerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Don't load the full AI service provider to avoid API key requirements.
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('ai.chat.conversation_limit', [
            'enabled' => true,
            'max_messages' => 5,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    private function conversationWithMessages(int $count): AiConversation
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        for ($i = 0; $i < $count; $i++) {
            $conversation->messages()->create([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => 'Message ' . $i,
            ]);
        }

        return $conversation;
    }

    /* ---------------- shouldShow: threshold gate ---------------- */

    /** @test */
    public function hidden_below_the_threshold(): void
    {
        $this->assertFalse(ConversationLimitBanner::shouldShow($this->conversationWithMessages(4)));
    }

    /** @test */
    public function shown_at_the_threshold(): void
    {
        $this->assertTrue(ConversationLimitBanner::shouldShow($this->conversationWithMessages(5)));
    }

    /** @test */
    public function shown_above_the_threshold(): void
    {
        $this->assertTrue(ConversationLimitBanner::shouldShow($this->conversationWithMessages(7)));
    }

    /** @test */
    public function hidden_when_the_feature_is_disabled(): void
    {
        config(['ai.chat.conversation_limit.enabled' => false]);

        $this->assertFalse(ConversationLimitBanner::shouldShow($this->conversationWithMessages(9)));
    }

    /** @test */
    public function hidden_without_a_conversation(): void
    {
        $this->assertFalse(ConversationLimitBanner::shouldShow(null));
    }

    /** @test */
    public function hidden_when_the_configured_limit_is_not_positive(): void
    {
        config(['ai.chat.conversation_limit.max_messages' => 0]);

        $this->assertFalse(ConversationLimitBanner::shouldShow($this->conversationWithMessages(3)));
    }

    /** @test */
    public function a_caller_supplied_count_is_reused_instead_of_a_second_count_query(): void
    {
        // 0 rows in the DB but the caller already counted 10 (MessagesQuery memoizes ONE
        // COUNT per request) — the supplied value must win.
        $conversation = $this->conversationWithMessages(0);

        $this->assertTrue(ConversationLimitBanner::shouldShow($conversation, 10));
        $this->assertFalse(ConversationLimitBanner::shouldShow($conversation, 4));
    }

    /* ---------------- copyResumeJs: encoding safety (mirrors SuggestionSubmit) ---------------- */

    /** @test */
    public function copy_js_embeds_the_resume_as_a_hex_escaped_json_literal(): void
    {
        $hostile = 'Resume with "quotes", back\\slashes, </script> tags & \'apostrophes\'';

        $js = ConversationLimitBanner::copyResumeJs($hostile);

        // The exact SuggestionSubmit flag set — byte-for-byte the same encoding guarantees.
        $expected = json_encode(
            $hostile,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );
        $this->assertStringContainsString('const text = ' . $expected . ';', $js);

        // Nothing that could terminate the string literal or the surrounding markup survives raw.
        $this->assertStringNotContainsString('</script>', $js);
        $this->assertStringNotContainsString('"quotes"', $js);
        $bs = chr(92); // backslash — avoids editor/tooling escape ambiguity in this file
        $this->assertStringContainsString($bs . 'u0022', $js); // double quote, hex-escaped
        $this->assertStringContainsString($bs . 'u0027', $js); // apostrophe, hex-escaped
        $this->assertStringContainsString($bs . 'u003C', $js); // '<', hex-escaped
        $this->assertStringContainsString($bs . 'u0026', $js); // '&', hex-escaped
        $this->assertStringContainsString($bs . $bs, $js);     // backslash, JSON-doubled
    }

    /** @test */
    public function copy_js_still_emits_a_valid_string_literal_when_the_resume_contains_invalid_utf8(): void
    {
        // \xC3\x28 = truncated 2-byte sequence — representative of mojibake a lenient DB
        // connection or binary paste can land in message content. Without a guard,
        // json_encode returns false and the payload becomes `const text = ;` — a JS
        // SyntaxError: the copy silently dies while the sibling selfPost still swaps the
        // panel to the new conversation, so the user loses the resume with no feedback.
        $resume = "Recent questions:\n- valid \xC3\x28 invalid";

        $js = ConversationLimitBanner::copyResumeJs($resume);

        $this->assertStringNotContainsString(
            'const text = ;',
            $js,
            'json_encode() returned false and was concatenated raw into the onClick payload.'
        );

        // The payload must remain SOME parseable double-quoted literal.
        $this->assertMatchesRegularExpression(
            '/const text = ".*";/s',
            $js,
            'The resume must always be embedded as a well-formed JSON string literal.'
        );
    }

    /** @test */
    public function copy_js_handles_multiline_resumes_without_breaking_the_literal(): void
    {
        $resume = "Line one\nLine two\n- Question?";

        $js = ConversationLimitBanner::copyResumeJs($resume);

        $bs = chr(92);
        // Newlines are escaped into the literal, never raw inside the quoted string.
        $this->assertStringContainsString($bs . 'n', $js);
        $this->assertStringNotContainsString("Line one\nLine two", $js);
    }

    /** @test */
    public function copy_js_uses_the_clipboard_api_with_an_exec_command_fallback(): void
    {
        $js = ConversationLimitBanner::copyResumeJs('plain resume');

        $this->assertStringStartsWith('() => {', $js);
        $this->assertStringContainsString('navigator.clipboard.writeText(text)', $js);
        $this->assertStringContainsString('window.isSecureContext', $js);
        $this->assertStringContainsString('document.execCommand("copy")', $js);
        // The copy payload is client-only: no posting, no send-button hijack.
        $this->assertStringNotContainsString('selfPost', $js);
        $this->assertStringNotContainsString('chat-send-btn', $js);
    }
}
