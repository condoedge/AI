<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

/**
 * Client-side wiring for "click a suggested question" — welcome examples and follow-up chips.
 *
 * A suggestion click must behave EXACTLY like typing the same text and pressing send. Instead
 * of posting to a separate server endpoint (which runs the whole LLM turn with no optimistic
 * echo), the click fills the composer input, syncs the framework model, and programmatically
 * clicks the real send button. Everything downstream — the optimistic user bubble + typing
 * indicator (precreateMessagePlaceholder), the input clear, the selfPost that runs the turn,
 * and the messages-panel refresh — is ChatMessageForm's own sendingMessageActions() chain,
 * unduplicated. There is deliberately NO parallel send implementation here.
 */
final class SuggestionSubmit
{
    /**
     * DOM ids of the composer input / send button. OWNED here as the single source of truth:
     * ChatMessageForm assigns them (composerInput()/sendButton()) and this class targets them,
     * so the two can never drift apart.
     */
    public const INPUT_ID = 'chat-message-input';
    public const SEND_BUTTON_ID = 'chat-send-btn';

    /**
     * The ->run() payload for a suggestion element: fill the composer with $question,
     * dispatch an input event (so the reactive form model picks up the programmatic value),
     * then click the real send button — the same handler a typed send goes through.
     *
     * The question is JSON-encoded (hex-escaped) so quotes/backslashes/tags in config-provided
     * text can neither break the script nor inject markup.
     */
    public static function fillAndSendJs(string $question): string
    {
        $encoded = json_encode(
            $question,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );

        return '() => {'
            . 'const input = document.getElementById("' . self::INPUT_ID . '");'
            . 'const btn = document.getElementById("' . self::SEND_BUTTON_ID . '");'
            . 'if (!input || !btn) { console.warn("Chat composer not found for suggestion click"); return; }'
            . 'input.value = ' . $encoded . ';'
            . 'input.dispatchEvent(new Event("input", { bubbles: true }));'
            . 'btn.click();'
            . '}';
    }
}
