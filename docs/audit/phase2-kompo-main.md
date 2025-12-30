# Phase 2 Audit: Kompo Main UI Components

## Overview

This audit reviews the main Kompo UI components in `src/Kompo/`:
- `AiChatFloating.php` - Floating action button
- `AiChatPanel.php` - Main chat interface
- `ChatMessageForm.php` - Message input form
- `ConversationListQuery.php` - Conversation list sidebar

## Component Hierarchy

```
AiChatFloating
  -> opens AiChatPanel (in modal)

AiChatPanel
  +-- ConversationListQuery (sidebar)
  +-- ChatMessageForm (input area)
  +-- Modals:
      +-- FilePreviewModal
      +-- EditMessageModal
      +-- ChatSettingsModal
      +-- ChatHelpModal
```

---

## 1. AiChatFloating.php

**Purpose:** Floating action button that opens the AI chat panel in a modal.

### Props/Configuration

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `position` | string | `'bottom-right'` | Corner position: bottom-right, bottom-left, top-right, top-left |
| `size` | string | `'lg'` | Button size: sm, md, lg |
| `theme` | string | `'gradient'` | Visual theme: gradient, solid, outline, dark |
| `label` | string | `null` | Optional text label next to icon |
| `pulse` | bool | `false` | Enable pulsing animation |
| `modal_config` | array | `[]` | Props passed to AiChatPanel when opened |

### Dependencies

- **Extends:** `Condoedge\Utils\Kompo\Common\Form`
- **Traits:** None
- **Services:** None

### Child Components

- Opens `AiChatPanel` via `openChatModal()` method

### Methods

| Method | Visibility | Used By | Notes |
|--------|------------|---------|-------|
| `created()` | public | Kompo lifecycle | Initializes props |
| `render()` | public | Kompo lifecycle | Renders the floating button |
| `openChatModal()` | public | Self via selfGet | Opens chat panel modal |
| `chatIcon()` | protected | render() | Returns SVG icon HTML |

### Reference Status

- Used directly in layouts to add floating chat button
- Referenced in: `config/ai.php`, tests, documentation

### Notes/Anomalies

- **Clean design**: Simple, single-responsibility component
- **No unused props or methods detected**

---

## 2. AiChatPanel.php

**Purpose:** Main chat interface with conversation management, message display, and input form.

### Props/Configuration

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `conversation_id` | int | `null` | Pre-select a specific conversation |

Note: Additional configuration comes from the `ChatSettingsInterface` service via the `HasChatSettings` trait.

### Dependencies

- **Extends:** `Condoedge\Utils\Kompo\Common\Form`
- **Traits:**
  - `HasChatSettings` - Access to chat settings (show_avatars, show_timestamps, etc.)
  - `HasChatTheme` - Theme colors and gradients
  - `HasAvatars` - User/assistant avatar HTML generation
  - `HasTypingIndicator` - Typing indicator display (UNUSED - see notes)
  - `HasMethodsAsProperties` - Enables property-style access to methods
- **Services:**
  - `AiChatServiceInterface` - Checks AI service availability
  - `SafeMarkdownRenderer` - Renders markdown safely

### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `ID` | `'chat-panel'` | Main panel ID |
| `MESSAGES_PANEL_ID` | `'chat-messages-panel'` | Messages container ID |
| `INPUT_PANEL_ID` | `'chat-input-panel'` | Input area container ID |

### Child Components

- `ConversationListQuery` - Sidebar conversation list
- `ChatMessageForm` - Message input form
- `FilePreviewModal` - File preview (via viewFile)
- `EditMessageModal` - Edit message (via editMessage)
- `ChatSettingsModal` - Settings (via openSettings)
- `ChatHelpModal` - Help modal (via openHelp)

### Action Methods (Public)

