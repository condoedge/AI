<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\UI;

use Condoedge\Ai\Services\UI\Themes\GreenTheme;
use Condoedge\Ai\Services\UI\Themes\IndigoTheme;
use PHPUnit\Framework\TestCase;

/**
 * The green chat theme (2026-10-21, "ultra broken" in CondoEdge).
 *
 * It was written with SISC's own Tailwind colours (`greenmain`, `greenlight`, `greendark`)
 * and `level3` — colours a host defines in ITS Tailwind config or not at all. CondoEdge
 * defines none of the first three (its compiled CSS holds no such class: every green
 * background, gradient and border rendered as nothing) and its `level3` is a dark purple.
 * A theme must use Tailwind's default palette only, like the indigo theme, which renders
 * everywhere: green is now the indigo theme with the palette swapped, class for class.
 */
class GreenThemeTest extends TestCase
{
    /** Every theme value, by method name. @return array<string, string> */
    private static function values(object $theme): array
    {
        $values = [];
        foreach ((new \ReflectionClass($theme))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfRequiredParameters() === 0 && (string) $method->getReturnType() === 'string'
                && !in_array($method->getName(), ['getName', 'mainHexColor'], true)) {
                $values[$method->getName()] = $method->invoke($theme);
            }
        }
        ksort($values);

        return $values;
    }

    /** @test */
    public function the_green_theme_uses_no_host_specific_colour(): void
    {
        foreach (self::values(new GreenTheme()) as $method => $classes) {
            $this->assertDoesNotMatchRegularExpression('/greenmain|greenlight|greendark|\blevel\d|-level\d/', $classes, $method);
        }
    }

    /** @test */
    public function the_green_theme_is_the_indigo_theme_with_the_palette_swapped(): void
    {
        $shape = static fn (array $values, array $palette): array => array_map(
            static fn (string $classes): string => (string) preg_replace('/\b(?:' . implode('|', $palette) . ')-/', 'C-', $classes),
            $values
        );

        $this->assertSame(
            $shape(self::values(new IndigoTheme()), ['indigo', 'purple', 'fuchsia']),
            $shape(self::values(new GreenTheme()), ['emerald', 'teal', 'cyan'])
        );
    }

    /** @test */
    public function the_main_colour_is_a_plain_hex_colour(): void
    {
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', (new GreenTheme())->mainHexColor());
    }
}
