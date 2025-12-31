# Phase 3: Settings Resolution Flow Trace

## Overview

This document traces the complete settings resolution flow for chat UI settings,
documenting the priority chain, which settings are actually used vs merely defined,
and identifying any settings that are collected but never consumed.

---

## Priority Chain Diagram

```
                    SETTINGS RESOLUTION FLOW

    +------------------------------------------------------------------+
    |                     CONSUMER COMPONENTS                          |
    |  AiChatPanel, ChatMessageForm, ChatSettingsModal                 |
    |                                                                  |
    |  Usage: $this->settings()->settingMethod()                       |
    |         $this->cfg('setting_key')                                |
    +------------------------------------------------------------------+
                                 |
                                 v
    +------------------------------------------------------------------+
    |                   HasChatSettings TRAIT                          |
    |  Location: src/Kompo/Traits/HasChatSettings.php                  |
    |                                                                  |
    |  - settings(): ChatSettingsInterface  (resolves from container)  |
    |  - cfg(key, default): mixed           (toArray() lookup)         |
    |  - Shorthand methods: showAvatars(), showTimestamps(), etc.      |
    +------------------------------------------------------------------+
                                 |
                                 v
    +------------------------------------------------------------------+
    |               ChatSettingsInterface (Contract)                   |
    |  Location: src/Services/Settings/ChatSettingsInterface.php       |
    |                                                                  |
    |  Bound to: UserChatSettings::class                               |
    |  In: AiServiceProvider::registerSettingsServices()               |
    +------------------------------------------------------------------+
                                 |
                                 v
    +------------------------------------------------------------------+
    |                   UserChatSettings                               |
    |  Location: src/Services/Settings/UserChatSettings.php            |
    |  Extends: AbstractChatSettings                                   |
    |                                                                  |
    |  PRIORITY CHAIN (checked in order):                              |
    |  +---------------------------------------------------------+     |
    |  | 0. Constructor overrides ($this->settings array)        |     |
    |  |    - Passed when instantiating UserChatSettings         |     |
    |  |    - Highest priority (runtime injection)               |     |
    |  +---------------------------------------------------------+     |
    |                          |                                       |
    |                          v                                       |
    |  +---------------------------------------------------------+     |
    |  | 1. User Database Settings (AiUserSetting model)         |     |
    |  |    - Requires: auth()->check() == true                  |     |
    |  |    - Lookup: AiUserSetting::forUser(auth()->id())       |     |
    |  |    - Field: chat_settings (JSON column, cast to array)  |     |
    |  +---------------------------------------------------------+     |
    |                          |                                       |
    |                          v                                       |
    |  +---------------------------------------------------------+     |
    |  | 2. Session Settings                                     |     |
    |  |    - Key: 'ai_chat_settings'                            |     |
    |  |    - Set by: ChatSettingsModal::afterSave()             |     |
    |  |    - Cleared by: ChatSettingsModal::resetSettings()     |     |
    |  +---------------------------------------------------------+     |
    |                          |                                       |
    |                          v                                       |
    |  +---------------------------------------------------------+     |
    |  | 3. Application Config                                   |     |
    |  |    - Key pattern: config("ai.chat.{$key}")              |     |
    |  |    - File: config/ai.php -> 'chat' array                |     |
    |  +---------------------------------------------------------+     |
    |                          |                                       |
    |                          v                                       |
    |  +---------------------------------------------------------+     |
    |  | 4. Hardcoded Defaults                                   |     |
    |  |    - Defined in each getter method                      |     |
    |  |    - e.g., showAvatars() defaults to true               |     |
    |  +---------------------------------------------------------+     |
    +------------------------------------------------------------------+
```

---

## Theme Resolution Flow (Related)

