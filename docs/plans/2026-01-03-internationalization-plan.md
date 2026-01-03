# Internationalization (i18n) Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace all hardcoded UI strings in the AI package with translatable keys using Laravel's `__()` helper.

**Architecture:** Follow SISC's JSON translation pattern:
- Flat JSON files: `resources/lang/en.json`, `resources/lang/fr.json`
- Namespaced keys: `ai.{section}.{key}` (e.g., `ai.settings.title`)
- Use `__('ai.settings.title')` in PHP code

**Tech Stack:** Laravel localization, JSON translation files

---

## Translation Key Naming Convention

```
ai.{component}.{key}

Examples:
- ai.settings.title → "Chat Settings"
- ai.settings.appearance → "Appearance"
- ai.chat.new-conversation → "New Conversation"
- ai.messages.copy → "Copy"
- ai.help.getting-started → "Getting Started"
```

---

## Task 1: Create Base Translation Files

**Files:**
- Create: `resources/lang/en.json`
- Create: `resources/lang/fr.json`

**Step 1: Create the resources/lang directory if needed**

```bash
mkdir -p resources/lang
```

**Step 2: Create en.json with all extracted strings**

The initial en.json should contain ALL strings from the components below. Structure:

```json
{
    "ai.common.cancel": "Cancel",
    "ai.common.close": "Close",
    "ai.common.save": "Save",
    "ai.common.delete": "Delete",
    "ai.common.edit": "Edit",
    "ai.common.download": "Download",
    "ai.common.online": "Online",
    "ai.common.offline": "Offline",

    "ai.settings.title": "Chat Settings",
    "ai.settings.theme": "Theme",
    "ai.settings.theme-description": "Choose your color theme",
    "ai.settings.appearance": "Appearance",
    "ai.settings.appearance-description": "How the chat looks",
    "ai.settings.show-avatars": "Show Avatars",
    "ai.settings.show-avatars-desc": "Display user and AI avatars",
    "ai.settings.show-timestamps": "Show Timestamps",
    "ai.settings.show-timestamps-desc": "Display message times",
    "ai.settings.show-metrics": "Show Metrics",
    "ai.settings.show-metrics-desc": "Display response time and confidence",
    "ai.settings.features": "Features",
    "ai.settings.features-description": "Chat capabilities",
    "ai.settings.follow-up-suggestions": "Follow-up Suggestions",
    "ai.settings.follow-up-suggestions-desc": "Show suggested questions",
    "ai.settings.copy-button": "Copy Button",
    "ai.settings.copy-button-desc": "Allow copying AI responses",
    "ai.settings.feedback-buttons": "Feedback Buttons",
    "ai.settings.feedback-buttons-desc": "Rate AI responses",
    "ai.settings.regenerate": "Regenerate",
    "ai.settings.regenerate-desc": "Allow regenerating responses",
    "ai.settings.edit-messages": "Edit Messages",
    "ai.settings.edit-messages-desc": "Allow editing your messages",
    "ai.settings.response-style": "Response Style",
    "ai.settings.response-style-description": "How the AI responds",
    "ai.settings.style-friendly": "Friendly - Casual and approachable",
    "ai.settings.style-professional": "Professional - Formal and business-like",
    "ai.settings.style-concise": "Concise - Brief and to the point",
    "ai.settings.style-detailed": "Detailed - Thorough explanations",
    "ai.settings.reset-defaults": "Reset to Defaults",
    "ai.settings.save-settings": "Save Settings",

    "ai.chat.new-conversation": "New Conversation",
    "ai.chat.search-placeholder": "Search conversations...",
    "ai.chat.filter-all": "All",
    "ai.chat.filter-pinned": "Pinned",
    "ai.chat.filter-archived": "Archived",
    "ai.chat.no-messages": "No messages yet",
    "ai.chat.you-prefix": "You: ",
    "ai.chat.yesterday": "Yesterday",
    "ai.chat.just-now": "Just now",
    "ai.chat.select-conversation": "Select a conversation",
    "ai.chat.select-conversation-desc": "Choose an existing conversation from the sidebar or start a new one.",
    "ai.chat.start-new": "Start New Chat",
    "ai.chat.try-asking": "Try asking:",
    "ai.chat.settings": "Settings",

    "ai.messages.pin": "Pin conversation",
    "ai.messages.unpin": "Unpin",
    "ai.messages.export": "Export",
    "ai.messages.archive": "Archive",
    "ai.messages.copy": "Copy",
    "ai.messages.helpful": "Helpful",
    "ai.messages.not-helpful": "Not helpful",
    "ai.messages.regenerate": "Regenerate",
    "ai.messages.follow-up": "Follow-up questions:",
    "ai.messages.click-to-view": "Click to view",

    "ai.edit.title": "Edit Message",
    "ai.edit.instruction": "Edit your message below. The AI will regenerate its response based on your updated message.",
    "ai.edit.placeholder": "Enter your message...",
    "ai.edit.original": "Original:",
    "ai.edit.delete-message": "Delete Message",
    "ai.edit.save-regenerate": "Save & Regenerate",
    "ai.edit.not-found": "Message not found",
    "ai.edit.not-found-desc": "This message may have been deleted or you don't have permission to edit it.",

    "ai.file.title": "File Preview",
    "ai.file.document": "Document",
    "ai.file.no-content": "No content available",
    "ai.file.preview-unavailable": "Preview not available for this file type",
    "ai.file.download": "Download File",
    "ai.file.open-new-tab": "Open in New Tab",
    "ai.file.type-image": "Image",
    "ai.file.type-pdf": "PDF",
    "ai.file.type-spreadsheet": "Spreadsheet",
    "ai.file.type-excel": "Excel Spreadsheet",
    "ai.file.type-code": "Code",
    "ai.file.type-text": "Text File",
    "ai.file.type-markdown": "Markdown",

    "ai.help.title": "Help & Tips",
    "ai.help.tab-getting-started": "Getting Started",
    "ai.help.tab-tips": "Tips & Tricks",
    "ai.help.tab-shortcuts": "Shortcuts",
    "ai.help.tab-faq": "FAQ",
    "ai.help.welcome-title": "Welcome to AI Assistant",
    "ai.help.welcome-desc": "Your intelligent companion for data exploration and analysis.",
    "ai.help.feature-conversations": "Natural Conversations",
    "ai.help.feature-conversations-desc": "Ask questions in plain English. No need for complex queries.",
    "ai.help.feature-search": "Smart Search",
    "ai.help.feature-search-desc": "Search across your data, documents, and history.",
    "ai.help.feature-suggestions": "Suggestions",
    "ai.help.feature-suggestions-desc": "Get follow-up questions to dive deeper into topics.",
    "ai.help.feature-export": "Easy Export",
    "ai.help.feature-export-desc": "Export conversations to Markdown for documentation.",
    "ai.help.getting-started-title": "Getting Started",
    "ai.help.getting-started-desc": "Try asking questions like:",
    "ai.help.example-customers": "How many customers do we have?",
    "ai.help.example-sales": "Show me last month's sales",
    "ai.help.example-products": "What are the top products?",
    "ai.help.tip-specific-title": "Be Specific",
    "ai.help.tip-specific-desc": "The more specific your question, the better the answer. \"Show sales for Q4 2024\" is better than \"show sales\".",
    "ai.help.tip-context-title": "Use Context",
    "ai.help.tip-context-desc": "Reference previous answers. Say \"break that down by region\" to get more detail.",
    "ai.help.tip-styles-title": "Try Different Styles",
    "ai.help.tip-styles-desc": "Use the style selector for different response formats - concise for quick answers, detailed for explanations.",
    "ai.help.tip-pin-title": "Pin Important Chats",
    "ai.help.tip-pin-desc": "Pin conversations you want to keep handy. They'll appear at the top of your list.",
    "ai.help.tip-feedback-title": "Use Feedback",
    "ai.help.tip-feedback-desc": "Rate responses with thumbs up/down to help improve future answers.",
    "ai.help.tip-edit-title": "Edit & Retry",
    "ai.help.tip-edit-desc": "Made a typo? Edit your message and the AI will regenerate its response.",
    "ai.help.shortcuts-title": "Keyboard Shortcuts",
    "ai.help.shortcut-send": "Send message",
    "ai.help.shortcut-newline": "New line",
    "ai.help.shortcut-close": "Close modal",
    "ai.help.shortcut-new-conv": "New conversation",
    "ai.help.shortcut-search": "Search conversations",
    "ai.help.shortcut-copy": "Copy last response",
    "ai.help.faq-data-title": "How is my data protected?",
    "ai.help.faq-data-desc": "Your conversations are stored securely and only accessible to you. We don't share your data with third parties.",
    "ai.help.faq-delete-title": "Can I delete my chat history?",
    "ai.help.faq-delete-desc": "Yes! You can delete individual conversations or archive them for later reference.",
    "ai.help.faq-wrong-title": "What if the AI gives wrong information?",
    "ai.help.faq-wrong-desc": "Use the feedback buttons to report incorrect answers. You can also regenerate responses or rephrase your question.",
    "ai.help.faq-limits-title": "Are there usage limits?",
    "ai.help.faq-limits-desc": "Usage depends on your plan. Check your account settings for current limits.",
    "ai.help.faq-export-title": "Can I export my conversations?",
    "ai.help.faq-export-desc": "Yes! Use the export button to download conversations as Markdown files.",
    "ai.help.got-it": "Got it!",

    "ai.form.style-friendly": "Friendly",
    "ai.form.style-professional": "Professional",
    "ai.form.style-concise": "Concise",
    "ai.form.style-detailed": "Detailed",
    "ai.form.response-style-tooltip": "Response style",
    "ai.form.quick-summarize": "Summarize our conversation so far",
    "ai.form.quick-clarify": "Can you clarify your last response?",
    "ai.form.quick-examples": "Can you provide some examples?",
    "ai.form.quick-alternatives": "What are some alternative approaches?",
    "ai.form.validation-required": "Please enter a message",
    "ai.form.validation-max": "Message is too long (max 10,000 characters)"
}
```

