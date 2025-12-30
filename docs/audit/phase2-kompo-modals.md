# Phase 2 Audit: Kompo Modals

**Audit Date:** 2025-12-30
**Task:** 14 - Kompo Modal Components Review
**Directory:** `src/Kompo/Modals/`

## Overview

This document audits the modal components used in the AI chat system. All modals extend `Condoedge\Utils\Kompo\Common\Modal` and provide specific UI functionality.

## Modal Summary

| Modal | Purpose | Triggered From | Has Form Actions |
|-------|---------|----------------|------------------|
| ChatHelpModal | Display help/tips | AiChatPanel header | No |
| ChatSettingsModal | User preferences | AiChatPanel header | Yes (save/reset) |
| EditMessageModal | Edit user messages | Message action button | Yes (save/delete) |
| FilePreviewModal | Preview files | File reference link | No |

---

## 1. ChatHelpModal

**File:** `src/Kompo/Modals/ChatHelpModal.php`
**Lines:** 204

### What it Does
Displays help content and tips for using the AI chat interface with multiple tabbed sections:
- Getting Started - Feature overview and example queries
- Tips & Tricks - Usage tips for better results
- Shortcuts - Keyboard shortcuts reference
- FAQ - Common questions and answers

### Props/Parameters
- None required (no custom props)

### Triggered From
```php
// src/Kompo/AiChatPanel.php:135
->selfGet('openHelp')->inModal()

// src/Kompo/AiChatPanel.php:664-667
public function openHelp()
{
    return new ChatHelpModal();
}
```
- Triggered from help button in AiChatPanel header toolbar

### Form Actions
- **Close only** - "Got it!" button closes modal
- Self-GET tab switching via `showTab()` method

### Dependencies
- `HasChatTheme` trait (theming support)
- `Condoedge\Utils\Kompo\Common\Modal` (base class)

### Reference Status
- **ACTIVE** - Used in production UI

### Notes/Anomalies
1. **Embedded SVG icons** - Contains large inline SVG icon definitions (lines 187-200)
2. **Self-contained content** - All help text is hardcoded, not configurable
3. **No persistence** - Help content is static, no user tracking of viewed help

---

## 2. ChatSettingsModal

**File:** `src/Kompo/Modals/ChatSettingsModal.php`
**Lines:** 184

### What it Does
Allows users to configure their AI chat preferences:
- **Appearance:** Avatars, timestamps, metrics display
- **Features:** Suggestions, copy, feedback, regenerate, edit toggles
- **Response Style:** Friendly, professional, concise, or detailed
- **Theme:** Color theme selection (if user overrides allowed)

### Props/Parameters
- None required (auto-loads user settings via `AiUserSetting::forUser()`)

### Triggered From
```php
// src/Kompo/AiChatPanel.php:132
->selfGet('openSettings')->inModal()

// src/Kompo/AiChatPanel.php:659-662
public function openSettings()
{
    return new ChatSettingsModal();
}
```
- Triggered from settings button in AiChatPanel header toolbar

### Form Actions
- **Save Settings** - Saves to `AiUserSetting` model and syncs to session
- **Reset to Defaults** - Clears session and resets user theme
- **Cancel** - Closes modal without saving

### Dependencies
- `HasChatSettings` trait (settings access)
- `HasChatTheme` trait (theming support)
- `AiUserSetting` model (persistence)
- `ChatThemeFactoryInterface` (theme options)

### Reference Status
- **ACTIVE** - Used in production UI

### Notes/Anomalies
1. **Session sync in afterSave()** - Duplicates settings to session for immediate effect
2. **Conditional theme selector** - Only shown if `$factory->allowUserOverrides` is true
3. **Missing translation prefix** - Uses `translate.ai.themes.` which may not match actual translation keys

---

## 3. EditMessageModal

**File:** `src/Kompo/Modals/EditMessageModal.php`
**Lines:** 140

### What it Does
Allows users to edit their previously sent messages. When saved:
1. Updates the message content
2. Deletes all subsequent AI responses
3. Panel refresh triggers regeneration

### Props/Parameters
| Prop | Type | Description |
|------|------|-------------|
| `message_id` | int | ID of message to edit |
| `conversation_id` | int | ID of parent conversation |

### Triggered From
```php
// src/Kompo/AiChatPanel.php:259
->selfGet('editMessage', ['id' => $message->id])->inModal()

// src/Kompo/AiChatPanel.php:651-657
public function editMessage($id)
{
    return new EditMessageModal(null, [
        'message_id' => $id,
        'conversation_id' => $this->conversation?->id,
    ]);
}
```
- Triggered from edit button on user message hover actions