```
                    THEME RESOLUTION FLOW

    +------------------------------------------------------------------+
    |                     CONSUMER COMPONENTS                          |
    |  AiChatPanel, ChatMessageForm, ChatSettingsModal                 |
    |                                                                  |
    |  Usage: $this->theme()->primaryGradient()                        |
    +------------------------------------------------------------------+
                                 |
                                 v
    +------------------------------------------------------------------+
    |                   HasChatTheme TRAIT                             |
    |  Location: src/Kompo/Traits/HasChatTheme.php                     |
    |                                                                  |
    |  - theme(): ChatThemeInterface (resolves from container)         |
    +------------------------------------------------------------------+
                                 |
                                 v
    +------------------------------------------------------------------+
    |            ChatThemeFactoryInterface (Contract)                  |
    |  Location: src/Services/UI/ChatThemeFactoryInterface.php         |
    |                                                                  |
    |  Bound to: config('ai.ui.factory')                               |
    |  Default: UserChatThemeFactory::class                            |
    +------------------------------------------------------------------+
                                 |
                                 v
    +------------------------------------------------------------------+
    |                 UserChatThemeFactory                             |
    |  Location: src/Services/UI/UserChatThemeFactory.php              |
    |                                                                  |
    |  THEME RESOLUTION (create method):                               |
    |  +---------------------------------------------------------+     |
    |  | 1. Runtime parameter ($themeName argument)              |     |
    |  +---------------------------------------------------------+     |
    |                          |                                       |
    |                          v                                       |
    |  +---------------------------------------------------------+     |
    |  | 2. User Database Setting                                |     |
    |  |    - AiUserSetting::forUser()->getThemeName()           |     |
    |  |    - Field: ui_theme (string column)                    |     |
    |  +---------------------------------------------------------+     |
    |                          |                                       |
    |                          v                                       |
    |  +---------------------------------------------------------+     |
    |  | 3. Application Config                                   |     |
    |  |    - config('ai.ui.theme', 'indigo')                    |     |
    |  +---------------------------------------------------------+     |
    |                                                                  |
    |  COLOR OVERRIDES MERGE ORDER:                                    |
    |  +---------------------------------------------------------+     |
    |  | config('ai.ui.colors')                                  |     |
    |  |        +                                                |     |
    |  | userSettings->getColorOverrides()                       |     |
    |  |        +                                                |     |
    |  | runtime $overrides parameter                            |     |
    |  | = final merged overrides                                |     |
    |  +---------------------------------------------------------+     |
    +------------------------------------------------------------------+
```

---

## AiUserSetting Model Usage

| Field | Type | Purpose | Used In |
|-------|------|---------|---------|
| `user_id` | int | Foreign key to users table | All lookups via `forUser()` |
| `ui_theme` | string | Theme name preference | `UserChatThemeFactory` |
| `ui_colors` | array | Color override map | `UserChatThemeFactory` |
| `chat_settings` | array | Chat behavior settings | `UserChatSettings` |

### Key Methods

```php
// Static factory - always returns instance (creates if missing)
AiUserSetting::forUser(int $userId): self

// Theme access
getThemeName(): ?string
getColorOverrides(): array
setTheme(?string $themeName, array $colorOverrides = []): self

// Chat settings access
getSetting(string $key, $default = null): mixed
getSettings(): array
setSetting(string $key, $value): self
setSettings(array $settings): self
```

---

## HasChatSettings Trait Methods

### Core Methods

| Method | Return | Purpose |
|--------|--------|---------|
| `settings()` | `ChatSettingsInterface` | Get cached settings instance |
| `cfg(key, default)` | `mixed` | Generic key lookup via toArray() |

### Shorthand Methods (All Delegating to settings())

| Method | Return | Default Value | Usage Status |
|--------|--------|---------------|--------------|
| `showAvatars()` | `bool` | `true` | NOT USED (callers use `$this->settings()->showAvatars()`) |
| `showTimestamps()` | `bool` | `false` | NOT USED |
| `showTyping()` | `bool` | `true` | NOT USED |
| `showSuggestions()` | `bool` | `true` | NOT USED |
| `showMetrics()` | `bool` | `false` | NOT USED |
| `enableCopy()` | `bool` | `true` | NOT USED |
| `enableFeedback()` | `bool` | `true` | NOT USED |
| `enableEdit()` | `bool` | `true` | NOT USED |
| `enableRegenerate()` | `bool` | `true` | NOT USED |
| `welcomeTitle()` | `string` | `'AI Assistant'` | NOT USED |
| `welcomeMessage()` | `string` | `'Ask me anything...'` | NOT USED |
| `inputPlaceholder()` | `string` | `'Ask a question...'` | NOT USED |
| `exampleQuestions()` | `array` | `[]` | NOT USED |
| `maxSuggestions()` | `int` | `3` | NOT USED |
| `responseStyle()` | `string` | `'friendly'` | NOT USED |

**Key Finding:** All 15 shorthand methods in `HasChatSettings` are redundant.
Components consistently call `$this->settings()->methodName()` directly.

---

## Settings: Defined vs Actually Used

### Settings That ARE Consumed (in UI Components)