**Step 3: Create fr.json as copy (for translation later)**

Copy en.json to fr.json - values will be translated later.

**Step 4: Commit**

```bash
git add resources/lang/en.json resources/lang/fr.json
git commit -m "i18n: add base translation files with all AI package strings"
```

---

## Task 2: Update ChatSettingsModal.php

**File:** `src/Kompo/Modals/ChatSettingsModal.php`

**Strings to replace:**

| Line | Old | New |
|------|-----|-----|
| 21 | `'Chat Settings'` | `__('ai.settings.title')` |
| 56 | `'Theme'`, `'Choose your color theme'` | `__('ai.settings.theme')`, `__('ai.settings.theme-description')` |
| 61 | `'Appearance'`, `'How the chat looks'` | `__('ai.settings.appearance')`, `__('ai.settings.appearance-description')` |
| 63 | `'Show Avatars'`, `'Display user and AI avatars'` | `__('ai.settings.show-avatars')`, `__('ai.settings.show-avatars-desc')` |
| 64 | `'Show Timestamps'`, `'Display message times'` | `__('ai.settings.show-timestamps')`, `__('ai.settings.show-timestamps-desc')` |
| 65 | `'Show Metrics'`, `'Display response time and confidence'` | `__('ai.settings.show-metrics')`, `__('ai.settings.show-metrics-desc')` |
| 69 | `'Features'`, `'Chat capabilities'` | `__('ai.settings.features')`, `__('ai.settings.features-description')` |
| 71-75 | Toggle labels and descriptions | Use translation keys |
| 79 | `'Response Style'`, `'How the AI responds'` | Translation keys |
| 82-85 | Style options | Translation keys |
| 145 | `'Reset to Defaults'` | `__('ai.settings.reset-defaults')` |
| 149 | `'Cancel'` | `__('ai.common.cancel')` |
| 152 | `'Save Settings'` | `__('ai.settings.save-settings')` |

