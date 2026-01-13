# Chat Theming System

Customize the visual appearance of AI chat components with themes, factories, and user preferences.

## Overview

The theming system provides a flexible way to customize chat UI appearance:

| Component | Purpose |
|-----------|---------|
| `ChatThemeInterface` | Contract for theme implementations |
| `AbstractChatTheme` | Base class with override support |
| `ChatThemeFactoryInterface` | Contract for theme factories |
| `ConfigChatThemeFactory` | Creates themes from config |
| `UserChatThemeFactory` | Creates themes from user preferences |

### Theme Resolution Priority

When using `UserChatThemeFactory` (default), themes are resolved in this order:

1. Runtime parameter passed to `create()`
2. User's database setting (`ai_user_settings.ui_theme`)
3. Config value (`ai.ui.theme`)
4. Fallback to `indigo` theme

Color overrides follow the same priority chain and are merged together.

## Built-in Themes

### IndigoTheme

An indigo-to-purple gradient theme with vibrant accent colors.

```php
// Primary colors
'primary_gradient' => 'from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700'
'primary_text' => 'text-indigo-600'
'primary_solid' => 'bg-indigo-600 hover:bg-indigo-700'

// Accent colors
'accent_gradient' => 'from-indigo-500 via-purple-500 to-fuchsia-500'
'avatar_gradient' => 'from-indigo-500 via-purple-500 to-fuchsia-500'

// UI elements
'selected_bg' => 'bg-indigo-50'
'selected_border' => 'border-indigo-500'
'active_badge' => 'bg-indigo-100 text-indigo-700'
```

### GreenTheme

A green gradient theme using custom color tokens (default theme).

```php
// Primary colors (uses custom Tailwind tokens)
'primary_gradient' => 'from-greenmain/70 to-greenmain hover:from-greenmain/60 hover:to-greenmain'
'primary_text' => 'text-greenmain'
'primary_solid' => 'bg-greenmain hover:bg-level3'

// Accent colors
'accent_gradient' => 'from-greenmain via-level3 to-greenlight'
'avatar_gradient' => 'from-greenmain via-level3 to-greendark'

// UI elements
'selected_bg' => 'bg-greenlight'
'selected_border' => 'border-greenmain'
'active_badge' => 'bg-greenlight text-greendark'
```

### ConfigTheme

A fully config-driven theme with no hardcoded defaults. Requires all color properties to be defined in configuration.

```php
// config/ai.php
'ui' => [
    'theme' => 'config',
    'colors' => [
        'primary_gradient' => 'from-blue-600 to-cyan-600',
        'primary_solid' => 'bg-blue-600 hover:bg-blue-700',
        'primary_text' => 'text-blue-600',
        // ... all other required properties
    ],
],
```

Use `ConfigTheme::isComplete()` to verify all required colors are defined:

```php
$theme = new ConfigTheme();
if (!$theme->isComplete()) {
    $missing = $theme->getMissingColors();
    // Handle missing colors
}
```

## Theme Factories

### ConfigChatThemeFactory

Resolves themes purely from configuration. Use when you want consistent theming across all users.

```php
// config/ai.php
'ui' => [
    'factory' => \Condoedge\Ai\Services\UI\ConfigChatThemeFactory::class,
    'theme' => 'indigo', // or 'green', 'config', or custom class
],
```

### UserChatThemeFactory

Resolves themes from user database settings first, falling back to config. Use when you want per-user theme preferences.

```php
// config/ai.php (default)
'ui' => [
    'factory' => \Condoedge\Ai\Services\UI\UserChatThemeFactory::class,
    'theme' => 'green', // fallback when user has no preference
],
```

User preferences are stored in `ai_user_settings.ui_theme` and `ai_user_settings.ui_colors`.

### Using Factories

```php
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;

// Resolve via container (uses configured factory)
$factory = app(ChatThemeFactoryInterface::class);

// Create theme with defaults
$theme = $factory->create();

// Create specific theme
$theme = $factory->create('indigo');

// Create with runtime overrides
$theme = $factory->create('indigo', [
    'primary_gradient' => 'from-blue-600 to-indigo-600',
]);

// Check available themes
$factory->available(); // ['indigo', 'green', 'config']

// Check if theme exists
$factory->has('indigo'); // true
$factory->has(\App\Themes\CustomTheme::class); // true if class exists
```

## Configuration

### Basic Configuration

```php
// config/ai.php
'ui' => [
    // Factory class to use for theme resolution
    'factory' => \Condoedge\Ai\Services\UI\UserChatThemeFactory::class,

    // Default theme name or class
    'theme' => env('AI_UI_THEME', 'green'),

    // Color overrides (applied on top of theme defaults)
    'colors' => [
        'primary_gradient' => 'from-indigo-600 to-purple-600',
    ],
],
```