### Form Actions
- **Save & Regenerate** - Updates message, deletes subsequent messages, refreshes panel
- **Delete Message** - Deletes message and all subsequent messages
- **Cancel** - Closes modal without changes

### Dependencies
- `AiMessage` model
- `AiConversation` model
- `AiChatPanel::MESSAGES_PANEL_ID` (panel refresh target)

### Reference Status
- **ACTIVE** - Used in production UI

### Notes/Anomalies
1. **Cascade delete** - Both save and delete remove all messages after the edited one
2. **User-only editing** - Only messages with `role = 'user'` can be edited
3. **Authorization** - Validates conversation belongs to current user
4. **Hardcoded theme** - Uses `from-indigo-600 to-purple-600` gradient (not themed)

---

## 4. FilePreviewModal

**File:** `src/Kompo/Modals/FilePreviewModal.php`
**Lines:** 174

### What it Does
Displays file content referenced in AI responses with support for:
- **Images:** Direct preview with download option
- **PDFs:** Iframe embed with external link
- **Text/Code:** Syntax-highlighted pre block
- **Other:** Download link with file icon

### Props/Parameters
| Prop | Type | Description |
|------|------|-------------|
| `file_id` | int | ID of file to preview |
| `file_data` | array | Optional direct file data |

File data structure:
```php
[
    'name' => 'Document.pdf',
    'type' => 'pdf',
    'content' => null,      // For text files
    'url' => '/path/to/file',
    'size' => 1024,         // Optional, in bytes
]
```

### Triggered From
```php
// src/Kompo/AiChatPanel.php:473
->selfGet('viewFile', ['id' => $file['id']])->inModal()

// src/Kompo/AiChatPanel.php:646-649
public function viewFile($id)
{
    return new FilePreviewModal(null, ['file_id' => $id]);
}
```
- Triggered from file reference links in AI response content

### Form Actions
- **Download** - Downloads file from URL
- **Open in New Tab** - Opens file URL in new window
- **Close** - Closes modal

### Dependencies
- `Condoedge\Utils\Kompo\Common\Modal` (base class)
- No model dependencies (receives file data via props)

### Reference Status
- **ACTIVE** - Used in production UI

### Notes/Anomalies
1. **Placeholder implementation** - Comment says "In a real implementation, you would fetch the file details here" (line 24-25)
2. **No actual file fetching** - Relies entirely on props, no database lookup
3. **XSS protection** - Uses `e()` helper for URL/content escaping
4. **Hardcoded theme** - Uses `from-indigo-600 to-purple-600` gradient (not themed)

---

## Cross-Cutting Concerns

### Theme Consistency
| Modal | Uses HasChatTheme | Hardcoded Gradients |
|-------|-------------------|---------------------|
| ChatHelpModal | Yes | No (themed) |
| ChatSettingsModal | Yes | No (themed) |
| EditMessageModal | No | Yes (line 88) |
| FilePreviewModal | No | Yes (lines 53, 119) |

**Issue:** EditMessageModal and FilePreviewModal have hardcoded `from-indigo-600 to-purple-600` gradients instead of using the theme system.

### Modal Trigger Summary
All modals are triggered from `AiChatPanel.php`:
- Line 132: `openSettings` -> ChatSettingsModal
- Line 135: `openHelp` -> ChatHelpModal
- Line 259: `editMessage` -> EditMessageModal
- Line 473: `viewFile` -> FilePreviewModal

### Orphaned/Never Triggered Modals
**None found.** All 4 modals are actively triggered from AiChatPanel.

---

## Test Coverage

All modals have basic instantiation tests in `tests/Unit/Kompo/ChatComponentsBootTest.php`:
- `FilePreviewModal`: Lines 155-172
- `EditMessageModal`: Lines 178-191
- `ChatSettingsModal`: Lines 197-213
- `ChatHelpModal`: Lines 219-222

---

## Recommendations

### Priority: High
1. **Add HasChatTheme to EditMessageModal and FilePreviewModal** - For consistent theming

### Priority: Medium
2. **Implement actual file fetching in FilePreviewModal** - Currently a placeholder
3. **Fix translation keys in ChatSettingsModal** - Verify `translate.ai.themes.` prefix

### Priority: Low
4. **Extract SVG icons from ChatHelpModal** - Move to shared icon component
5. **Make ChatHelpModal content configurable** - Allow customization via config

---

## Files Audited

| File | Lines | Status |
|------|-------|--------|
| `src/Kompo/Modals/ChatHelpModal.php` | 204 | Active |
| `src/Kompo/Modals/ChatSettingsModal.php` | 184 | Active |
| `src/Kompo/Modals/EditMessageModal.php` | 140 | Active |
| `src/Kompo/Modals/FilePreviewModal.php` | 174 | Active |

**Total Lines:** 702