**Commit:** `git commit -m "i18n(settings): replace hardcoded strings with translation keys"`

---

## Task 3: Update ChatHelpModal.php

**File:** `src/Kompo/Modals/ChatHelpModal.php`

**Strings to replace:** ~50 strings including:
- Modal title (line 16)
- Tab labels (lines 38-41)
- Help section content
- Feature cards
- Tips
- Shortcuts
- FAQs

**Commit:** `git commit -m "i18n(help): replace hardcoded strings with translation keys"`

---

## Task 4: Update EditMessageModal.php

**File:** `src/Kompo/Modals/EditMessageModal.php`

**Strings to replace:**
- Line 19: Modal title
- Line 67: Instruction text
- Line 72: Placeholder
- Line 74: "Original:"
- Lines 83-92: Button labels
- Lines 105-108: Error messages

**Commit:** `git commit -m "i18n(edit-modal): replace hardcoded strings with translation keys"`

---

## Task 5: Update FilePreviewModal.php

**File:** `src/Kompo/Modals/FilePreviewModal.php`

**Strings to replace:**
- Line 13, 33: Modal title
- Line 27, 55, 161: "Document"
- Line 92: "No content available"
- Line 99: "Preview not available..."
- Line 105, 118: "Download File"
- Line 123: "Open in New Tab"
- Line 129: "Close"
- Lines 151-158: File type labels

