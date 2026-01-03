# Module 01: UI_CHAT_INTERFACE - Analysis Plan

> **Module Slug:** ui-chat-interface
> **Priority:** HIGH (Entry point for all user interactions)
> **Estimated Files:** 17

---

## 1. Ideal vs Actual Responsibility

### Ideal Responsibility
- Render chat UI components (panel, messages, input form)
- Handle user input events (typing, sending, scrolling)
- Display AI responses with proper formatting (markdown, code highlighting)
- Manage visual state (loading indicators, animations, scroll position)
- Apply user theme preferences
- Delegate all business logic to services

### Actual Responsibility Hypotheses (To Verify)
- May contain business logic that should be in services
- May directly access models instead of through services
- May have tight coupling to specific service implementations

---

## 2. File-by-File Reading Plan

### Phase A: Core Components (Read First)
| Order | File | Purpose Hypothesis |
|-------|------|-------------------|
| 1 | `src/Kompo/AiChatPanel.php` | Main container component |
| 2 | `src/Kompo/ChatMessageForm.php` | Message input and submission |
| 3 | `src/Kompo/MessagesQuery.php` | Message display/query |
| 4 | `src/Kompo/AiChatFloating.php` | Floating chat widget |
| 5 | `src/Kompo/ConversationListQuery.php` | Conversation list display |

### Phase B: Supporting Components
| Order | File | Purpose Hypothesis |
|-------|------|-------------------|
| 6 | `src/Kompo/Components/TypingIndicator.php` | Loading/typing animation |
| 7 | `src/Kompo/Modals/ChatHelpModal.php` | Help dialog |
| 8 | `src/Kompo/Modals/ChatSettingsModal.php` | Settings dialog |
| 9 | `src/Kompo/Modals/EditMessageModal.php` | Message editing |
| 10 | `src/Kompo/Modals/FilePreviewModal.php` | File preview |

### Phase C: Traits (Shared Behavior)
| Order | File | Purpose Hypothesis |
|-------|------|-------------------|
| 11 | `src/Kompo/Traits/HasAvatars.php` | Avatar rendering |
| 12 | `src/Kompo/Traits/HasChatSettings.php` | Settings access |
| 13 | `src/Kompo/Traits/HasChatTheme.php` | Theme access |
| 14 | `src/Kompo/Traits/HasMethodsAsProperties.php` | Utility trait |

### Phase D: Frontend Assets
| Order | File | Purpose Hypothesis |
|-------|------|-------------------|
| 15 | `resources/css/ai-chat.css` | Chat styling |
| 16 | `resources/js/chat-message-injector.js` | Optimistic UI updates |
| 17 | `resources/js/chat-scroll.js` | Scroll behavior |

---

## 3. Dependency Descent Strategy

When UI components use other modules, inspect:

### Direct Dependencies
1. **SendMessageService** - Verify clean interface, no leaky abstractions
2. **AiConversation model** - Verify only used for display, not business logic
3. **AiMessage model** - Verify proper data access patterns
4. **Settings services** - Verify abstraction via traits
5. **Theme services** - Verify abstraction via traits

### Questions to Answer
- Does ChatMessageForm directly call AiManager or go through proper service layer?
- Does MessagesQuery contain business logic or just display logic?
- Are models accessed for read-only display or mutated directly?

---

## 4. Reference Tracing Plan

### Prove Usage (Inbound)
- Where is AiChatPanel instantiated? (Routes? Controllers? Other components?)
- Where is ChatMessageForm used?
- How are modals triggered?

### Prove Dependencies (Outbound)
- Trace SendMessageService usage in ChatMessageForm
- Trace model access patterns
- Trace trait method calls

---

## 5. Risk Map

| Category | Risk | Severity | Evidence Needed |
|----------|------|----------|-----------------|
| Correctness | Message display order incorrect | Medium | Test scroll/ordering |
| Security | XSS in message rendering | High | Check SafeMarkdownRenderer usage |
| Performance | Unnecessary re-renders | Low | Check component refresh logic |
| Maintainability | Business logic in UI | Medium | Audit all service calls |
| Usability | Poor mobile experience | Low | Check responsive CSS |

---

## 6. Edge Cases and Failure Modes

### User Input Edge Cases
- Empty message submission (should be blocked)
- Very long messages (10,000 char limit per validation)
- Messages with code blocks, markdown
- Messages with special characters

### Display Edge Cases
- Very long AI responses
- Responses with complex markdown/tables
- Error messages from AI
- Loading states during slow responses

### State Edge Cases
- Conversation not found
- User not authenticated
- Network failure during send

---

## 7. Contracts/Interfaces Expected

### Input Contracts
- User provides: conversation_id, message text, optional style
- Settings provide: theme, avatar preferences, response style

### Output Contracts
- Messages displayed in order
- Visual feedback on send (optimistic UI)
- Error states shown appropriately

---

## 8. Data Propagation Checks

| Data Point | Source | Consumer | Verify Consumed? |
|------------|--------|----------|------------------|
| conversation_id | Props | SendMessageService | YES |
| message text | Form input | SendMessageService | YES |
| response_style | Settings/Form | SendMessageService | YES |
| user | Auth | SendMessageService | YES |
| theme settings | UserChatSettings | UI rendering | VERIFY |
| avatar settings | UserChatSettings | UI rendering | VERIFY |

---

## 9. Cleanup/Refactor Strategy Options

### Option A: Keep As-Is (If Clean)
- If boundary is clean, no changes needed
- Pros: No risk, no effort
- Cons: None if already good

### Option B: Extract Business Logic (If Found)
- Move any business logic to services
- Pros: Cleaner separation
- Cons: Requires careful testing

### Option C: Consolidate Traits (If Redundant)
- If traits overlap, consolidate
- Pros: Less indirection
- Cons: May break existing usage

---

## 10. Documentation Impact Plan

### Docs to Update
- `resources/docs/1.0/chat/chat-ui.md` - Ensure matches implementation
- `docs/architecture.md` - Update UI section if needed

### New Docs Needed
- Component hierarchy diagram
- Props/configuration reference

---

## Agent Dispatch Instructions

When analyzing this module:
1. Read each file in order specified above
2. For each file, record: purpose, inputs, outputs, dependencies, callers
3. Trace all service calls to verify proper delegation
4. Check for any direct model mutations (should only be reads)
5. Verify trait usage is consistent
6. Check JS files for proper event handling
7. Document any boundary violations found