| Method | Parameters | Description |
|--------|------------|-------------|
| `createConversation()` | - | Creates new conversation |
| `selectConversation($id)` | conversation ID | Switches to conversation |
| `deleteConversation($id)` | conversation ID | Deletes conversation |
| `archiveConversation($id)` | conversation ID | Archives conversation |
| `togglePin($id)` | conversation ID | Pin/unpin conversation |
| `feedback($id, $type)` | message ID, 'positive'/'negative' | Submit message feedback |
| `regenerate($id)` | message ID | Regenerate assistant response |
| `askSuggestion($question)` | question text | Ask a suggested question |
| `viewFile($id)` | file ID | Open file preview modal |
| `editMessage($id)` | message ID | Open edit message modal |
| `openSettings()` | - | Open settings modal |
| `openHelp()` | - | Open help modal |
| `renderMessages()` | - | Returns messages content (for panel refresh) |

### Reference Status

- Used by: `AiChatFloating`, `ConversationListQuery.selectConversation()`
- Used in: tests, documentation

### Notes/Anomalies

1. **UNUSED TRAIT: `HasTypingIndicator`**
   - The trait is included but none of its methods are called:
     - `typingIndicator()` - not used
     - `typingIndicatorScript()` - not used
     - `hideTypingScript()` - not used
   - This suggests streaming/typing feedback was planned but not implemented

2. **Global function dependency**: Uses `currentTeamId()` (line 543) which may not exist in all environments

3. **Complex component**: 690 lines - largest component in the codebase

---

## 3. ChatMessageForm.php

**Purpose:** Input form for sending chat messages with optional style selector and quick actions.

### Props/Configuration

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `conversation_id` | int | `null` | Target conversation ID |
| `panel_id` | string | `AiChatPanel::MESSAGES_PANEL_ID` | Panel to refresh after send |
| `response_style` | string | `'friendly'` | Default response style |

### Config-based Features (via `cfg()`)

| Config Key | Type | Description |
|------------|------|-------------|
| `show_style_selector` | bool | Show/hide response style dropdown |
| `quick_actions` | array | Array of quick action buttons |

### Dependencies

- **Extends:** `Condoedge\Utils\Kompo\Common\Form`
- **Traits:**
  - `HasChatSettings` - Access to settings
  - `HasChatTheme` - Theme colors
- **Services:**
  - `AiChatServiceInterface` - AI chat service for sending messages

### Methods

| Method | Visibility | Used By | Notes |
|--------|------------|---------|-------|
| `created()` | public | Kompo lifecycle | |
| `render()` | public | Kompo lifecycle | |
| `responseStyleSelector()` | protected | render() | Conditional style dropdown |
| `quickActions()` | protected | render() | Conditional quick action buttons |
| `sendMessage()` | public | Submit action | Main send handler |
| `quickAction($action)` | public | Quick action buttons | |
| `rules()` | public | Validation | |
| `validationMessages()` | public | Validation | |

### Reference Status

- Used by: `AiChatPanel.inputArea()`, `AiChatPanel.regenerate()`
- Referenced in: tests, documentation

### Notes/Anomalies

1. **Config props never passed**: `show_style_selector` and `quick_actions` are accessed via `cfg()` but there's no documented way to pass them. They would need to be set via props array but aren't documented.

2. **Unused import**: `AiManager` is imported but `AiChatServiceInterface` is used instead

---

## 4. ConversationListQuery.php

**Purpose:** Displays filterable list of conversations in the sidebar.

### Props/Configuration

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `selected_id` | int | `null` | Currently selected conversation ID |

### Dependencies

- **Extends:** `Condoedge\Utils\Kompo\Common\Query`
- **Traits:**
  - `HasChatTheme` - Theme colors
- **Models:**
  - `AiConversation` - Query source

### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `ID` | `'conversation-list'` | Component ID |

### Methods

| Method | Visibility | Description |
|--------|------------|-------------|
| `created()` | public | Kompo lifecycle |
| `top()` | public | Search input and filter buttons |
| `query()` | public | Returns conversation query |
| `render($conversation)` | public | Renders single conversation row |
| `getPreviewText($message)` | protected | Formats message preview |
| `formatTime($date)` | protected | Human-friendly date formatting |
| `selectConversation($id)` | public | Returns new AiChatPanel for selection |