**Commit:** `git commit -m "i18n(file-preview): replace hardcoded strings with translation keys"`

---

## Task 6: Update ConversationListQuery.php

**File:** `src/Kompo/ConversationListQuery.php`

**Strings to replace:**
- Line 37: "Search conversations..."
- Lines 48-50: Already use __() - verify keys exist
- Line 76: "New Conversation"
- Line 97: "No messages yet"
- Line 100: "You: "
- Line 115: "Yesterday"

**Commit:** `git commit -m "i18n(conversation-list): replace hardcoded strings with translation keys"`

---

## Task 7: Update MessagesQuery.php

**File:** `src/Kompo/MessagesQuery.php`

**Strings to replace:**
- Line 75: "New Conversation"
- Line 84: "Just now"
- Lines 101-116: Balloon tooltips
- Line 178: "Edit"
- Lines 235-265: Balloon tooltips
- Line 284: "Follow-up questions:"
- Line 332: "Value"
- Line 395: "Click to view"
- Lines 403-406: Empty state
- Line 432: "Try asking:"

**Commit:** `git commit -m "i18n(messages): replace hardcoded strings with translation keys"`

---

## Task 8: Update AiChatPanel.php

**File:** `src/Kompo/AiChatPanel.php`

**Strings to replace:**
- Line 100: "Online" / "Offline"
- Line 115: "New conversation"
- Line 124: "Settings"

**Commit:** `git commit -m "i18n(chat-panel): replace hardcoded strings with translation keys"`

---

## Task 9: Update ChatMessageForm.php

**File:** `src/Kompo/ChatMessageForm.php`

**Strings to replace:**
- Lines 77-80: Style labels
- Line 84: "Response style" balloon
- Lines 171-174: Quick action labels
- Lines 193-194: Validation messages

**Commit:** `git commit -m "i18n(message-form): replace hardcoded strings with translation keys"`

---

## Task 10: Register Lang Path in Service Provider

**File:** `src/AiServiceProvider.php`

**Add to boot() method:**

```php
// Load translations
$this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');
```

**Also publish translations:**

```php
// In boot(), add to publishes
$this->publishes([
    __DIR__.'/../resources/lang' => resource_path('lang/vendor/ai'),
], 'ai-lang');
```

**Commit:** `git commit -m "i18n: register translation files in service provider"`

---

## Summary

| Task | File | Strings | Status |
|------|------|---------|--------|
| 1 | Translation files | ~120 | Create |
| 2 | ChatSettingsModal | ~25 | Replace |
| 3 | ChatHelpModal | ~50 | Replace |
| 4 | EditMessageModal | ~10 | Replace |
| 5 | FilePreviewModal | ~15 | Replace |
| 6 | ConversationListQuery | ~8 | Replace |
| 7 | MessagesQuery | ~20 | Replace |
| 8 | AiChatPanel | ~5 | Replace |
| 9 | ChatMessageForm | ~10 | Replace |
| 10 | AiServiceProvider | 1 | Add |

**Total:** ~120 unique translation keys across 10 files.

**Execution approach:** Complete Task 1 first (create all translation keys), then Tasks 2-9 can be parallelized with subagents since they're independent file changes.
