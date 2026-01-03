# Chat UX Improvements: Typing Animation, Message Animations, Scroll Direction & Smart Reload

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Transform the chat experience with polished typing indicators, smooth message animations, reversed scroll direction (newest at bottom), and intelligent DOM updates without full page reloads.

**Architecture:** Extend existing Kompo chat components with CSS animations, JavaScript placeholder injection pattern (from auth package), and reversed scroll pagination. Use the same tools and patterns already established in the kompo ecosystem.

**Tech Stack:** PHP 8.2, Laravel 11, Kompo Components, Tailwind CSS, Vanilla JavaScript

---

## Research Summary

### Current State Analysis

**ChatMessageForm.php** (line 145):
- Has comment `// Loading typing animation` but no implementation
- Uses `setLoadingMessage()` to show user message instantly
- Refreshes `MessagesQuery` after `sendMessage()` completes

**MessagesQuery.php**:
- Uses `$paginationType = 'Scroll'` with `$perPage = 200`
- Orders messages by `created_at ASC` (oldest first)
- Has `scrollScript()` that scrolls to bottom with `scrollTo({ top: c.scrollHeight })`
- Current scroll: newest messages at bottom, but loading more scrolls down (wrong direction)

**ai-chat.css** (existing animations):
- `typingBounce` - dots bounce animation (1.4s)
- `typingWave` - wave animation (1s)
- `fadeSlideIn` - fade + slide down (300ms)
- `slideInRight/slideInLeft` - directional slides
- CSS classes: `.ai-typing-dots`, `.ai-typing-wave`

### Auth Package Placeholder Pattern (roles-manager.js):
1. `precreateRoleVisuals()` - Creates placeholders with `data-void="true"`
2. `injectRoleContent()` - Replaces placeholders with real content via `parentNode.replaceChild()`
3. Pattern avoids full page reloads by DOM manipulation

---

## Task 1: Create Typing Animation Component

**Files:**
- Create: `src/Kompo/Components/TypingIndicator.php`
- Modify: `src/Kompo/ChatMessageForm.php:143-148`
- Modify: `resources/css/ai-chat.css`

**Step 1: Create the TypingIndicator component**

Create file `src/Kompo/Components/TypingIndicator.php`:

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Components;

use Condoedge\Ai\Kompo\Traits\HasChatTheme;

/**
 * TypingIndicator - Beautiful typing animation component
 *
 * Displays an animated typing indicator while waiting for AI response.
 * Supports multiple animation styles: dots, wave, pulse, brain.
 */
class TypingIndicator
{
    use HasChatTheme;

    protected string $style = 'dots';
    protected string $message = '';
    protected bool $showAvatar = true;

    public function __construct(array $options = [])
    {
        $this->style = $options['style'] ?? 'dots';
        $this->message = $options['message'] ?? '';
        $this->showAvatar = $options['show_avatar'] ?? true;
    }

    /**
     * Render the typing indicator
     */
    public function render()
    {
        $avatar = $this->showAvatar ? $this->assistantAvatarHtml() : '';

        return _Flex(
            $avatar ? _Html($avatar)->class('mr-3 flex-shrink-0 self-start') : null,
            _Rows(
                $this->getAnimationHtml(),
                $this->message ? _Html($this->message)->class('text-xs text-gray-400 mt-2') : null,
            )->class('px-5 py-4 rounded-2xl rounded-tl-md bg-white border border-gray-100 shadow-sm'),
        )->class('items-start animate-fade-in');
    }

    /**
     * Get the animation HTML based on style
     */
    protected function getAnimationHtml()
    {
        return match($this->style) {
            'wave' => $this->waveAnimation(),
            'pulse' => $this->pulseAnimation(),
            'brain' => $this->brainAnimation(),
            default => $this->dotsAnimation(),
        };
    }

