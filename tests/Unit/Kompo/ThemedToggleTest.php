<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\Support\ThemedToggle;
use Orchestra\Testbench\TestCase;

/**
 * The chat settings toggles (ChatSettingsModal).
 *
 * Live 2026-10-21: every toggle of the settings modal was INVISIBLE. They used the utils
 * `checkColor()` macro, which adds `opacity-0` and relies on a JS function
 * (`checkChangeMyColor`) to fade the toggle in — a function defined nowhere in the host or
 * utils assets, so the toggles never appeared. A themed toggle is now Kompo's own toggle
 * (visible by its default styles) plus one scoped CSS rule for the theme colour: no JS needed.
 */
class ThemedToggleTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/KompoHelpers.php';
        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/HelperUtils.php';
    }

    /** @test */
    public function the_toggle_is_never_hidden_waiting_for_javascript(): void
    {
        $json = json_encode(ThemedToggle::make('show_avatars')) ?: '';

        $this->assertStringContainsString('show_avatars', $json);
        $this->assertStringNotContainsString('opacity-0', $json);
        $this->assertStringNotContainsString('checkChangeMyColor', $json);
        $this->assertStringContainsString(ThemedToggle::CSS_CLASS, $json);
    }

    /** @test */
    public function the_theme_colour_is_one_scoped_css_rule_for_the_checked_state(): void
    {
        $css = ThemedToggle::css('#0EA5E9');

        $this->assertStringContainsString('.' . ThemedToggle::CSS_CLASS . ' input:checked ~ .vlSwitch', $css);
        $this->assertStringContainsString('background-color:#0EA5E9', $css);
    }

    /** @test */
    public function a_colour_that_is_not_a_hex_colour_never_reaches_the_page(): void
    {
        $css = ThemedToggle::css('red;}</style><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $css);
        $this->assertStringNotContainsString('alert', $css);
        $this->assertStringContainsString('background-color:' . ThemedToggle::DEFAULT_COLOR, $css);
    }
}
