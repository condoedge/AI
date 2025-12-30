# Chat UI Theming System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create an Abstract Factory pattern for chat UI theming with interface-based design, multiple theme presets, Tailwind class storage, and single source of truth across all chat components. Support both config-based and user-setting-based theme resolution.

**Architecture:**
- `ChatThemeInterface` defines theme color methods
- `ChatThemeFactoryInterface` defines the factory contract
- `ConfigChatThemeFactory` resolves themes from config (default)
- `UserChatThemeFactory` resolves themes from user database settings
- Developer can swap factory implementations in service provider
- User settings stored in `ai_user_settings` table

**Tech Stack:** PHP 8.1+, Laravel Service Container, Tailwind CSS classes, Laravel migrations

---

## Theme Color Categories

| Category | Usage | Example Current Value |
|----------|-------|----------------------|
| `primary_gradient` | Main buttons, send button | `from-indigo-600 to-purple-600` |
| `primary_solid` | Solid primary buttons | `bg-indigo-600 hover:bg-indigo-700` |
| `primary_text` | Primary text color | `text-indigo-600` |
| `primary_light_bg` | Light backgrounds | `bg-indigo-50` |
| `primary_light_bg_hover` | Light hover | `hover:bg-indigo-50` |
| `primary_ring` | Focus rings | `ring-indigo-200 focus:ring-indigo-200` |
| `primary_border` | Focus borders | `border-indigo-300 focus:border-indigo-300` |
| `primary_shadow` | Colored shadows | `shadow-indigo-500/30` |
| `accent_gradient` | Avatar/decorative gradients | `from-indigo-500 via-purple-500 to-fuchsia-500` |
| `selected_bg` | Selected item background | `bg-indigo-50` |
| `selected_border` | Selected item border | `border-indigo-500` |
| `active_badge` | Active filter badge | `bg-indigo-100 text-indigo-700` |

---

### Task 1: Create ChatThemeInterface

**Files:**
- Create: `src/Services/UI/ChatThemeInterface.php`

**Step 1: Create the interface**

```php
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
    public function toArray(): array;
}
```

**Step 2: Verify and commit**

---

### Task 2: Create ChatThemeFactoryInterface

**Files:**
- Create: `src/Services/UI/ChatThemeFactoryInterface.php`

**Step 1: Create the factory interface**

```php
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
```

**Step 2: Verify and commit**

---

### Task 3: Create AbstractChatTheme Base Class

**Files:**
- Create: `src/Services/UI/AbstractChatTheme.php`

**Step 1: Create abstract base class with config override support**

```php
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
        ];
    }
}
```

**Step 2: Verify and commit**

---

### Task 4: Create Theme Implementations (Indigo, Green, Config)

**Files:**
- Create: `src/Services/UI/Themes/IndigoTheme.php`
- Create: `src/Services/UI/Themes/GreenTheme.php`
- Create: `src/Services/UI/Themes/ConfigTheme.php`

**Step 1: Create IndigoTheme**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI\Themes;

use Condoedge\Ai\Services\UI\AbstractChatTheme;

class IndigoTheme extends AbstractChatTheme
{
    public function getName(): string { return 'indigo'; }
    public function primaryGradient(): string { return $this->get('primary_gradient', 'from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700'); }
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
}
```

**Step 2: Create GreenTheme**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI\Themes;

use Condoedge\Ai\Services\UI\AbstractChatTheme;

class GreenTheme extends AbstractChatTheme
{
    public function getName(): string { return 'green'; }
    public function primaryGradient(): string { return $this->get('primary_gradient', 'from-greenmain to-greendark hover:from-greendark hover:to-greendarker'); }
    public function primarySolid(): string { return $this->get('primary_solid', 'bg-greenmain hover:bg-greendark'); }
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
}
```

**Step 3: Create ConfigTheme (skeleton)**

```php
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
        ];
    }

    public function isComplete(): bool
    {
        $required = ['primary_gradient', 'primary_solid', 'primary_text', 'primary_light_bg',
            'primary_light_bg_hover', 'primary_ring', 'primary_border', 'primary_shadow',
            'accent_gradient', 'selected_bg', 'selected_border', 'active_badge', 'inactive_badge'];
        foreach ($required as $key) {
            if (empty($this->colors[$key])) return false;
        }
        return true;
    }

    public function getMissingColors(): array
    {
        $required = ['primary_gradient', 'primary_solid', 'primary_text', 'primary_light_bg',
            'primary_light_bg_hover', 'primary_ring', 'primary_border', 'primary_shadow',
            'accent_gradient', 'selected_bg', 'selected_border', 'active_badge', 'inactive_badge'];
        return array_filter($required, fn($key) => empty($this->colors[$key]));
    }
}
```

