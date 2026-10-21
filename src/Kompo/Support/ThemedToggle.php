<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

/**
 * A toggle in the chat's theme colour.
 *
 * The settings modal used the utils `checkColor()` macro, which hides the toggle
 * (`opacity-0`) until a JS function `checkChangeMyColor` fades it in — a function no host
 * asset defines, so every toggle stayed invisible (2026-10-21). This is Kompo's own toggle,
 * visible by its default styles, with the theme colour applied to the checked state by one
 * scoped CSS rule ({@see css()}) the surface prints once.
 */
final class ThemedToggle
{
    /** The class that scopes the colour rule to these toggles only. */
    public const CSS_CLASS = 'ai-themed-toggle';

    /** Used when the theme gives no valid hex colour. */
    public const DEFAULT_COLOR = '#4f46e5';

    public static function make(string $name)
    {
        return _Toggle()->name($name)->class('flex-shrink-0 ' . self::CSS_CLASS);
    }

    /**
     * The scoped rule for the checked state. The colour is printed into a <style> block, so
     * anything that is not a plain hex colour is replaced by the default.
     */
    public static function css(?string $color): string
    {
        $hex = is_string($color) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color) === 1
            ? $color
            : self::DEFAULT_COLOR;

        return '.' . self::CSS_CLASS . ' input:checked ~ .vlSwitch{background-color:' . $hex . '}';
    }

    /** The <style> element to print once on a surface that shows themed toggles. */
    public static function styleElement(?string $color)
    {
        return _Html('<style>' . self::css($color) . '</style>');
    }
}