    /**
     * Classic bouncing dots animation
     */
    protected function dotsAnimation()
    {
        $gradient = $this->theme()->primaryGradient();

        return _Html('
            <div class="ai-typing-dots flex items-center gap-1.5 h-6">
                <span class="w-2.5 h-2.5 rounded-full bg-gradient-to-r ' . $gradient . ' animate-typing-dot-1"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-gradient-to-r ' . $gradient . ' animate-typing-dot-2"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-gradient-to-r ' . $gradient . ' animate-typing-dot-3"></span>
            </div>
        ');
    }

    /**
     * Sound wave style animation
     */
    protected function waveAnimation()
    {
        $gradient = $this->theme()->primaryGradient();

        return _Html('
            <div class="ai-typing-wave flex items-end gap-1 h-6">
                <span class="w-1 bg-gradient-to-t ' . $gradient . ' rounded-full animate-wave-bar-1"></span>
                <span class="w-1 bg-gradient-to-t ' . $gradient . ' rounded-full animate-wave-bar-2"></span>
                <span class="w-1 bg-gradient-to-t ' . $gradient . ' rounded-full animate-wave-bar-3"></span>
                <span class="w-1 bg-gradient-to-t ' . $gradient . ' rounded-full animate-wave-bar-4"></span>
                <span class="w-1 bg-gradient-to-t ' . $gradient . ' rounded-full animate-wave-bar-5"></span>
            </div>
        ');
    }

    /**
     * Pulsing circle animation
     */
    protected function pulseAnimation()
    {
        $gradient = $this->theme()->primaryGradient();

        return _Html('
            <div class="ai-typing-pulse relative flex items-center justify-center w-10 h-6">
                <span class="absolute w-4 h-4 rounded-full bg-gradient-to-r ' . $gradient . ' animate-typing-pulse-inner"></span>
                <span class="absolute w-6 h-6 rounded-full bg-gradient-to-r ' . $gradient . ' opacity-30 animate-typing-pulse-outer"></span>
                <span class="absolute w-8 h-8 rounded-full bg-gradient-to-r ' . $gradient . ' opacity-10 animate-typing-pulse-glow"></span>
            </div>
        ');
    }

    /**
     * Brain/thinking animation with sparkles
     */
    protected function brainAnimation()
    {
        $primary = $this->theme()->primaryText();

        return _Html('
            <div class="ai-typing-brain flex items-center gap-2 h-6">
                <svg class="w-5 h-5 ' . $primary . ' animate-thinking-brain" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C8.5 2 5.5 4.5 5 8c-2 .5-3.5 2.3-3.5 4.5 0 2.5 2 4.5 4.5 4.5h12c2.5 0 4.5-2 4.5-4.5 0-2.2-1.5-4-3.5-4.5-.5-3.5-3.5-6-7-6z"/>
                </svg>
                <div class="flex gap-0.5">
                    <span class="w-1 h-1 rounded-full bg-amber-400 animate-sparkle-1"></span>
                    <span class="w-1 h-1 rounded-full bg-amber-400 animate-sparkle-2"></span>
                    <span class="w-1 h-1 rounded-full bg-amber-400 animate-sparkle-3"></span>
                </div>
            </div>
        ');
    }

    /**
     * Get assistant avatar HTML (from HasAvatars trait pattern)
     */
    protected function assistantAvatarHtml(): string
    {
        return '
            <div class="ai-avatar-animated relative">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-violet-500 via-purple-500 to-fuchsia-500 flex items-center justify-center shadow-lg">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                </div>
            </div>
        ';
    }
}
```

**Step 2: Add CSS animations for typing indicators**

Add to `resources/css/ai-chat.css` (at the end of the Animations section, after line 706):

```css
/* ==========================================================================
   Enhanced Typing Indicator Animations
   ========================================================================== */

/* Bouncing Dots Animation */
@keyframes typingDot {
    0%, 80%, 100% {
        transform: translateY(0);
        opacity: 0.5;
    }
    40% {
        transform: translateY(-8px);
        opacity: 1;
    }
}

.animate-typing-dot-1 {
    animation: typingDot 1.4s ease-in-out infinite;
}

.animate-typing-dot-2 {
    animation: typingDot 1.4s ease-in-out infinite 0.2s;
}

.animate-typing-dot-3 {
    animation: typingDot 1.4s ease-in-out infinite 0.4s;
}

/* Wave Bars Animation */
@keyframes waveBar {
    0%, 100% {
        height: 8px;
        opacity: 0.4;
    }
    50% {
        height: 20px;
        opacity: 1;
    }
}

.animate-wave-bar-1 {
    animation: waveBar 0.8s ease-in-out infinite 0s;
}

.animate-wave-bar-2 {
    animation: waveBar 0.8s ease-in-out infinite 0.1s;
}

.animate-wave-bar-3 {
    animation: waveBar 0.8s ease-in-out infinite 0.2s;
}

.animate-wave-bar-4 {
    animation: waveBar 0.8s ease-in-out infinite 0.3s;
}

.animate-wave-bar-5 {
    animation: waveBar 0.8s ease-in-out infinite 0.4s;
}

/* Pulse Animation */
@keyframes typingPulseInner {
    0%, 100% {
        transform: scale(0.8);
        opacity: 1;
    }
    50% {
        transform: scale(1);
        opacity: 0.8;
    }
}

@keyframes typingPulseOuter {
    0%, 100% {
        transform: scale(1);
        opacity: 0.3;
    }
    50% {
        transform: scale(1.3);
        opacity: 0.1;
    }
}

@keyframes typingPulseGlow {
    0%, 100% {
        transform: scale(1);
        opacity: 0.1;
    }
    50% {
        transform: scale(1.5);
        opacity: 0;
    }
}

.animate-typing-pulse-inner {
    animation: typingPulseInner 1.5s ease-in-out infinite;
}

.animate-typing-pulse-outer {
    animation: typingPulseOuter 1.5s ease-in-out infinite;
}

.animate-typing-pulse-glow {
    animation: typingPulseGlow 1.5s ease-in-out infinite;
}

/* Brain/Thinking Animation */
@keyframes thinkingBrain {
    0%, 100% {
        transform: scale(1);
        filter: brightness(1);
    }
    25% {
        transform: scale(1.05);
        filter: brightness(1.2);
    }
    50% {
        transform: scale(1);
        filter: brightness(1);
    }
    75% {
        transform: scale(1.03);
        filter: brightness(1.1);
    }
}

@keyframes sparkle {
    0%, 100% {
        transform: scale(0) rotate(0deg);
        opacity: 0;
    }
    50% {
        transform: scale(1) rotate(180deg);
        opacity: 1;
    }
}

.animate-thinking-brain {
    animation: thinkingBrain 2s ease-in-out infinite;
}

.animate-sparkle-1 {
    animation: sparkle 1.2s ease-in-out infinite 0s;
}

.animate-sparkle-2 {
    animation: sparkle 1.2s ease-in-out infinite 0.4s;
}

.animate-sparkle-3 {
    animation: sparkle 1.2s ease-in-out infinite 0.8s;
}
```

**Step 3: Update ChatMessageForm to use TypingIndicator**

Modify `src/Kompo/ChatMessageForm.php` lines 143-148. Replace:

```php
return _Rows(
    $messageQueryForm->userBubble($tempMessage),

    // Loading typing animation
);
```

With:

```php
$typingIndicator = new \Condoedge\Ai\Kompo\Components\TypingIndicator([
    'style' => $this->settings()->typingAnimationStyle() ?? 'dots',
    'message' => __('ai.chat.thinking'),
    'show_avatar' => $this->settings()->showAvatars(),
]);

return _Rows(
    $messageQueryForm->userBubble($tempMessage),
    $typingIndicator->render(),
);
```

**Step 4: Add typingAnimationStyle to ChatSettingsInterface**

Add method to `src/Contracts/ChatSettingsInterface.php`:

```php
/**
 * Get the typing animation style
 * Options: 'dots', 'wave', 'pulse', 'brain'
 */
public function typingAnimationStyle(): string;
```

Add implementation to `src/Services/ChatSettings.php`:

```php
public function typingAnimationStyle(): string
{
    return $this->get('typing_animation_style', 'dots');
}
```

**Step 5: Add translation**

Add to `resources/lang/en.json`:

```json
"ai.chat.thinking": "Thinking..."
```

Add to `resources/lang/fr.json`:

```json
"ai.chat.thinking": "En cours de reflexion..."
```

---

## Task 2: Create Message Appearance Animations

**Files:**
- Modify: `src/Kompo/MessagesQuery.php:163-184` (userBubble)
- Modify: `src/Kompo/MessagesQuery.php:186-226` (assistantBubble)
- Modify: `resources/css/ai-chat.css`

**Step 1: Add entrance animation CSS**

Add to `resources/css/ai-chat.css`:

```css
/* ==========================================================================
   Message Entrance Animations
   ========================================================================== */

/* User Message - Slide from right */
@keyframes messageSlideInRight {
    0% {
        opacity: 0;
        transform: translateX(30px) scale(0.95);
    }
    60% {
        transform: translateX(-5px) scale(1.02);
    }
    100% {
        opacity: 1;
        transform: translateX(0) scale(1);
    }
}

/* Assistant Message - Slide from left */
@keyframes messageSlideInLeft {
    0% {
        opacity: 0;
        transform: translateX(-30px) scale(0.95);
    }
    60% {
        transform: translateX(5px) scale(1.02);
    }
    100% {
        opacity: 1;
        transform: translateX(0) scale(1);
    }
}

/* Content Reveal - For rich content within messages */
@keyframes contentReveal {
    0% {
        opacity: 0;
        max-height: 0;
        transform: translateY(-10px);
    }
    100% {
        opacity: 1;
        max-height: 2000px;
        transform: translateY(0);
    }
}

/* Subtle pop for new messages */
@keyframes messagePop {
    0% {
        opacity: 0;
        transform: scale(0.8);
    }
    50% {
        transform: scale(1.02);
    }
    100% {
        opacity: 1;
        transform: scale(1);
    }
}

/* Animation classes */
.animate-message-user {
    animation: messageSlideInRight 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

.animate-message-assistant {
    animation: messageSlideInLeft 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

.animate-message-pop {
    animation: messagePop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

.animate-content-reveal {
    animation: contentReveal 0.5s ease-out forwards;
    overflow: hidden;
}

/* Staggered content animation for rich data */
.animate-stagger-content > * {
    opacity: 0;
    animation: fadeSlideIn 0.3s ease-out forwards;
}

.animate-stagger-content > *:nth-child(1) { animation-delay: 0.1s; }
.animate-stagger-content > *:nth-child(2) { animation-delay: 0.2s; }
.animate-stagger-content > *:nth-child(3) { animation-delay: 0.3s; }
.animate-stagger-content > *:nth-child(4) { animation-delay: 0.4s; }
.animate-stagger-content > *:nth-child(5) { animation-delay: 0.5s; }

/* Highlight effect for newly added messages */
@keyframes newMessageHighlight {
    0% {
        box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(99, 102, 241, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(99, 102, 241, 0);
    }
}

.animate-new-message-highlight {
    animation: newMessageHighlight 1s ease-out;
}
```

**Step 2: Update userBubble in MessagesQuery.php**

Modify `src/Kompo/MessagesQuery.php` userBubble method (lines 163-184). Replace with:

```php
public function userBubble($message)
{
    $isNew = $this->isNewMessage($message);
    $animationClass = $isNew ? 'animate-message-user' : '';

    return _Rows(
        _FlexEnd(
            _Rows(
                _Html(e($message->content))->class('whitespace-pre-wrap'),
                $this->settings()->showTimestamps()
                    ? _Html($message->created_at?->format('g:i A') ?? '')->class('text-xs opacity-60 mt-2')
                    : null,
            )->class('group px-4 py-3 rounded-2xl rounded-tr-md max-w-xl bg-gradient-to-r ' . $this->theme()->primaryGradient() . ' text-white shadow-md ' . ($isNew ? 'animate-new-message-highlight' : '')),
            $this->settings()->showAvatars()
                ? _Html($this->userAvatarHtml())->class('ml-3 flex-shrink-0')
                : null,
        )->class('items-end ' . $animationClass),
        // Edit button on hover
        $this->settings()->enableEdit() && $message->id ? _FlexEnd(
            _Link(__('ai.common.edit'))->icon('pencil')
                ->class('opacity-0 group-hover:opacity-100 text-xs text-gray-400 hover:text-gray-600 mt-1 transition-opacity')
                ->selfGet('editMessage', ['id' => $message->id])->inModal(),
        ) : null,
    )->class('group');
}
```

**Step 3: Update assistantBubble in MessagesQuery.php**

Modify the assistantBubble method. Add animation class to the outer container:

```php
public function assistantBubble($message)
{
    $isNew = $this->isNewMessage($message);
    $animationClass = $isNew ? 'animate-message-assistant' : '';

    $content = [];

    // Main content with reveal animation for new messages
    $content[] = _Html($this->renderMarkdown($message->content))
        ->class('prose prose-sm max-w-none' . ($isNew ? ' animate-content-reveal' : ''));

    // Rich data display (tables, metrics, lists) - with staggered animation
    if ($responseData = $message->response_data) {
        $richContent = $this->renderRichData($responseData);
        if ($richContent) {
            $content[] = _Rows($richContent)->class($isNew ? 'animate-stagger-content' : '');
        }
    }

    // File references
    if ($message->hasFileReferences()) {
        $content[] = $this->renderFileReferences($message->getReferencedFiles());
    }

    // Follow-up suggestions
    $suggestions = $message->metadata['suggestions'] ?? [];
    if ($this->settings()->showSuggestions() && !empty($suggestions)) {
        $content[] = $this->renderSuggestions($suggestions);
    }

    // Metrics (execution time, confidence)
    if ($this->settings()->showMetrics() && $message->execution_time_ms) {
        $content[] = $this->renderMetrics($message);
    }

    // Action bar (copy, feedback, regenerate)
    $content[] = $this->messageActionBar($message);

    return _Rows(
        _Flex(
            $this->settings()->showAvatars()
                ? _Html($this->assistantAvatarHtml())->class('mr-3 flex-shrink-0 self-start')
                : null,
            _Rows(...$content)
                ->class('group px-5 py-4 rounded-2xl rounded-tl-md max-w-2xl bg-white border border-gray-100 shadow-sm hover:shadow-md transition-shadow'),
        )->class('items-start ' . $animationClass),
    );
}
```

**Step 4: Add isNewMessage helper method**

Add this helper to MessagesQuery.php (after line 44):

```php
/**
 * Check if message is newly added (for animation purposes)
 * Messages created within the last 5 seconds are considered "new"
 */
protected function isNewMessage($message): bool
{
    if (!$message->created_at) {
        return true; // Temp messages are always "new"
    }

    return $message->created_at->diffInSeconds(now()) < 5;
}
```

---

## Task 3: Fix Scroll Direction (Newest at Bottom, Load Older Up)

**Files:**
- Modify: `src/Kompo/MessagesQuery.php`
- Create: `resources/js/chat-scroll.js`

**Step 1: Understand the problem**

Current behavior:
- Messages ordered by `created_at ASC` (oldest first)
- New messages appear at bottom
- Scroll pagination loads MORE items at the bottom
- User has to scroll DOWN to see older messages (wrong!)

Desired behavior:
- Messages ordered by `created_at ASC` (oldest first) - KEEP THIS
- New messages appear at bottom - KEEP THIS
- Scroll UP to load older messages
- Initial view: scrolled to bottom (newest visible)

**Step 2: Create custom scroll handler JavaScript**

Create `resources/js/chat-scroll.js`:

```javascript
/**
 * Chat Scroll Manager
 *
 * Handles reverse scroll pagination for chat messages.
 * - Loads older messages when scrolling UP (to top)
 * - Maintains scroll position when new messages are loaded
 * - Smooth scroll to bottom for new messages
 */
class ChatScrollManager {
    constructor(containerId) {
        this.containerId = containerId;
        this.container = null;
        this.isLoadingMore = false;
        this.previousScrollHeight = 0;
        this.init();
    }

    init() {
        // Wait for DOM
        this.waitForContainer().then(container => {
            this.container = container;
            this.setupScrollListener();
            this.scrollToBottom(false);
        });
    }

    waitForContainer() {
        return new Promise((resolve) => {
            const check = () => {
                const panel = document.getElementById(this.containerId);
                const wrapper = panel?.querySelector('.vlQueryWrapper');
                if (wrapper) {
                    resolve(wrapper);
                } else {
                    setTimeout(check, 100);
                }
            };
            check();
        });
    }

    setupScrollListener() {
        this.container.addEventListener('scroll', () => {
            this.handleScroll();
        });
    }

    handleScroll() {
        // Detect scroll to top (for loading older messages)
        if (this.container.scrollTop < 100 && !this.isLoadingMore) {
            this.loadOlderMessages();
        }
    }

    loadOlderMessages() {
        // Store current scroll position
        this.previousScrollHeight = this.container.scrollHeight;
        this.isLoadingMore = true;

        // Trigger Kompo's browse action
        // This will be handled by the PHP component
        const event = new CustomEvent('chat-load-older', {
            detail: { containerId: this.containerId }
        });
        document.dispatchEvent(event);
    }

    onMessagesLoaded() {
        // Maintain scroll position after loading older messages
        const newScrollHeight = this.container.scrollHeight;
        const scrollDiff = newScrollHeight - this.previousScrollHeight;
        this.container.scrollTop = scrollDiff;
        this.isLoadingMore = false;
    }

    scrollToBottom(smooth = true) {
        if (!this.container) return;

        setTimeout(() => {
            this.container.scrollTo({
                top: this.container.scrollHeight,
                behavior: smooth ? 'smooth' : 'auto'
            });
        }, 100);
    }

    /**
     * Add new message with animation
     */
    addNewMessage(messageHtml, isUser = false) {
        // Create placeholder
        const placeholder = document.createElement('div');
        placeholder.setAttribute('data-new-message', 'true');
        placeholder.innerHTML = messageHtml;

        // Find the loading panel and insert before it
        const loadingPanel = document.getElementById('temp-message-loading');
        if (loadingPanel) {
            loadingPanel.parentNode.insertBefore(placeholder, loadingPanel);
        } else {
            this.container.appendChild(placeholder);
        }

        // Scroll to show new message
        this.scrollToBottom(true);
    }
}

// Global instance
window.chatScrollManager = null;

// Initialize when chat panel loads
document.addEventListener('DOMContentLoaded', () => {
    initChatScroll();
});

function initChatScroll() {
    const panelId = 'chat-messages-panel';
    if (document.getElementById(panelId)) {
        window.chatScrollManager = new ChatScrollManager(panelId);
    }
}

// Re-initialize after Kompo refreshes
document.addEventListener('kompo:mounted', () => {
    initChatScroll();
});

// Handle loading completion
document.addEventListener('chat-messages-loaded', () => {
    if (window.chatScrollManager) {
        window.chatScrollManager.onMessagesLoaded();
    }
});
```

**Step 3: Modify MessagesQuery for proper scroll handling**

Modify `src/Kompo/MessagesQuery.php` `$itemsWrapperClass`:

```php
public $itemsWrapperClass = '[&>div]:gap-4 [&>div]:flex [&>div]:flex-col p-6 overflow-y-auto mini-scroll h-full';
```

And modify the `$style`:

```php
public $style = 'max-height: 95vh; display: flex; flex-direction: column;';
```

**Step 4: Add onLoad scroll initialization**

Modify the `bottom()` method in MessagesQuery.php to include scroll initialization:

```php
public function bottom()
{
    if (!$this->conversation) {
        return null;
    }

    return _Rows(
        new ChatMessageForm(null, [
            'conversation_id' => $this->conversation->id,
            'response_style' => $this->settings()->responseStyle(),
        ]),

        _Hidden()->onLoad->run($this->scrollScript(false)),

        // Initialize scroll manager
        _Hidden()->onLoad->run("() => {
            if (typeof initChatScroll === 'function') {
                initChatScroll();
            }
        }"),
    );
}
```

**Step 5: Register the JavaScript file**

Add to `src/AiServiceProvider.php` in the `boot` method:

```php
// Register chat scroll JavaScript
$this->publishes([
    __DIR__.'/../resources/js/chat-scroll.js' => public_path('vendor/condoedge/ai/js/chat-scroll.js'),
], 'ai-assets');
```

---

## Task 4: Implement Placeholder Injection Pattern for Smart Reload

**Files:**
- Create: `resources/js/chat-message-injector.js`
- Modify: `src/Kompo/ChatMessageForm.php`
- Modify: `src/Kompo/MessagesQuery.php`

**Step 1: Create the message injector JavaScript**

Create `resources/js/chat-message-injector.js`:

```javascript
/**
 * Chat Message Injector
 *
 * Implements the placeholder injection pattern (from auth/roles-manager.js)
 * to avoid full page reloads when adding new messages.
 *
 * Pattern:
 * 1. precreateMessagePlaceholder() - Creates placeholder with typing animation
 * 2. injectMessageContent() - Replaces placeholder with actual message
 */

const ChatMessageInjector = {
    /**
     * Panel ID for the messages container
     */
    panelId: 'chat-messages-panel',
    loadingPanelId: 'temp-message-loading',

    /**
     * Create a placeholder for a new user message + typing indicator
     * Called immediately when user clicks send
     */
    precreateMessagePlaceholder(userMessage, userAvatarHtml) {
        const loadingPanel = document.getElementById(this.loadingPanelId);
        if (!loadingPanel) {
            console.warn('Loading panel not found');
            return;
        }

        // Create user message placeholder
        const userBubbleHtml = this.createUserBubbleHtml(userMessage, userAvatarHtml);
        const userBubble = document.createElement('div');
        userBubble.setAttribute('data-placeholder', 'user-message');
        userBubble.setAttribute('data-void', 'true');
        userBubble.innerHTML = userBubbleHtml;
        userBubble.className = 'animate-message-user';

        // Create typing indicator placeholder
        const typingHtml = this.createTypingIndicatorHtml();
        const typingIndicator = document.createElement('div');
        typingIndicator.setAttribute('data-placeholder', 'typing-indicator');
        typingIndicator.setAttribute('data-void', 'true');
        typingIndicator.innerHTML = typingHtml;
        typingIndicator.className = 'animate-fade-in';

        // Insert into loading panel
        loadingPanel.innerHTML = '';
        loadingPanel.appendChild(userBubble);
        loadingPanel.appendChild(typingIndicator);

        // Scroll to bottom
        this.scrollToBottom();
    },

    /**
     * Inject the actual assistant response, replacing the typing indicator
     */
    injectAssistantMessage(responseHtml) {
        const typingIndicator = document.querySelector('[data-placeholder="typing-indicator"]');
        if (!typingIndicator) {
            console.warn('Typing indicator not found');
            return;
        }

        // Create the actual message element
        const assistantBubble = document.createElement('div');
        assistantBubble.innerHTML = responseHtml;
        assistantBubble.className = 'animate-message-assistant';

        // Replace typing indicator with actual message
        typingIndicator.parentNode.replaceChild(assistantBubble, typingIndicator);

        // Remove the void marker from user message
        const userMessage = document.querySelector('[data-placeholder="user-message"]');
        if (userMessage) {
            userMessage.removeAttribute('data-void');
            userMessage.removeAttribute('data-placeholder');
        }

        // Scroll to show new message
        this.scrollToBottom();
    },

    /**
     * Remove all placeholders (on error or cancel)
     */
    clearPlaceholders() {
        const loadingPanel = document.getElementById(this.loadingPanelId);
        if (loadingPanel) {
            loadingPanel.innerHTML = '';
        }
    },

    /**
     * Create user bubble HTML
     */
    createUserBubbleHtml(message, avatarHtml) {
        const showTimestamp = true; // Could be from settings
        const timestamp = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

        return `
            <div class="group">
                <div class="flex justify-end items-end">
                    <div class="group px-4 py-3 rounded-2xl rounded-tr-md max-w-xl bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 text-white shadow-md animate-new-message-highlight">
                        <div class="whitespace-pre-wrap">${this.escapeHtml(message)}</div>
                        ${showTimestamp ? `<div class="text-xs opacity-60 mt-2">${timestamp}</div>` : ''}
                    </div>
                    ${avatarHtml ? `<div class="ml-3 flex-shrink-0">${avatarHtml}</div>` : ''}
                </div>
            </div>
        `;
    },

    /**
     * Create typing indicator HTML
     */
    createTypingIndicatorHtml() {
        return `
            <div class="flex items-start animate-fade-in">
                <div class="ai-avatar-animated relative mr-3 flex-shrink-0 self-start">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-violet-500 via-purple-500 to-fuchsia-500 flex items-center justify-center shadow-lg">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                    </div>
                </div>
                <div class="px-5 py-4 rounded-2xl rounded-tl-md bg-white border border-gray-100 shadow-sm">
                    <div class="ai-typing-dots flex items-center gap-1.5 h-6">
                        <span class="w-2.5 h-2.5 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 animate-typing-dot-1"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 animate-typing-dot-2"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500 animate-typing-dot-3"></span>
                    </div>
                    <div class="text-xs text-gray-400 mt-2">Thinking...</div>
                </div>
            </div>
        `;
    },

    /**
     * Scroll to bottom of chat
     */
    scrollToBottom() {
        const panel = document.getElementById(this.panelId);
        const wrapper = panel?.querySelector('.vlQueryWrapper');
        if (wrapper) {
            setTimeout(() => {
                wrapper.scrollTo({
                    top: wrapper.scrollHeight,
                    behavior: 'smooth'
                });
            }, 100);
        }
    },

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// Export for use
window.ChatMessageInjector = ChatMessageInjector;

/**
 * Helper functions for Kompo run() calls
 */
function precreateMessagePlaceholder(message, avatarHtml) {
    ChatMessageInjector.precreateMessagePlaceholder(message, avatarHtml || '');
}

function injectAssistantMessage(responseHtml) {
    ChatMessageInjector.injectAssistantMessage(responseHtml);
}

function clearMessagePlaceholders() {
    ChatMessageInjector.clearPlaceholders();
}
```

**Step 2: Update ChatMessageForm to use placeholder injection**

Modify `src/Kompo/ChatMessageForm.php` render() method. Update the send button onClick:

```php
_Button()->icon(_Sax('send-1', 20))->id('chat-send-btn')
    ->class('p-3 rounded-xl bg-gradient-to-r ' . $this->theme()->primaryGradient() . ' text-white shadow-lg hover:shadow-xl hover:scale-105 active:scale-95 transition-all flex-shrink-0')
    // Step 1: Create placeholder immediately (no server call)
    ->onClick(fn($e) => $e->run('() => {
        const input = document.getElementById("chat-message-input");
        const message = input ? input.value : "";
        if (message.trim()) {
            precreateMessagePlaceholder(message, "' . addslashes($this->settings()->showAvatars() ? $this->userAvatarHtml() : '') . '");
            input.value = "";
            input.style.height = "auto";
            input.dispatchEvent(new Event("input", { bubbles: true }));
        }
    }'))
    // Step 2: Send message to server
    ->onClick(fn($e) => $e->selfPost('sendMessage')->withAllFormValues()
        ->onSuccess->run('() => {
            // Refresh will inject the actual messages
        }')
        ->onError->run('() => {
            clearMessagePlaceholders();
        }')
        ->refresh(MessagesQuery::ID)
    ),
```

**Step 3: Update setLoadingMessage to work with injector**

The `setLoadingMessage` method can be simplified or removed since we're handling it client-side:

```php
public function setLoadingMessage()
{
    // Now handled by client-side ChatMessageInjector
    // This method is kept for backwards compatibility
    // but returns null to avoid duplicate rendering
    return null;
}
```

**Step 4: Add JavaScript to MessagesQuery**

Add script inclusion in MessagesQuery `top()` or `bottom()`:

```php
// In top() method, add:
_Script('
    // Include chat-message-injector.js content here
    // Or load from external file
')->id('chat-message-injector-script'),
```

Or register in service provider for asset publishing.

---

## Task 5: Optimize Refresh Behavior

**Files:**
- Modify: `src/Kompo/ChatMessageForm.php`
- Modify: `src/Kompo/MessagesQuery.php`

**Step 1: Implement selective refresh**

Instead of refreshing the entire MessagesQuery, refresh only the new message area.

Modify ChatMessageForm to target specific panel:

```php
// In sendMessage success handler
->onSuccess->run('() => {
    // Only refresh the temp-message-loading panel area
    // The actual messages are already in the database
}')
->inPanel('temp-message-loading')
```

**Step 2: Create getLatestMessages action**

Add to MessagesQuery.php:

```php
/**
 * Get only the latest messages (for partial refresh)
 */
public function getLatestMessages()
{
    $lastKnownId = request('last_message_id');

    $newMessages = $this->conversation?->messages()
        ->when($lastKnownId, fn($q) => $q->where('id', '>', $lastKnownId))
        ->orderBy('created_at')
        ->get();

    if ($newMessages->isEmpty()) {
        return null;
    }

    return _Rows(
        ...$newMessages->map(fn($msg) => $this->render($msg))->toArray()
    );
}
```

**Step 3: Update JavaScript to track last message ID**

Add to chat-message-injector.js:

```javascript
/**
 * Get the last message ID from the DOM
 */
getLastMessageId() {
    const messages = document.querySelectorAll('[data-message-id]');
    const lastMessage = messages[messages.length - 1];
    return lastMessage ? lastMessage.getAttribute('data-message-id') : null;
}
```

**Step 4: Add data-message-id to rendered messages**

Modify MessagesQuery render() method:

```php
public function render($message)
{
    $bubble = $message->role === 'user'
        ? $this->userBubble($message)
        : $this->assistantBubble($message);

    $isLatest = $message->id === $this->latestMessageId;

    return _Rows(
        $bubble,
        !$isLatest ? null : _Panel()->id('temp-message-loading')->class('mt-4'),
    )->attr(['data-message-id' => $message->id]); // ADD THIS
}
```

---

## Task 6: Add Settings for Animation Preferences

**Files:**
- Modify: `src/Contracts/ChatSettingsInterface.php`
- Modify: `src/Services/ChatSettings.php`
- Modify: `src/Kompo/Modals/ChatSettingsModal.php`

**Step 1: Add animation settings to interface**

Add to `src/Contracts/ChatSettingsInterface.php`:

```php
/**
 * Whether to enable message animations
 */
public function enableAnimations(): bool;

/**
 * Get animation speed: 'slow', 'normal', 'fast', 'none'
 */
public function animationSpeed(): string;

/**
 * Get the typing animation style
 * Options: 'dots', 'wave', 'pulse', 'brain'
 */
public function typingAnimationStyle(): string;
```

**Step 2: Implement in ChatSettings**

Add to `src/Services/ChatSettings.php`:

```php
public function enableAnimations(): bool
{
    return $this->get('enable_animations', true);
}

public function animationSpeed(): string
{
    return $this->get('animation_speed', 'normal');
}

public function typingAnimationStyle(): string
{
    return $this->get('typing_animation_style', 'dots');
}
```

**Step 3: Add to settings modal**

Add to `src/Kompo/Modals/ChatSettingsModal.php` in the render() method:

```php
// Animation Settings Group
_Rows(
    _Html(__('ai.settings.animations'))->class('font-semibold text-gray-700 mb-3'),

    _Toggle(__('ai.settings.enable-animations'))
        ->name('enable_animations')
        ->default($this->settings->enableAnimations()),

    _Select(__('ai.settings.animation-speed'))
        ->name('animation_speed')
        ->options([
            'slow' => __('ai.settings.speed-slow'),
            'normal' => __('ai.settings.speed-normal'),
            'fast' => __('ai.settings.speed-fast'),
            'none' => __('ai.settings.speed-none'),
        ])
        ->default($this->settings->animationSpeed()),

    _Select(__('ai.settings.typing-style'))
        ->name('typing_animation_style')
        ->options([
            'dots' => __('ai.settings.typing-dots'),
            'wave' => __('ai.settings.typing-wave'),
            'pulse' => __('ai.settings.typing-pulse'),
            'brain' => __('ai.settings.typing-brain'),
        ])
        ->default($this->settings->typingAnimationStyle()),
)->class('mb-6'),
```

**Step 4: Add translations**

Add to `resources/lang/en.json`:

```json
"ai.settings.animations": "Animations",
"ai.settings.enable-animations": "Enable animations",
"ai.settings.animation-speed": "Animation speed",
"ai.settings.speed-slow": "Slow",
"ai.settings.speed-normal": "Normal",
"ai.settings.speed-fast": "Fast",
"ai.settings.speed-none": "None",
"ai.settings.typing-style": "Typing indicator style",
"ai.settings.typing-dots": "Bouncing dots",
"ai.settings.typing-wave": "Sound wave",
"ai.settings.typing-pulse": "Pulse",
"ai.settings.typing-brain": "Thinking brain"
```

---

## Task 7: Add Animation Speed CSS Variables

**Files:**
- Modify: `resources/css/ai-chat.css`

**Step 1: Add CSS variables for animation speed**

Add to the `:root` section of `ai-chat.css`:

```css
/* Animation Speed Variables */
--ai-animation-duration-slow: 600ms;
--ai-animation-duration-normal: 300ms;
--ai-animation-duration-fast: 150ms;
```

**Step 2: Add speed modifier classes**

Add to the end of the animation section:

```css
/* Animation Speed Modifiers */
.animation-speed-slow {
    --ai-transition-fast: 300ms;
    --ai-transition-normal: 450ms;
    --ai-transition-slow: 600ms;
}

.animation-speed-normal {
    --ai-transition-fast: 150ms;
    --ai-transition-normal: 200ms;
    --ai-transition-slow: 300ms;
}

.animation-speed-fast {
    --ai-transition-fast: 75ms;
    --ai-transition-normal: 100ms;
    --ai-transition-slow: 150ms;
}

.animation-speed-none,
.animation-speed-none * {
    animation: none !important;
    transition: none !important;
}
```

**Step 3: Apply speed class to container**

Update MessagesQuery created() to add speed class:

```php
public function created()
{
    $this->id(self::ID);

    $animationSpeed = $this->settings()->animationSpeed();
    $speedClass = 'animation-speed-' . $animationSpeed;

    $this->conversation = AiConversation::where('user_id', auth()->id())
        ->find($this->prop('conversation_id') ?? session('selected_conversation_id'));

    $this->class = '!static flex-1 bg-gradient-to-b ' . $this->theme()->heroBackground() . ' ' . $speedClass;

    $this->latestMessageId = $this->query()?->reorder('created_at', 'desc')->first()?->id;
}
```

---

## Summary of Changes

| File | Change Type | Description |
|------|-------------|-------------|
| `src/Kompo/Components/TypingIndicator.php` | Create | New typing indicator component with 4 animation styles |
| `src/Kompo/ChatMessageForm.php` | Modify | Integrate typing indicator and placeholder injection |
| `src/Kompo/MessagesQuery.php` | Modify | Add entrance animations, isNewMessage helper, data-message-id |
| `resources/css/ai-chat.css` | Modify | Add all new animations and speed modifiers |
| `resources/js/chat-scroll.js` | Create | Reverse scroll pagination handler |
| `resources/js/chat-message-injector.js` | Create | Placeholder injection pattern for instant updates |
| `src/Contracts/ChatSettingsInterface.php` | Modify | Add animation settings methods |
| `src/Services/ChatSettings.php` | Modify | Implement animation settings |
| `src/Kompo/Modals/ChatSettingsModal.php` | Modify | Add animation preferences UI |
| `resources/lang/en.json` | Modify | Add animation-related translations |
| `resources/lang/fr.json` | Modify | Add French translations |

## Expected Impact

- **User Experience**: Smooth, professional animations that feel responsive
- **Performance**: No full page reloads - instant visual feedback
- **Scroll Behavior**: Natural chat flow - newest at bottom, scroll up for history
- **Customization**: Users can choose animation style and speed
- **Maintainability**: Follows existing Kompo patterns and auth package placeholder pattern

## Design Decisions

1. **Typing Indicator Styles**: Chose 4 distinct styles (dots, wave, pulse, brain) to cater to different preferences
2. **Placeholder Pattern**: Adopted from auth/roles-manager.js for proven DOM manipulation approach
3. **Animation Timing**: Used cubic-bezier for bouncy, playful feel while keeping professional
4. **Scroll Direction**: Kept message order (oldest first) but reversed scroll trigger direction
5. **Settings Integration**: Made all animations configurable to respect user preferences

---

**Plan complete and saved to `docs/plans/2026-01-03-chat-ux-animations-scroll.md`. Two execution options:**

**1. Subagent-Driven (this session)** - I dispatch fresh subagent per task, review between tasks, fast iteration

**2. Parallel Session (separate)** - Open new session with executing-plans, batch execution with checkpoints

**Which approach?**
