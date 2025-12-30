<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI;

/**
 * Contract for chat UI theme implementations.
 *
 * Themes provide Tailwind CSS class names for various UI elements.
 * All methods return strings containing space-separated Tailwind classes.
 */
interface ChatThemeInterface
{
    public function getName(): string;
    public function primaryGradient(): string;
    public function primaryLightGradient(): string;
    public function primarySolid(): string;
    public function primaryText(): string;
    public function primaryLightBg(): string;
    public function primaryLightBgHover(): string;
    public function primaryRing(): string;
    public function primaryBorder(): string;
    public function primaryShadow(): string;
    public function accentGradient(): string;
    public function selectedBg(): string;
    public function selectedBorder(): string;
    public function activeBadge(): string;
    public function inactiveBadge(): string;
    public function avatarGradient(): string;
    public function heroBackground(): string;
    public function linkHover(): string;
    public function toArray(): array;
}