| Setting | Defined In | Consumed In | Usage |
|---------|------------|-------------|-------|
| `show_avatars` | `UserChatSettings` | `AiChatPanel` | Controls avatar display on messages |
| `show_timestamps` | `UserChatSettings` | `AiChatPanel` | Controls timestamp display |
| `show_suggestions` | `UserChatSettings` | `AiChatPanel` | Controls follow-up suggestions |
| `show_metrics` | `UserChatSettings` | `AiChatPanel` | Controls execution metrics display |
| `enable_copy` | `UserChatSettings` | `AiChatPanel` | Controls copy button visibility |
| `enable_feedback` | `UserChatSettings` | `AiChatPanel` | Controls feedback buttons |
| `enable_edit` | `UserChatSettings` | `AiChatPanel` | Controls message edit button |
| `enable_regenerate` | `UserChatSettings` | `AiChatPanel` | Controls regenerate button |
| `welcome_title` | `UserChatSettings` | `AiChatPanel` | Welcome screen title |
| `welcome_message` | `UserChatSettings` | `AiChatPanel` | Welcome screen message |
| `example_questions` | `UserChatSettings` | `AiChatPanel` | Welcome screen questions |
| `input_placeholder` | `UserChatSettings` | `ChatMessageForm` | Input field placeholder |
| `max_suggestions` | `UserChatSettings` | `AiChatPanel` | Limits displayed suggestions |
| `response_style` | `UserChatSettings` | `AiChatPanel`, `ChatMessageForm` | AI response style |

### Settings That ARE Defined But NOT Consumed

| Setting | Defined In | Why Not Used |
|---------|------------|--------------|
| `show_typing` | `UserChatSettings` | No typing indicator implementation in current UI |

**Finding:** 14 of 15 settings are actively consumed. Only `show_typing` is defined but not used.

---

## Settings Saved But Potentially Never Retrieved

### In ChatSettingsModal::afterSave()

```php
session()->put('ai_chat_settings', [
    'show_avatars' => $this->model->show_avatars,      // Issue: reads from model columns
    'show_timestamps' => $this->model->show_timestamps, // that don't exist as columns!
    'show_metrics' => $this->model->show_metrics,
    'show_suggestions' => $this->model->show_suggestions,
    'enable_copy' => $this->model->enable_copy,
    'enable_feedback' => $this->model->enable_feedback,
    'enable_regenerate' => $this->model->enable_regenerate,
    'enable_edit' => $this->model->enable_edit,
    'response_style' => $this->model->response_style,
    'ui_theme' => $this->model->ui_theme,              // This one exists
]);
```

**Critical Bug:** The `afterSave()` method tries to read individual columns
(`show_avatars`, `show_timestamps`, etc.) from the `AiUserSetting` model,
but these are stored in the `chat_settings` JSON column, not as individual columns.

### What Should Happen

```php
// Correct approach would be:
$chatSettings = $this->model->chat_settings ?? [];
session()->put('ai_chat_settings', $chatSettings);
```

---

## Config Keys vs UserChatSettings Keys

### Config Keys (in config/ai.php -> 'chat')

```php
'chat' => [
    'show_timestamps' => env('AI_CHAT_SHOW_TIMESTAMPS', false),
    'show_avatars' => env('AI_CHAT_SHOW_AVATARS', true),
    'show_typing_indicator' => env('AI_CHAT_SHOW_TYPING', true),  // Different key!
    'show_suggestions' => env('AI_CHAT_SHOW_SUGGESTIONS', true),
    'max_suggestions' => env('AI_CHAT_MAX_SUGGESTIONS', 3),
    'show_metrics' => env('AI_CHAT_SHOW_METRICS', false),
    'input_placeholder' => env('AI_CHAT_INPUT_PLACEHOLDER', 'Ask a question...'),
    'enable_copy' => env('AI_CHAT_ENABLE_COPY', true),
    'enable_feedback' => env('AI_CHAT_ENABLE_FEEDBACK', false),  // Different default!
    // Missing: enable_edit, enable_regenerate, welcome_title, welcome_message, etc.
]
```

### UserChatSettings Keys

| Settings Key | Config Key | Match? |
|--------------|------------|--------|
| `show_typing` | `show_typing_indicator` | MISMATCH |
| `enable_feedback` | `enable_feedback` | Match (but different defaults) |
| `enable_edit` | N/A | MISSING from config |
| `enable_regenerate` | N/A | MISSING from config |
| `welcome_title` | `welcome.title` | NESTED structure mismatch |
| `welcome_message` | `welcome.message` | NESTED structure mismatch |
| `example_questions` | `example_questions` | Match |
| `response_style` | N/A | MISSING from config |