### Environment Variables

```env
# Set default theme
AI_UI_THEME=indigo

# Use config-only factory (no user preferences)
AI_UI_FACTORY=Condoedge\Ai\Services\UI\ConfigChatThemeFactory
```

## User Preferences

### AiUserSetting Model

User theme preferences are stored in the `ai_user_settings` table:

| Column | Type | Description |
|--------|------|-------------|
| `ui_theme` | string | Theme name (e.g., 'indigo', 'green') |
| `ui_colors` | json | Custom color overrides array |

### Setting User Theme

```php
use Condoedge\Ai\Models\AiUserSetting;

// Get or create settings for current user
$settings = AiUserSetting::forUser(auth()->id());

// Set theme preference
$settings->setTheme('indigo');

// Set theme with color overrides
$settings->setTheme('indigo', [
    'primary_gradient' => 'from-blue-600 to-cyan-600',
]);

// Read current theme
$themeName = $settings->getThemeName(); // 'indigo'
$colorOverrides = $settings->getColorOverrides(); // []
```

### Integration with UserChatThemeFactory

When `UserChatThemeFactory` is configured, themes are automatically resolved from user settings:

```php
// User with theme preference 'indigo'
$factory = app(ChatThemeFactoryInterface::class);
$theme = $factory->create(); // Returns IndigoTheme

// User with no preference uses config default
$theme = $factory->create(); // Returns theme from config('ai.ui.theme')
```

## Creating Custom Themes

### Option 1: Extend AbstractChatTheme

The recommended approach for themes with sensible defaults:

```php
namespace App\Services\UI\Themes;

use Condoedge\Ai\Services\UI\AbstractChatTheme;

class BrandTheme extends AbstractChatTheme
{
    public function getName(): string
    {
        return 'brand';
    }

    public function primaryGradient(): string
    {
        return $this->get('primary_gradient', 'from-brand-600 to-brand-700');
    }

    public function primaryLightGradient(): string
    {
        return $this->get('primary_light_gradient', 'from-brand-100 to-brand-200');
    }

    public function primarySolid(): string
    {
        return $this->get('primary_solid', 'bg-brand-600 hover:bg-brand-700');
    }

    public function primaryText(): string
    {
        return $this->get('primary_text', 'text-brand-600');
    }

    public function primaryLightBg(): string
    {
        return $this->get('primary_light_bg', 'bg-brand-50');
    }

    public function primaryLightBgHover(): string
    {
        return $this->get('primary_light_bg_hover', 'hover:bg-brand-50');
    }

    public function primaryRing(): string
    {
        return $this->get('primary_ring', 'ring-brand-200 focus:ring-brand-200');
    }

    public function primaryBorder(): string
    {
        return $this->get('primary_border', 'focus:border-brand-300');
    }

    public function primaryShadow(): string
    {
        return $this->get('primary_shadow', 'shadow-brand-500/30');
    }

    public function accentGradient(): string
    {
        return $this->get('accent_gradient', 'from-brand-500 to-brand-600');
    }

    public function selectedBg(): string
    {
        return $this->get('selected_bg', 'bg-brand-50');
    }

    public function selectedBorder(): string
    {
        return $this->get('selected_border', 'border-brand-500');
    }

    public function activeBadge(): string
    {
        return $this->get('active_badge', 'bg-brand-100 text-brand-700');
    }

    public function inactiveBadge(): string
    {
        return $this->get('inactive_badge', 'text-gray-500 hover:bg-gray-100');
    }

    public function avatarGradient(): string
    {
        return $this->get('avatar_gradient', 'from-brand-500 to-brand-600');
    }

    public function heroBackground(): string
    {
        return $this->get('hero_background', 'from-brand-50/30 via-white to-brand-50/20');
    }

    public function linkHover(): string
    {
        return $this->get('link_hover', 'hover:text-brand-600 hover:bg-brand-50');
    }
}
```

The `$this->get()` method allows color overrides while providing defaults.

### Option 2: Implement ChatThemeInterface

For complete control over theme behavior:

```php
namespace App\Services\UI\Themes;

use Condoedge\Ai\Services\UI\ChatThemeInterface;

class DynamicTheme implements ChatThemeInterface
{
    protected array $colors;

    public function __construct(array $colors = [])
    {
        // Load colors from database, API, or other source
        $this->colors = array_merge($this->loadFromDatabase(), $colors);
    }

    public function getName(): string
    {
        return 'dynamic';
    }

    public function primaryGradient(): string
    {
        return $this->colors['primary_gradient'] ?? '';
    }

    // Implement all other methods...

    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'primary_gradient' => $this->primaryGradient(),
            // ... all other properties
        ];
    }

    protected function loadFromDatabase(): array
    {
        // Load theme colors from database or other source
        return [];
    }
}
```

