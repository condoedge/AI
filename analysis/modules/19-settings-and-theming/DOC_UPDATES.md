# Module 19: SETTINGS_AND_THEMING - Documentation Updates

> **Status:** COMPLETED

## Settings Architecture

### Files Analyzed
- `src/Services/Settings/ChatSettingsInterface.php` - Interface defining 18 settings
- `src/Services/Settings/AbstractChatSettings.php` - Base implementation with override support
- `src/Services/Settings/UserChatSettings.php` - User-aware settings with priority resolution

### Key Concepts

**Settings Resolution Priority (UserChatSettings):**
```
1. Constructor overrides ($settings array)
2. Authenticated user DB settings (AiUserSetting model)
3. Session storage (ai_chat_settings key)
4. Config file (ai.chat.*)
5. Hardcoded defaults
```

**Available Settings:**
| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| welcome_title | string | "AI Assistant" | Title shown to new users |
| welcome_message | string | "Ask me anything..." | Welcome message |
| example_questions | array | [] | Starter questions |
| input_placeholder | string | translated | Input field placeholder |
| show_timestamps | bool | false | Display message timestamps |
| show_avatars | bool | true | Display user/AI avatars |
| show_typing | bool | true | Show typing indicator |
| show_suggestions | bool | true | Show follow-up suggestions |
| show_metrics | bool | false | Show response metrics |
| enable_copy | bool | true | Enable copy button |
| enable_feedback | bool | true | Enable feedback buttons |
| enable_edit | bool | true | Enable message editing |
| enable_regenerate | bool | true | Enable response regeneration |
| max_suggestions | int | 3 | Max suggestions displayed |
| response_style | string | "friendly" | AI response style |
| enable_animations | bool | true | Enable UI animations |
| animation_speed | string | "normal" | Animation speed (slow/normal/fast) |
| typing_animation_style | string | "dots" | Typing indicator style |

---

## Theme Architecture

### Files Analyzed
- `src/Services/UI/ChatThemeInterface.php` - Interface for theme implementations
- `src/Services/UI/AbstractChatTheme.php` - Base theme with override support
- `src/Services/UI/ChatThemeFactoryInterface.php` - Factory contract
- `src/Services/UI/ConfigChatThemeFactory.php` - Config-based factory
- `src/Services/UI/UserChatThemeFactory.php` - User preference factory
- `src/Services/UI/Themes/IndigoTheme.php` - Default theme
- `src/Services/UI/Themes/GreenTheme.php` - Alternative theme
- `src/Services/UI/Themes/ConfigTheme.php` - Fully customizable theme

### Theme Properties
All themes must implement these Tailwind CSS class properties:
- `primaryGradient()` - Main gradient for buttons/highlights
- `primaryLightGradient()` - Lighter gradient variant
- `primarySolid()` - Solid background color
- `primaryText()` - Text color
- `primaryLightBg()` - Light background
- `primaryLightBgHover()` - Light background hover state
- `primaryRing()` - Focus ring styling
- `primaryBorder()` - Border styling
- `primaryShadow()` - Shadow styling
- `accentGradient()` - Accent gradient
- `selectedBg()` - Selected item background
- `selectedBorder()` - Selected item border
- `activeBadge()` - Active badge styling
- `inactiveBadge()` - Inactive badge styling
- `avatarGradient()` - Avatar gradient
- `heroBackground()` - Hero section background
- `linkHover()` - Link hover styling

### Creating Custom Themes
```php
class CustomTheme extends AbstractChatTheme
{
    public function getName(): string { return 'custom'; }
    public function primaryGradient(): string {
        return $this->get('primary_gradient', 'from-blue-600 to-cyan-600');
    }
    // ... implement all interface methods
}

// Register in service provider
$factory->register('custom', CustomTheme::class);
```

---

## SafeMarkdownRenderer

### File Analyzed
- `src/Services/UI/SafeMarkdownRenderer.php`

### Supported Markdown Features
- Code blocks (``` with optional language)
- Inline code (`)
- Bold (**text**)
- Italic (*text*)
- Unordered lists (- item)
- Ordered lists (1. item)
- Headers (## and ###)
- Links ([text](url))
- Citations ([1], [2], etc.)

### Security Features
1. **HTML Escaping:** All content escaped with `htmlspecialchars()`
2. **URL Whitelist:** Only http://, https://, mailto:, tel:, # schemes allowed
3. **Relative URL Safety:** Allowed only if no colon present
4. **Language Sanitization:** Code block languages limited to alphanumeric chars
5. **No Raw HTML:** All markup is generated, not passed through

### Usage
```php
$renderer = new SafeMarkdownRenderer([
    'primaryText' => 'text-blue-600',
    'activeBadge' => 'bg-blue-100 text-blue-700',
]);
$html = $renderer->render($markdown);
```

---

## Configuration Examples

### config/ai.php
```php
return [
    'chat' => [
        'welcome_title' => 'Custom Assistant',
        'show_metrics' => true,
        'enable_feedback' => false,
    ],
    'ui' => [
        'theme' => 'indigo', // or 'green', 'config', or custom class
        'colors' => [
            'primary_gradient' => 'from-blue-600 to-blue-800',
            // ... override specific colors
        ],
    ],
];
```

### Session-based Override
```php
session(['ai_chat_settings' => [
    'show_timestamps' => true,
    'animation_speed' => 'fast',
]]);
```

### User Database Override
Uses `AiUserSetting` model with direct column access for authenticated users.
