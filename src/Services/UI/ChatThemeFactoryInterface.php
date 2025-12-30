<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI;

/**
 * Contract for theme factory implementations.
 *
 * Factories create theme instances from various sources:
 * - ConfigChatThemeFactory: from config/ai.php
 * - UserChatThemeFactory: from user database settings
 *
 * Developers can swap implementations in the service provider.
 */
interface ChatThemeFactoryInterface
{
    /**
     * Create a theme instance.
     *
     * @param string|null $themeName Theme name, class, or null for default
     * @param array $overrides Runtime color overrides
     */
    public function create(?string $themeName = null, array $overrides = []): ChatThemeInterface;

    /**
     * Register a custom theme class.
     */
    public function register(string $name, string $themeClass): self;

    /**
     * Get list of available theme names.
     */
    public function available(): array;

    /**
     * Check if a theme exists.
     */
    public function has(string $name): bool;
}
