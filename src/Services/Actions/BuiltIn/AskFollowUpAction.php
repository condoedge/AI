<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * ask_follow_up — the follow-up question chip ("ask" chip).
 *
 * A chip whose click SENDS its question as the user's next message, through the normal
 * send path (the composer is filled and the real send button clicked — the mechanism of
 * the welcome-screen suggestions). Code attaches it, like every other chip (owner rule
 * "code attaches links/actions; the AI only narrates"):
 *
 *  - on a clarifying question, one chip per candidate reading;
 *  - on an attention digest, one per section that shows only a part of its records;
 *  - on a document-grounded answer, the questions {@see \Condoedge\Ai\Services\Response\FollowUpWriter}
 *    proposed and code verified.
 *
 * Read-only and non-mutating: handling it builds no element and runs nothing — it returns
 * the question and its label as a {@see \Condoedge\Ai\Services\Actions\ActionKind::FollowUp}
 * intent, which the presenter turns into the chip. The only thing a click does is send a
 * chat message as the user, who sees it; any authenticated chat user may do that.
 *
 * The question is sanitized in ONE place, {@see self::sanitize()}: when the chip is
 * attached ({@see self::invocation()}) and again when it is rendered ({@see self::handle()} —
 * a persisted record is data, never authority).
 *
 * Internal to the package: the registry resolves it for dispatch but never lists it, so no
 * prompt section offers it to a model as something the user could ask for.
 */
final class AskFollowUpAction extends AbstractBuiltInAction
{
    public const KEY = 'ask_follow_up';

    /** A chip is one short line: a longer text is cut on a word boundary. */
    public const MAX_LENGTH = 160;

    private const PARAM = 'question';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        $translationKey = 'ai.actions.' . self::KEY;

        try {
            $translated = function_exists('__') ? __($translationKey) : $translationKey;
        } catch (\Throwable $e) {
            $translated = $translationKey;
        }

        return is_string($translated) && $translated !== $translationKey ? $translated : 'Ask a follow-up question';
    }

    public function description(): string
    {
        return "Sends a suggested follow-up question as the user's next message.";
    }

    public function inputSchema(): array
    {
        return [self::PARAM => ['type' => 'string']];
    }

    /** Any authenticated chat user: the click only sends a message as that user. */
    public function authorize(mixed $user, array $input): bool
    {
        return $user !== null;
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $question = self::sanitize(is_scalar($input[self::PARAM] ?? null) ? (string) $input[self::PARAM] : '');
        if ($question === '') {
            return AiActionResult::error('There is no follow-up question to ask.');
        }

        $label = self::sanitize((string) ($context->text ?? ''));

        return AiActionResult::followUp($question, $label !== '' ? $label : $question, $this->label());
    }

    /**
     * The chip for one follow-up question, or null when nothing is left of it once
     * sanitized. The label is the question itself unless one is given (a clarifying
     * question's reading, capitalized).
     */
    public static function invocation(string $question, ?string $label = null): ?ActionInvocation
    {
        $question = self::sanitize($question);
        if ($question === '') {
            return null;
        }

        $label = $label !== null ? self::sanitize($label) : '';

        return new ActionInvocation(self::KEY, null, null, [self::PARAM => $question], $label !== '' ? $label : $question);
    }

    /**
     * THE sanitizer of a follow-up question (and of its label): one plain line.
     *
     *  - markup: a markdown link keeps its text, tags go, then the characters markdown
     *    and HTML are written with (emphasis, code, heading, table, bracket, angle);
     *  - invisible format characters (zero-width, direction overrides) are removed; every
     *    other control character — line breaks and tabs included — becomes a space;
     *  - a leading list or numbering marker and wrapping double quotes are dropped;
     *  - whitespace is collapsed, and a text longer than MAX_LENGTH is cut on a word
     *    boundary and ends with an ellipsis.
     *
     * '' when nothing is left: the caller attaches no chip.
     */
    public static function sanitize(string $text): string
    {
        if ($text === '' || preg_match('//u', $text) !== 1) {
            return '';
        }

        $text = (string) preg_replace('/\[([^\[\]]*)\]\([^()]*\)/u', '$1', $text);
        $text = (string) preg_replace('/<[^<>]*>/u', '', $text);
        $text = (string) preg_replace('/\p{Cf}+/u', '', $text);
        $text = (string) preg_replace('/\p{C}+/u', ' ', $text);
        $text = (string) preg_replace('/[*`#|~<>\[\]{}\\\\]+/u', '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = (string) preg_replace('/^(?:[-+•]|\d{1,2}[.)])\s+/u', '', $text);
        $text = (string) preg_replace('/^[\s"\x{201C}\x{201D}\x{00AB}\x{00BB}]+|[\s"\x{201C}\x{201D}\x{00AB}\x{00BB}]+$/u', '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text, 'UTF-8') <= self::MAX_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::MAX_LENGTH - 1, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space, 'UTF-8');
        }

        return rtrim($cut) . '…';
    }
}
