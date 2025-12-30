# Chat Settings Store System

## Overview

Create an interface-based settings system mirroring the theme pattern. Settings will be resolved through a priority chain: User DB → Session → Config → Defaults.

## Current State (To Be Replaced)

**Files using deprecated pattern:**
- `src/Kompo/Traits/HasChatConfig.php` - trait to delete
- `src/Kompo/AiChatPanel.php` - uses `HasChatConfig`, calls `loadChatConfig()`, `cfg()`
- `src/Kompo/ChatMessageForm.php` - uses `HasChatConfig`, calls `loadChatConfig()`, `cfg()`

**Current issues:**
- Settings loaded from props (deprecated pattern)
- No user preference persistence
- No session fallback
- `extraConfig` props pattern is confusing

## New Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                    HasChatSettings Trait                      │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ settings() → ChatSettingsInterface                       │ │
│  │ __get($property) → snake_case to camelCase delegation    │ │
│  │ Shorthand: $this->show_avatars, $this->welcome_title     │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│                  ChatSettingsInterface                        │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ welcomeTitle(): string                                   │ │
│  │ welcomeMessage(): string                                 │ │
│  │ exampleQuestions(): array                                │ │
│  │ inputPlaceholder(): string                               │ │
│  │ showTimestamps(): bool                                   │ │
│  │ showAvatars(): bool                                      │ │
│  │ showTyping(): bool                                       │ │
│  │ showSuggestions(): bool                                  │ │
│  │ showMetrics(): bool                                      │ │
│  │ enableCopy(): bool                                       │ │
│  │ enableFeedback(): bool                                   │ │
│  │ enableEdit(): bool                                       │ │
│  │ enableRegenerate(): bool                                 │ │
│  │ maxSuggestions(): int                                    │ │
│  │ responseStyle(): string                                  │ │
│  │ toArray(): array                                         │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│                  AbstractChatSettings                         │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ protected array $settings;                               │ │
│  │ protected function get(string $key, mixed $default)      │ │
│  │ Provides common implementation logic                     │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│                   UserChatSettings                            │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ Priority chain:                                          │ │
│  │ 1. User DB (AiUserSetting model)                         │ │
│  │ 2. Session (ai_chat_settings)                            │ │
│  │ 3. Config (ai.chat.*)                                    │ │
│  │ 4. Hardcoded defaults                                    │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

## Implementation Steps

### Step 1: Create ChatSettingsInterface
**File:** `src/Services/Settings/ChatSettingsInterface.php`

```php
interface ChatSettingsInterface
{
    public function welcomeTitle(): string;
    public function welcomeMessage(): string;
    public function exampleQuestions(): array;
    public function inputPlaceholder(): string;
    public function showTimestamps(): bool;
    public function showAvatars(): bool;
    public function showTyping(): bool;
    public function showSuggestions(): bool;
    public function showMetrics(): bool;
    public function enableCopy(): bool;
    public function enableFeedback(): bool;
    public function enableEdit(): bool;
    public function enableRegenerate(): bool;
    public function maxSuggestions(): int;
    public function responseStyle(): string;
    public function toArray(): array;
}
```

### Step 2: Create AbstractChatSettings
**File:** `src/Services/Settings/AbstractChatSettings.php`

Base class with `get()` helper method (similar to `AbstractChatTheme`).

### Step 3: Create UserChatSettings
**File:** `src/Services/Settings/UserChatSettings.php`

Main implementation with priority chain:
1. Check authenticated user's `AiUserSetting` model
2. Check session `ai_chat_settings`
3. Check config `ai.chat.*`
4. Return hardcoded default

### Step 4: Create HasChatSettings Trait
**File:** `src/Kompo/Traits/HasChatSettings.php`

Mirror `HasChatTheme` pattern:
- `settings()` method returns cached `ChatSettingsInterface`
- `__get()` magic method for snake_case property access
- Shorthand methods like `showAvatars()`, `welcomeTitle()`

### Step 5: Update Service Provider
**File:** `src/AiServiceProvider.php`

Add binding:
```php
$this->app->singleton(ChatSettingsInterface::class, function ($app) {
    return new UserChatSettings();
});
```

### Step 6: Update AiChatPanel
**File:** `src/Kompo/AiChatPanel.php`

- Replace `HasChatConfig` with `HasChatSettings`
- Remove `loadChatConfig()` call from `created()`
- Replace `$this->cfg('key')` with `$this->settings()->keyAsCamelCase()` or `$this->key_as_snake`

### Step 7: Update ChatMessageForm
**File:** `src/Kompo/ChatMessageForm.php`

- Replace `HasChatConfig` with `HasChatSettings`
- Remove `loadChatConfig()` call
- Update all `cfg()` calls

### Step 8: Update ChatSettingsModal
**File:** `src/Kompo/Modals/ChatSettingsModal.php`

- Ensure `afterSave()` updates both session and user DB
- Remove any `config` prop passing

### Step 9: Delete HasChatConfig Trait
**File:** `src/Kompo/Traits/HasChatConfig.php`

Delete this file completely.

### Step 10: Update AiUserSetting Model
**File:** `src/Models/AiUserSetting.php`

Add settings getter/setter methods similar to theme:
```php
public function getSettings(): array;
public function getSetting(string $key, mixed $default = null): mixed;
public function setSettings(array $settings): void;
public function setSetting(string $key, mixed $value): void;
```

## Settings List

| Setting Key | Type | Default | Description |
|-------------|------|---------|-------------|
| welcome_title | string | 'AI Assistant' | Welcome screen title |
| welcome_message | string | 'Ask me anything...' | Welcome screen message |
| example_questions | array | [] | Sample questions to show |
| input_placeholder | string | 'Ask a question...' | Input field placeholder |
| show_timestamps | bool | false | Show message times |
| show_avatars | bool | true | Show user/AI avatars |
| show_typing | bool | true | Show typing indicator |
| show_suggestions | bool | true | Show follow-up suggestions |
| show_metrics | bool | false | Show response time/confidence |
| enable_copy | bool | true | Allow copying responses |
| enable_feedback | bool | true | Show thumbs up/down |
| enable_edit | bool | true | Allow editing messages |
| enable_regenerate | bool | true | Allow regenerating responses |
| max_suggestions | int | 3 | Max follow-up suggestions |
| response_style | string | 'friendly' | AI response style |

## Migration Notes

The `AiUserSetting` model already has a `settings` JSON column. We'll use it to store chat settings alongside the existing theme settings.

## Testing

1. Settings load from user DB when authenticated
2. Settings fall back to session for guests
3. Settings fall back to config when not set
4. Hardcoded defaults work when nothing is configured
5. `HasChatSettings` trait provides correct values
6. `ChatSettingsModal` saves to correct location
7. All components render correctly with new system