### Reference Status

- Used by: `AiChatPanel.sidebar()`
- Referenced in: tests, documentation

### Notes/Anomalies

1. **MySQL-specific query**: Uses `JSON_EXTRACT` and `IFNULL` in `orderByRaw()` which may not work with SQLite or PostgreSQL

---

## Trait Analysis

### HasChatSettings

- **Used by:** AiChatPanel, ChatMessageForm, ChatSettingsModal
- **Provides:** Access to chat settings via `settings()` method and shorthand methods
- **Status:** Actively used, well-designed

### HasChatTheme

- **Used by:** AiChatPanel, ChatMessageForm, ConversationListQuery, ChatSettingsModal, ChatHelpModal
- **Provides:** Theme colors via `theme()` method and shorthand methods
- **Status:** Actively used, well-designed

### HasAvatars

- **Used by:** AiChatPanel
- **Provides:** `userAvatarHtml()`, `assistantAvatarHtml()`, `welcomeAvatarHtml()`
- **Status:** All methods used

### HasTypingIndicator

- **Used by:** AiChatPanel (trait included but NOT USED)
- **Provides:** `typingIndicator()`, `typingIndicatorScript()`, `hideTypingScript()`
- **Status:** UNUSED - candidate for removal or implementation

### HasMethodsAsProperties

- **Used by:** AiChatPanel
- **Provides:** Magic `__get()` for snake_case property access to methods
- **Status:** Actively used (enables `$this->theme_gradient` syntax)

---

## Dead/Unused Code Summary

### Unused Traits/Methods

| Location | Item | Status |
|----------|------|--------|
| AiChatPanel | `HasTypingIndicator` trait | Included but no methods called |
| ChatMessageForm | `use AiManager` import | Not used (AiChatServiceInterface used instead) |

### Config Props with No Input Path

| Component | Config Key | Issue |
|-----------|------------|-------|
| ChatMessageForm | `show_style_selector` | No documented way to set |
| ChatMessageForm | `quick_actions` | No documented way to set |

### Test References to Missing Component

| Test File | Reference | Issue |
|-----------|-----------|-------|
| ChatComponentsBootTest.php | `AiChatModal` | Class does not exist |

---

## Recommendations

1. **Remove or implement HasTypingIndicator**: The trait is included in AiChatPanel but never used. Either implement typing feedback or remove the trait.

2. **Remove unused import**: Remove `use AiManager` from ChatMessageForm.

3. **Document config props**: Add documentation for `show_style_selector` and `quick_actions` configuration, or remove the functionality if not needed.

4. **Create AiChatModal or fix tests**: Either create the missing `AiChatModal` component or update the test file.

5. **Database compatibility**: Consider using Eloquent query builder methods instead of raw SQL in ConversationListQuery to ensure cross-database compatibility.

6. **Extract large methods**: AiChatPanel is 690 lines. Consider extracting some render methods into separate components or traits.

---

## Files Reviewed

- `src/Kompo/AiChatFloating.php` (102 lines)
- `src/Kompo/AiChatPanel.php` (690 lines)
- `src/Kompo/ChatMessageForm.php` (226 lines)
- `src/Kompo/ConversationListQuery.php` (122 lines)
- `src/Kompo/Traits/HasChatSettings.php` (189 lines)
- `src/Kompo/Traits/HasChatTheme.php` (52 lines)
- `src/Kompo/Traits/HasAvatars.php` (39 lines)
- `src/Kompo/Traits/HasTypingIndicator.php` (36 lines)
- `src/Kompo/Traits/HasMethodsAsProperties.php` (18 lines)
- `src/Kompo/Modals/FilePreviewModal.php` (174 lines)
- `src/Kompo/Modals/EditMessageModal.php` (140 lines)
- `src/Kompo/Modals/ChatSettingsModal.php` (184 lines)
- `src/Kompo/Modals/ChatHelpModal.php` (204 lines)

**Total:** ~2,176 lines of Kompo UI code reviewed
