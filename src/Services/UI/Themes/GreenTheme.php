<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI\Themes;

use Condoedge\Ai\Services\UI\AbstractChatTheme;

/**
 * Green chat theme: the indigo theme with Tailwind's default emerald / teal / cyan palette.
 * Only default-palette colours: a theme's classes are compiled by the HOST's Tailwind build, and
 * host-specific colour names (the former `greenmain` / `greenlight` / `greendark` / `level3` of
 * one host) do not exist in another — there every green surface rendered as nothing.
 */
class GreenTheme extends AbstractChatTheme
{
    public function getName(): string { return 'green'; }
    public function primaryGradient(): string { return $this->get('primary_gradient', 'from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700'); }
    public function primaryLightGradient(): string { return $this->get('primary_light_gradient', 'from-emerald-100 to-teal-100 hover:from-emerald-200 hover:to-teal-200'); }
    public function primarySolid(): string { return $this->get('primary_solid', 'bg-emerald-600 hover:bg-emerald-700'); }
    public function primaryText(): string { return $this->get('primary_text', 'text-emerald-600'); }
    public function primaryLightBg(): string { return $this->get('primary_light_bg', 'bg-emerald-50'); }
    public function primaryLightBgHover(): string { return $this->get('primary_light_bg_hover', 'hover:bg-emerald-50'); }
    public function primaryRing(): string { return $this->get('primary_ring', 'ring-emerald-200 focus:ring-emerald-200 focus:ring-2'); }
    public function primaryBorder(): string { return $this->get('primary_border', 'focus:border-emerald-300'); }
    public function primaryShadow(): string { return $this->get('primary_shadow', 'shadow-emerald-500/30'); }
    public function accentGradient(): string { return $this->get('accent_gradient', 'from-emerald-500 via-teal-500 to-cyan-500'); }
    public function selectedBg(): string { return $this->get('selected_bg', 'bg-emerald-50'); }
    public function selectedBorder(): string { return $this->get('selected_border', 'border-emerald-500'); }
    public function activeBadge(): string { return $this->get('active_badge', 'bg-emerald-100 text-emerald-700'); }
    public function inactiveBadge(): string { return $this->get('inactive_badge', 'text-gray-500 hover:bg-gray-100'); }
    public function avatarGradient(): string { return $this->get('avatar_gradient', 'from-emerald-500 via-teal-500 to-cyan-500'); }
    public function heroBackground(): string { return $this->get('hero_background', 'from-emerald-50/30 via-white to-teal-50/20'); }
    public function linkHover(): string { return $this->get('link_hover', 'hover:text-emerald-600 hover:bg-emerald-50'); }

    public function mainHexColor(): string { return $this->get('main_hex_color', '#059669'); }
}
