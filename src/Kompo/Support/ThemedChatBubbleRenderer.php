<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Utils\Kompo\Chat\ChatBubbleRenderer;

/**
 * The shared chat-kit bubble renderer (condoedge/utils) specialized for the ai chat.
 *
 * The kit owns ALL the bubble markup: the own/other row layout, avatar and timestamp
 * slots, radii, shadows and entrance animations (plus the $HTML/$TIME optimistic
 * template via ownBubbleTemplate()). This subclass only redirects the two knobs the
 * ai chat does differently:
 *
 *  - Own-bubble color. The ai theme system is runtime-configurable (indigo / green /
 *    config-driven, per env or user preference) and speaks Tailwind class names, so the
 *    gradient keeps riding on the theme's classes instead of the kit's
 *    --chat-bubble-own-bg custom property (which a consumer can only theme statically
 *    in scss). With the default indigo theme the result is pixel-identical to the kit
 *    default; non-default themes keep working unchanged.
 *
 *  - Delivery ticks: none. The ai send runs the whole turn server-side (user message +
 *    assistant answer persist together), so WhatsApp-style sending/sent/read ticks have
 *    no state to report. Returning '' also drops the tick from the optimistic template.
 */
class ThemedChatBubbleRenderer extends ChatBubbleRenderer
{
    public function __construct(protected ChatThemeInterface $theme)
    {
    }

    protected function ownBubbleClass(): string
    {
        // Same recipe as the kit's ownBubbleClass(), with the themed Tailwind gradient
        // in place of the chat-bubble-own custom-property hook.
        return 'group px-4 py-3 rounded-2xl rounded-tr-md max-w-xl bg-gradient-to-r '
            . $this->theme->primaryGradient() . ' text-white shadow-md';
    }

    protected function ticksHtml(bool $pending, bool $read): string
    {
        return '';
    }

    /**
     * The ai timestamp keeps its original plain left-aligned style (the kit's justify-end
     * meta row exists to seat the delivery ticks, which the ai chat doesn't render).
     */
    protected function timestampRow(?string $timestamp, $footer, bool $own, ?string $ticksHtml = null)
    {
        if (!$own || $footer || $ticksHtml) {
            return parent::timestampRow($timestamp, $footer, $own, $ticksHtml);
        }

        return $timestamp ? _Html(e($timestamp))->class('text-xs opacity-60 mt-2') : null;
    }

    protected function ownTemplateMetaHtml(): string
    {
        return '<div class="text-xs opacity-60 mt-2">$TIME</div>';
    }
}
