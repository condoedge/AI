# Module 19: SETTINGS_AND_THEMING - Analysis Plan

> **Module Slug:** settings-and-theming
> **Priority:** LOW (User preferences)
> **Estimated Files:** 12

## Responsibility
- User-configurable chat settings
- Visual theming
- Safe markdown rendering

## Files
### Settings
| File | Purpose |
|------|---------|
| `src/Services/Settings/AbstractChatSettings.php` | Base settings |
| `src/Services/Settings/ChatSettingsInterface.php` | Interface |
| `src/Services/Settings/UserChatSettings.php` | User settings |

### UI/Theming
| File | Purpose |
|------|---------|
| `src/Services/UI/AbstractChatTheme.php` | Base theme |
| `src/Services/UI/ChatThemeFactoryInterface.php` | Factory interface |
| `src/Services/UI/ChatThemeInterface.php` | Theme interface |
| `src/Services/UI/ConfigChatThemeFactory.php` | Config-based factory |
| `src/Services/UI/SafeMarkdownRenderer.php` | Safe markdown |
| `src/Services/UI/UserChatThemeFactory.php` | User-based factory |
| `src/Services/UI/Themes/ConfigTheme.php` | Config theme |
| `src/Services/UI/Themes/GreenTheme.php` | Green theme |
| `src/Services/UI/Themes/IndigoTheme.php` | Indigo theme |