### Registering Custom Themes

Register your theme with the factory in a service provider:

```php
// app/Providers/AppServiceProvider.php
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;
use App\Services\UI\Themes\BrandTheme;

public function boot()
{
    $factory = app(ChatThemeFactoryInterface::class);
    $factory->register('brand', BrandTheme::class);
}
```

Now use by name:

```php
$theme = $factory->create('brand');
```

Or reference directly by class:

```php
// config/ai.php
'ui' => [
    'theme' => \App\Services\UI\Themes\BrandTheme::class,
],
```

## Color Properties Reference

All themes must provide these color properties (Tailwind CSS classes):

### Primary Colors

| Property | Purpose | Example |
|----------|---------|---------|
| `primaryGradient` | Main action buttons, send button | `from-indigo-600 to-purple-600` |
| `primaryLightGradient` | Light gradient backgrounds | `from-indigo-100 to-purple-100` |
| `primarySolid` | Solid background elements | `bg-indigo-600 hover:bg-indigo-700` |
| `primaryText` | Primary text color | `text-indigo-600` |
| `primaryLightBg` | Light background areas | `bg-indigo-50` |
| `primaryLightBgHover` | Hover state for light bg | `hover:bg-indigo-50` |
| `primaryRing` | Focus ring styling | `ring-indigo-200 focus:ring-2` |
| `primaryBorder` | Border on focus | `focus:border-indigo-300` |
| `primaryShadow` | Shadow color | `shadow-indigo-500/30` |

### Accent & Selection

| Property | Purpose | Example |
|----------|---------|---------|
| `accentGradient` | Accent decorations | `from-indigo-500 via-purple-500 to-fuchsia-500` |
| `selectedBg` | Selected item background | `bg-indigo-50` |
| `selectedBorder` | Selected item border | `border-indigo-500` |
| `activeBadge` | Active state badges | `bg-indigo-100 text-indigo-700` |
| `inactiveBadge` | Inactive state badges | `text-gray-500 hover:bg-gray-100` |

### Visual Elements

| Property | Purpose | Example |
|----------|---------|---------|
| `avatarGradient` | Assistant avatar gradient | `from-indigo-500 via-purple-500 to-fuchsia-500` |
| `heroBackground` | Welcome screen background | `from-indigo-50/30 via-white to-purple-50/20` |
| `linkHover` | Link hover states | `hover:text-indigo-600 hover:bg-indigo-50` |

### Gradient Syntax

Tailwind gradient classes follow this pattern:

```css
/* Direction */
bg-gradient-to-r  /* left to right */
bg-gradient-to-br /* top-left to bottom-right */

/* Colors */
from-indigo-600   /* start color */
via-purple-500    /* middle color (optional) */
to-fuchsia-500    /* end color */

/* Complete example */
'from-indigo-600 via-purple-500 to-fuchsia-500'
```

## Examples

### Brand-Matching Theme

```php
// config/ai.php
'ui' => [
    'theme' => 'indigo',
    'colors' => [
        'primary_gradient' => 'from-[#1a56db] to-[#7e3af2]',
        'primary_text' => 'text-[#1a56db]',
        'avatar_gradient' => 'from-[#1a56db] to-[#7e3af2]',
    ],
],
```

### Dark Mode Support

Create a theme that uses dark mode variants:

```php
class DarkModeTheme extends AbstractChatTheme
{
    public function primaryGradient(): string
    {
        return $this->get(
            'primary_gradient',
            'from-indigo-600 to-purple-600 dark:from-indigo-500 dark:to-purple-500'
        );
    }

    public function primaryLightBg(): string
    {
        return $this->get(
            'primary_light_bg',
            'bg-indigo-50 dark:bg-indigo-900/20'
        );
    }

    // ... other methods with dark: variants
}
```

### Per-User Theming UI

Allow users to select their preferred theme:

```php
use Condoedge\Ai\Models\AiUserSetting;
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;

class ThemeSelector extends Form
{
    public function render()
    {
        $factory = app(ChatThemeFactoryInterface::class);
        $current = AiUserSetting::forUser(auth()->id())->getThemeName()
            ?? config('ai.ui.theme');

        return _Rows(
            _Select('Theme')
                ->options(collect($factory->available())->mapWithKeys(
                    fn($name) => [$name => ucfirst($name)]
                ))
                ->value($current)
                ->onChange('saveTheme')
        );
    }

    public function saveTheme()
    {
        $settings = AiUserSetting::forUser(auth()->id());
        $settings->setTheme(request('theme'));

        return response()->json(['success' => true]);
    }
}
```