**Step 4: Verify and commit**

---

### Task 5: Create ConfigChatThemeFactory

**Files:**
- Create: `src/Services/UI/ConfigChatThemeFactory.php`

**Step 1: Create the config-based factory**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI;

use Condoedge\Ai\Services\UI\Themes\IndigoTheme;
use Condoedge\Ai\Services\UI\Themes\GreenTheme;
use Condoedge\Ai\Services\UI\Themes\ConfigTheme;

/**
 * Factory that resolves themes from config/ai.php
 *
 * This is the default factory - reads theme selection from config.
 */
class ConfigChatThemeFactory implements ChatThemeFactoryInterface
{
    protected array $themes = [
        'indigo' => IndigoTheme::class,
        'green' => GreenTheme::class,
        'config' => ConfigTheme::class,
    ];

    public function create(?string $themeName = null, array $overrides = []): ChatThemeInterface
    {
        $config = config('ai.ui', []);
        $themeName = $themeName ?? $config['theme'] ?? 'indigo';
        $configOverrides = $config['colors'] ?? [];
        $mergedOverrides = array_merge($configOverrides, $overrides);

        // Custom theme class
        if (class_exists($themeName) && is_subclass_of($themeName, ChatThemeInterface::class)) {
            return new $themeName($mergedOverrides);
        }

        // Built-in theme
        if (isset($this->themes[$themeName])) {
            return new ($this->themes[$themeName])($mergedOverrides);
        }

        // Fallback
        return new IndigoTheme($mergedOverrides);
    }

    public function register(string $name, string $themeClass): self
    {
        if (!is_subclass_of($themeClass, ChatThemeInterface::class)) {
            throw new \InvalidArgumentException("Theme class must implement ChatThemeInterface");
        }
        $this->themes[$name] = $themeClass;
        return $this;
    }

    public function available(): array
    {
        return array_keys($this->themes);
    }

    public function has(string $name): bool
    {
        return isset($this->themes[$name]) ||
               (class_exists($name) && is_subclass_of($name, ChatThemeInterface::class));
    }
}
```

**Step 2: Verify and commit**

---

### Task 6: Create AiUserSetting Model and Migration

**Files:**
- Create: `database/migrations/2025_01_03_000001_create_ai_user_settings_table.php`
- Create: `src/Models/AiUserSetting.php`

**Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('ui_theme')->nullable(); // Theme name or class
            $table->json('ui_colors')->nullable(); // Custom color overrides
            $table->json('chat_settings')->nullable(); // Other chat preferences
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_user_settings');
    }
};
```

**Step 2: Create model**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'ui_theme',
        'ui_colors',
        'chat_settings',
    ];

    protected $casts = [
        'ui_colors' => 'array',
        'chat_settings' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\\Models\\User'));
    }

    /**
     * Get or create settings for a user.
     */
    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(['user_id' => $userId]);
    }

    /**
     * Get the theme name.
     */
    public function getThemeName(): ?string
    {
        return $this->ui_theme;
    }

    /**
     * Get custom color overrides.
     */
    public function getColorOverrides(): array
    {
        return $this->ui_colors ?? [];
    }

    /**
     * Set theme preference.
     */
    public function setTheme(?string $themeName, array $colorOverrides = []): self
    {
        $this->update([
            'ui_theme' => $themeName,
            'ui_colors' => !empty($colorOverrides) ? $colorOverrides : null,
        ]);
        return $this;
    }
}
```

**Step 3: Verify and commit**

---

### Task 7: Create UserChatThemeFactory

**Files:**
- Create: `src/Services/UI/UserChatThemeFactory.php`

**Step 1: Create the user-settings-based factory**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI;

use Condoedge\Ai\Models\AiUserSetting;
use Condoedge\Ai\Services\UI\Themes\IndigoTheme;
use Condoedge\Ai\Services\UI\Themes\GreenTheme;
use Condoedge\Ai\Services\UI\Themes\ConfigTheme;

/**
 * Factory that resolves themes from user database settings.
 *
 * Falls back to config if user has no preference set.
 */
class UserChatThemeFactory implements ChatThemeFactoryInterface
{
    protected array $themes = [
        'indigo' => IndigoTheme::class,
        'green' => GreenTheme::class,
        'config' => ConfigTheme::class,
    ];

    public function create(?string $themeName = null, array $overrides = []): ChatThemeInterface
    {
        // Get user settings if authenticated
        $userSettings = null;
        if (auth()->check()) {
            $userSettings = AiUserSetting::forUser(auth()->id());
        }

        // Priority: runtime param > user setting > config
        $themeName = $themeName
            ?? $userSettings?->getThemeName()
            ?? config('ai.ui.theme', 'indigo');

        // Merge overrides: config < user < runtime
        $configOverrides = config('ai.ui.colors', []);
        $userOverrides = $userSettings?->getColorOverrides() ?? [];
        $mergedOverrides = array_merge($configOverrides, $userOverrides, $overrides);

        // Custom theme class
        if (class_exists($themeName) && is_subclass_of($themeName, ChatThemeInterface::class)) {
            return new $themeName($mergedOverrides);
        }

        // Built-in theme
        if (isset($this->themes[$themeName])) {
            return new ($this->themes[$themeName])($mergedOverrides);
        }

        // Fallback
        return new IndigoTheme($mergedOverrides);
    }

    public function register(string $name, string $themeClass): self
    {
        if (!is_subclass_of($themeClass, ChatThemeInterface::class)) {
            throw new \InvalidArgumentException("Theme class must implement ChatThemeInterface");
        }
        $this->themes[$name] = $themeClass;
        return $this;
    }

    public function available(): array
    {
        return array_keys($this->themes);
    }

    public function has(string $name): bool
    {
        return isset($this->themes[$name]) ||
               (class_exists($name) && is_subclass_of($name, ChatThemeInterface::class));
    }
}
```

