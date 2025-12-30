<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI\Themes;

use Condoedge\Ai\Services\UI\AbstractChatTheme;

class IndigoTheme extends AbstractChatTheme
{
    public function getName(): string { return 'indigo'; }
    public function primaryGradient(): string { return $this->get('primary_gradient', 'from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700'); }
    public function primaryLightGradient(): string { return $this->get('primary_light_gradient', 'from-indigo-100 to-purple-100 hover:from-indigo-200 hover:to-purple-200'); }
    public function primarySolid(): string { return $this->get('primary_solid', 'bg-indigo-600 hover:bg-indigo-700'); }
    public function primaryText(): string { return $this->get('primary_text', 'text-indigo-600'); }
    public function primaryLightBg(): string { return $this->get('primary_light_bg', 'bg-indigo-50'); }
    public function primaryLightBgHover(): string { return $this->get('primary_light_bg_hover', 'hover:bg-indigo-50'); }
    public function primaryRing(): string { return $this->get('primary_ring', 'ring-indigo-200 focus:ring-indigo-200 focus:ring-2'); }
    public function primaryBorder(): string { return $this->get('primary_border', 'focus:border-indigo-300'); }
    public function primaryShadow(): string { return $this->get('primary_shadow', 'shadow-indigo-500/30'); }
    public function accentGradient(): string { return $this->get('accent_gradient', 'from-indigo-500 via-purple-500 to-fuchsia-500'); }
    public function selectedBg(): string { return $this->get('selected_bg', 'bg-indigo-50'); }
    public function selectedBorder(): string { return $this->get('selected_border', 'border-indigo-500'); }
    public function activeBadge(): string { return $this->get('active_badge', 'bg-indigo-100 text-indigo-700'); }
    public function inactiveBadge(): string { return $this->get('inactive_badge', 'text-gray-500 hover:bg-gray-100'); }
    public function avatarGradient(): string { return $this->get('avatar_gradient', 'from-indigo-500 via-purple-500 to-fuchsia-500'); }
    public function heroBackground(): string { return $this->get('hero_background', 'from-indigo-50/30 via-white to-purple-50/20'); }
    public function linkHover(): string { return $this->get('link_hover', 'hover:text-indigo-600 hover:bg-indigo-50'); }

    public function mainHexColor(): string { return $this->get('main_hex_color', '#4f46e5'); }
}
