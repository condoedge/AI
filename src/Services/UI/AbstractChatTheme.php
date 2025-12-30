<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI;

abstract class AbstractChatTheme implements ChatThemeInterface
{
    protected array $overrides = [];

    public function __construct(array $overrides = [])
    {
        $this->overrides = $overrides;
    }

    protected function get(string $key, string $default): string
    {
        return $this->overrides[$key] ?? $default;
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
}