**Finding:** Several key mismatches between config structure and settings keys.

---

## Complete Data Flow Example

### Retrieving `show_avatars`:

```
1. AiChatPanel renders user bubble
2. Calls: $this->settings()->showAvatars()
3. HasChatSettings::settings() returns cached ChatSettingsInterface
4. UserChatSettings::showAvatars() calls get('show_avatars', true)
5. get() checks priority chain:
   a. $this->settings['show_avatars']? -> No (not set at construction)
   b. auth()->check()? -> Yes
      - AiUserSetting::forUser(auth()->id())
      - $userSetting->chat_settings['show_avatars']? -> e.g., true
      - RETURN: true (from user DB)
   c. (skipped - found in step b)
   d. (skipped - found in step b)
```

### Saving Settings (ChatSettingsModal):

```
1. User clicks "Save Settings" in modal
2. Form submits with toggle values
3. Kompo saves to AiUserSetting model:
   - BUT: tries to save individual columns that don't exist!
   - Should save to chat_settings JSON column
4. afterSave() tries to sync to session:
   - Reads individual columns (will be null)
   - Saves nulls to session
5. Session now has incorrect/null values
```

---

## Issues Identified

### High Priority

1. **ChatSettingsModal saves to wrong columns**
   - Tries to save to individual columns (show_avatars, etc.)
   - Should save to chat_settings JSON column
   - Location: `src/Kompo/Modals/ChatSettingsModal.php`

2. **Config key mismatch: show_typing vs show_typing_indicator**
   - UserChatSettings looks for `ai.chat.show_typing`
   - Config defines `ai.chat.show_typing_indicator`
   - Will never find config value

### Medium Priority

3. **Nested config structure for welcome settings**
   - Config has: `chat.welcome.title`, `chat.welcome.message`
   - UserChatSettings looks for: `chat.welcome_title`, `chat.welcome_message`
   - Config values will never be found

4. **Missing config keys**
   - `enable_edit`, `enable_regenerate`, `response_style` have no config fallback
   - Only user DB or defaults will ever apply

### Low Priority

5. **Redundant shorthand methods**
   - All 15 shorthand methods in HasChatSettings are never called
   - Components use `$this->settings()->method()` directly
   - Consider removing for clarity

6. **show_typing setting never consumed**
   - Defined in interface and settings class
   - No UI component uses it
   - Either implement typing indicator or remove setting

---

## Recommendations

### Immediate Fixes

1. **Fix ChatSettingsModal form fields**
   ```php
   // Fields should save to chat_settings array
   _Toggle()->name('chat_settings.show_avatars')
   ```

2. **Fix config key for show_typing**
   ```php
   // In config/ai.php
   'show_typing' => env('AI_CHAT_SHOW_TYPING', true),  // Not show_typing_indicator
   ```

3. **Flatten welcome config or adjust lookup**
   ```php
   // Option A: In config/ai.php
   'welcome_title' => env('AI_CHAT_WELCOME_TITLE', 'AI Assistant'),
   'welcome_message' => env('AI_CHAT_WELCOME_MESSAGE', '...'),

   // Option B: In UserChatSettings
   $configValue = config("ai.chat.welcome.{$key}");
   ```

### Cleanup

4. **Remove or document shorthand methods**
   - Either remove from HasChatSettings
   - Or document as convenience methods and use them

5. **Add missing config keys**
   ```php
   'enable_edit' => env('AI_CHAT_ENABLE_EDIT', true),
   'enable_regenerate' => env('AI_CHAT_ENABLE_REGENERATE', true),
   'response_style' => env('AI_RESPONSE_STYLE', 'friendly'),
   ```

---

## File Reference

| File | Purpose |
|------|---------|
| `src/Services/Settings/ChatSettingsInterface.php` | Contract for settings |
| `src/Services/Settings/AbstractChatSettings.php` | Base class with toArray() |
| `src/Services/Settings/UserChatSettings.php` | Priority chain implementation |
| `src/Kompo/Traits/HasChatSettings.php` | Trait for component access |
| `src/Models/AiUserSetting.php` | User settings persistence |
| `src/Kompo/Modals/ChatSettingsModal.php` | Settings UI (has bugs) |
| `src/Services/UI/UserChatThemeFactory.php` | Theme resolution |
| `src/AiServiceProvider.php` | Container bindings |
| `config/ai.php` | Configuration defaults |
