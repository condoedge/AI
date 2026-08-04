<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

use Condoedge\Ai\Models\AiConversation;

/**
 * Gate + client-side copy wiring for the conversation-length banner (plain and kit-free,
 * same pattern as WelcomeSuggestions — MessagesQuery owns the markup, this class owns the
 * testable logic).
 *
 * The threshold is a SOFT context-quality guard: prompt history only reaches ~5 messages
 * back and the result snapshot is one-deep, so long conversations lose track of earlier
 * details well before any storage limit. At/over the threshold we SUGGEST a fresh
 * conversation (with a one-click clipboard mini resume to carry context over) — the
 * composer is never disabled and sending is never blocked.
 */
final class ConversationLimitBanner
{
    /**
     * Whether the banner should render: feature enabled, a conversation is open, and its
     * message count has reached the configured soft limit.
     *
     * $messageCount lets the caller reuse a count it already computed for the same request
     * (MessagesQuery::chatHeader shows it too — no second COUNT query); null falls back to
     * counting here.
     */
    public static function shouldShow(?AiConversation $conversation, ?int $messageCount = null): bool
    {
        if (!$conversation) {
            return false;
        }

        if (!config('ai.chat.conversation_limit.enabled', true)) {
            return false;
        }

        $max = (int) config('ai.chat.conversation_limit.max_messages', 30);
        if ($max <= 0) {
            return false;
        }

        $count = $messageCount ?? $conversation->messages()->count();

        return $count >= $max;
    }

    /**
     * The ->run() payload for the banner button: copy the server-built resume to the
     * clipboard. The resume is JSON-encoded with the same HEX flags as
     * SuggestionSubmit::fillAndSendJs (the XSS-safe precedent), so quotes/backslashes/tags
     * in conversation-derived text can neither break the script nor inject markup.
     *
     * navigator.clipboard needs a secure context; the execCommand('copy') textarea fallback
     * covers plain-http installs. Both paths run inside the click's user-gesture window.
     */
    public static function copyResumeJs(string $resume): string
    {
        // JSON_INVALID_UTF8_SUBSTITUTE: conversation content is attacker-influenced DB
        // text; without it json_encode returns false on invalid UTF-8 (mojibake from a
        // lenient connection) and concatenating false would emit `const text = ;` — a JS
        // SyntaxError that silently kills the copy. Valid-UTF-8 output (SuggestionSubmit
        // parity) is byte-identical with or without the flag.
        $encoded = json_encode(
            $resume,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($encoded === false) {
            // Unreachable for string input with the substitute flag, but the payload must
            // NEVER be a broken literal.
            $encoded = '""';
        }

        return '() => {'
            . 'const text = ' . $encoded . ';'
            . 'const fallbackCopy = () => {'
            . 'const ta = document.createElement("textarea");'
            . 'ta.value = text;'
            . 'ta.style.position = "fixed";'
            . 'ta.style.opacity = "0";'
            . 'document.body.appendChild(ta);'
            . 'ta.select();'
            . 'try { document.execCommand("copy"); } catch (e) { console.warn("Resume copy failed", e); }'
            . 'document.body.removeChild(ta);'
            . '};'
            . 'if (navigator.clipboard && window.isSecureContext) {'
            . 'navigator.clipboard.writeText(text).catch(fallbackCopy);'
            . '} else {'
            . 'fallbackCopy();'
            . '}'
            . '}';
    }
}