**Step 2: Verify and commit**

---

### Task 8: Add UI Config Section to ai.php

**Files:**
- Modify: `config/ai.php`

**Step 1: Add UI configuration section after 'chat' section**

```php
    /*
    |--------------------------------------------------------------------------
    | UI Theming Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the visual theme for AI chat components.
    |
    | Built-in themes: 'indigo' (default), 'green', 'config'
    |
    | Factory options:
    |   - ConfigChatThemeFactory: reads from config only (default)
    |   - UserChatThemeFactory: reads from user database settings first
    |
    */
    'ui' => [
        // Factory class to use for theme resolution
        // Options: ConfigChatThemeFactory::class, UserChatThemeFactory::class
        'factory' => env('AI_UI_FACTORY', \Condoedge\Ai\Services\UI\ConfigChatThemeFactory::class),

        // Default theme (used when factory is ConfigChatThemeFactory or user has no preference)
        'theme' => env('AI_UI_THEME', 'indigo'),

        // Color overrides (optional)
        'colors' => [
            // 'primary_gradient' => 'from-indigo-600 to-purple-600',
        ],
    ],
```

**Step 2: Verify and commit**

---

### Task 9: Register Theme Services in Service Provider

**Files:**
- Modify: `src/AiServiceProvider.php`

**Step 1: Add imports**

```php
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Services\UI\ConfigChatThemeFactory;
```

**Step 2: Add registration method**

```php
    private function registerUiServices(): void
    {
        // Register the configured factory implementation
        $this->app->singleton(ChatThemeFactoryInterface::class, function ($app) {
            $factoryClass = config('ai.ui.factory', ConfigChatThemeFactory::class);
            return new $factoryClass();
        });

        // Alias for convenience
        $this->app->alias(ChatThemeFactoryInterface::class, 'chat-theme-factory');

        // Register the default theme from factory
        $this->app->singleton(ChatThemeInterface::class, function ($app) {
            return $app->make(ChatThemeFactoryInterface::class)->create();
        });
    }
```

**Step 3: Call in register() and add to provides()**

**Step 4: Verify and commit**

---

### Task 10: Update ChatSettingsModal to Save Theme

**Files:**
- Modify: `src/Kompo/Modals/ChatSettingsModal.php`

**Step 1: Add theme selector to the form**

Add to `settingsForm()`:

```php
            // Theme section
            $this->sectionHeader('Theme', 'Choose your chat theme'),
            _Select()->name('ui_theme')
                ->options($this->getThemeOptions())
                ->value($this->getCurrentTheme())
                ->class('w-full border border-gray-200 rounded-xl p-3 ' . $this->theme()->primaryRing() . ' ' . $this->theme()->primaryBorder() . ' transition-all'),
```

**Step 2: Add helper methods**

```php
    protected function getThemeOptions(): array
    {
        $factory = app(ChatThemeFactoryInterface::class);
        $options = [];
        foreach ($factory->available() as $theme) {
            $options[$theme] = ucfirst($theme);
        }
        return $options;
    }

    protected function getCurrentTheme(): string
    {
        if (auth()->check()) {
            $settings = AiUserSetting::forUser(auth()->id());
            return $settings->getThemeName() ?? config('ai.ui.theme', 'indigo');
        }
        return config('ai.ui.theme', 'indigo');
    }
```

