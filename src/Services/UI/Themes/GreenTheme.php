<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI\Themes;

use Condoedge\Ai\Services\UI\AbstractChatTheme;

class GreenTheme extends AbstractChatTheme
{
    public function getName(): string { return 'green'; }
    public function primaryGradient(): string { return $this->get('primary_gradient', 'from-greenmain/70 to-greenmain hover:from-greenmain/60 hover:to-greenmain'); }
    public function primaryLightGradient(): string { return $this->get('primary_light_gradient', 'from-greenlight to-level3/80 hover:from-greenlight hover:to-level3/70'); }
    public function primarySolid(): string { return $this->get('primary_solid', 'bg-greenmain hover:bg-level3'); }
    public function primaryText(): string { return $this->get('primary_text', 'text-greenmain'); }
    public function primaryLightBg(): string { return $this->get('primary_light_bg', 'bg-greenlight'); }
    public function primaryLightBgHover(): string { return $this->get('primary_light_bg_hover', 'hover:bg-greenlight'); }
    public function primaryRing(): string { return $this->get('primary_ring', 'ring-level3 focus:ring-level3 focus:ring-2'); }
    public function primaryBorder(): string { return $this->get('primary_border', 'focus:border-greenmain'); }
    public function primaryShadow(): string { return $this->get('primary_shadow', 'shadow-greenmain/30'); }
    public function accentGradient(): string { return $this->get('accent_gradient', 'from-greenmain via-level3 to-greenlight'); }
    public function selectedBg(): string { return $this->get('selected_bg', 'bg-greenlight'); }
    public function selectedBorder(): string { return $this->get('selected_border', 'border-greenmain'); }
    public function activeBadge(): string { return $this->get('active_badge', 'bg-greenlight text-greendark'); }
    public function inactiveBadge(): string { return $this->get('inactive_badge', 'text-gray-500 hover:bg-gray-100'); }
    public function avatarGradient(): string { return $this->get('avatar_gradient', 'from-greenmain via-level3 to-greendark'); }
    public function heroBackground(): string { return $this->get('hero_background', 'from-greenlight/30 via-white to-level3/20'); }
    public function linkHover(): string { return $this->get('link_hover', 'hover:text-greenmain hover:bg-greenlight'); }

    public function mainHexColor(): string { return $this->get('main_hex_color', '#158b63da'); }
}
