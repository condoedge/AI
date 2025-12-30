<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI\Themes;

use Condoedge\Ai\Services\UI\ChatThemeInterface;

/**
 * Config-driven theme with no hardcoded defaults.
 * Reads ALL values directly from config - requires all colors to be defined.
 */
class ConfigTheme implements ChatThemeInterface
{
    protected array $colors;

    public function __construct(array $colors = [])
    {
        $configColors = config('ai.ui.colors', []);
        $this->colors = array_merge($configColors, $colors);
    }

    public function getName(): string { return 'config'; }
    public function primaryGradient(): string { return $this->colors['primary_gradient'] ?? ''; }
    public function primaryLightGradient(): string { return $this->colors['primary_light_gradient'] ?? ''; }
    public function primarySolid(): string { return $this->colors['primary_solid'] ?? ''; }
    public function primaryText(): string { return $this->colors['primary_text'] ?? ''; }
    public function primaryLightBg(): string { return $this->colors['primary_light_bg'] ?? ''; }
    public function primaryLightBgHover(): string { return $this->colors['primary_light_bg_hover'] ?? ''; }
    public function primaryRing(): string { return $this->colors['primary_ring'] ?? ''; }
    public function primaryBorder(): string { return $this->colors['primary_border'] ?? ''; }
    public function primaryShadow(): string { return $this->colors['primary_shadow'] ?? ''; }
    public function accentGradient(): string { return $this->colors['accent_gradient'] ?? ''; }
    public function selectedBg(): string { return $this->colors['selected_bg'] ?? ''; }
    public function selectedBorder(): string { return $this->colors['selected_border'] ?? ''; }
    public function activeBadge(): string { return $this->colors['active_badge'] ?? ''; }
    public function inactiveBadge(): string { return $this->colors['inactive_badge'] ?? ''; }
    public function avatarGradient(): string { return $this->colors['avatar_gradient'] ?? ''; }
    public function heroBackground(): string { return $this->colors['hero_background'] ?? ''; }
    public function linkHover(): string { return $this->colors['link_hover'] ?? ''; }

    public function mainHexColor(): string
    {
        return $this->colors['main_hex_color'] ?? '#a9abffff';
    }

    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'primary_gradient' => $this->primaryGradient(),
            'primary_solid' => $this->primarySolid(),
            'primary_text' => $this->primaryText(),
            'primary_light_bg' => $this->primaryLightBg(),
            'primary_light_bg_hover' => $this->primaryLightBgHover(),
            'primary_ring' => $this->primaryRing(),
            'primary_border' => $this->primaryBorder(),
            'primary_shadow' => $this->primaryShadow(),
            'accent_gradient' => $this->accentGradient(),
            'selected_bg' => $this->selectedBg(),
            'selected_border' => $this->selectedBorder(),
            'active_badge' => $this->activeBadge(),
            'inactive_badge' => $this->inactiveBadge(),
            'avatar_gradient' => $this->avatarGradient(),
            'hero_background' => $this->heroBackground(),
            'link_hover' => $this->linkHover(),
        ];
    }

    public function isComplete(): bool
    {
        $required = ['primary_gradient', 'primary_solid', 'primary_text', 'primary_light_bg',
            'primary_light_bg_hover', 'primary_ring', 'primary_border', 'primary_shadow',
            'accent_gradient', 'selected_bg', 'selected_border', 'active_badge', 'inactive_badge',
            'avatar_gradient', 'hero_background', 'link_hover'];
        foreach ($required as $key) {
            if (empty($this->colors[$key])) return false;
        }
        return true;
    }

    public function getMissingColors(): array
    {
        $required = ['primary_gradient', 'primary_solid', 'primary_text', 'primary_light_bg',
            'primary_light_bg_hover', 'primary_ring', 'primary_border', 'primary_shadow',
            'accent_gradient', 'selected_bg', 'selected_border', 'active_badge', 'inactive_badge',
            'avatar_gradient', 'hero_background', 'link_hover'];
        return array_filter($required, fn($key) => empty($this->colors[$key]));
    }
}