**Step 3: Update saveSettings() to persist theme**

```php
    public function saveSettings()
    {
        $settings = [
            'show_avatars' => (bool) request('show_avatars'),
            // ... existing settings ...
        ];

        session(['ai_chat_settings' => $settings]);

        // Save theme to database if user is authenticated
        if (auth()->check()) {
            $userSettings = AiUserSetting::forUser(auth()->id());
            $userSettings->setTheme(request('ui_theme'));
            $userSettings->update(['chat_settings' => $settings]);
        }
    }
```

**Step 4: Verify and commit**

---

### Task 11: Create HasChatTheme Trait

**Files:**
- Create: `src/Kompo/Traits/HasChatTheme.php`

**Step 1: Create the trait**

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;

trait HasChatTheme
{
    protected ?ChatThemeInterface $chatTheme = null;

    protected function theme(): ChatThemeInterface
    {
        if ($this->chatTheme === null) {
            $themeName = $this->prop('ui_theme') ?? null;
            $overrides = $this->prop('ui_colors') ?? [];

            if ($themeName || !empty($overrides)) {
                $this->chatTheme = app(ChatThemeFactoryInterface::class)->create($themeName, $overrides);
            } else {
                $this->chatTheme = app(ChatThemeInterface::class);
            }
        }
        return $this->chatTheme;
    }

    // Shorthand methods
    protected function themeGradient(): string { return 'bg-gradient-to-r ' . $this->theme()->primaryGradient(); }
    protected function themeSolid(): string { return $this->theme()->primarySolid(); }
    protected function themeText(): string { return $this->theme()->primaryText(); }
    protected function themeLightBg(): string { return $this->theme()->primaryLightBg(); }
    protected function themeRing(): string { return $this->theme()->primaryRing(); }
    protected function themeBorder(): string { return $this->theme()->primaryBorder(); }
    protected function themeShadow(): string { return $this->theme()->primaryShadow(); }
    protected function themeAccentGradient(): string { return 'bg-gradient-to-br ' . $this->theme()->accentGradient(); }
    protected function themeSelected(): string { return $this->theme()->selectedBg() . ' ' . $this->theme()->selectedBorder(); }
    protected function themeActiveBadge(): string { return $this->theme()->activeBadge(); }
}
```

**Step 2: Verify and commit**

---

### Task 12-16: Update All Components to Use Theme

Update these files to add `HasChatTheme` trait and replace hardcoded colors:
- `src/Kompo/AiChatPanel.php`
- `src/Kompo/AiChatFloating.php`
- `src/Kompo/ChatMessageForm.php`
- `src/Kompo/ConversationListQuery.php`
- `src/Kompo/Traits/HasAvatars.php`
- `src/Kompo/Modals/ChatSettingsModal.php`
- `src/Kompo/Modals/ChatHelpModal.php`
- `src/Kompo/Modals/EditMessageModal.php`
- `src/Kompo/Modals/FilePreviewModal.php`

Replace patterns:
- `from-indigo-600 to-purple-600` → `$this->theme()->primaryGradient()`
- `bg-indigo-50` → `$this->theme()->primaryLightBg()`
- `text-indigo-600` → `$this->theme()->primaryText()`
- etc.

---

### Task 17: Final Verification

**Step 1: Verify all PHP syntax**
**Step 2: Run `composer dump-autoload`**
**Step 3: Final commit**

---

## Configuration Examples

### sisc (Green Theme)

```php
// config/ai.php
'ui' => [
    'theme' => 'green',
],
```

### User-Selectable Themes

```php
// config/ai.php
'ui' => [
    'factory' => \Condoedge\Ai\Services\UI\UserChatThemeFactory::class,
    'theme' => 'indigo', // fallback default
],
```

### Fully Custom from Config

```php
// config/ai.php
'ui' => [
    'theme' => 'config',
    'colors' => [
        'primary_gradient' => 'from-blue-600 to-cyan-600',
        // ... all colors defined
    ],
],
```

---

## Architecture Summary

```
ChatThemeFactoryInterface (contract)
├── ConfigChatThemeFactory (reads from config)
└── UserChatThemeFactory (reads from user DB settings, falls back to config)

ChatThemeInterface (contract)
├── AbstractChatTheme (base with override support)
│   ├── IndigoTheme (default)
│   └── GreenTheme (sisc)
└── ConfigTheme (pure config skeleton)
```

---

Plan complete. Two execution options:

**1. Subagent-Driven (this session)** - Fresh subagent per task, review between tasks

**2. Parallel Session (separate)** - Open new session with executing-plans

Which approach?
