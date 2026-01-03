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
        const timestamp = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

        return `
            <div class="group">
                <div class="flex justify-end items-end">
                    <div class="group px-4 py-3 rounded-2xl rounded-tr-md max-w-xl bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 text-white shadow-md animate-new-message-highlight">
                        <div class="whitespace-pre-wrap">${this.escapeHtml(message)}</div>
                        <div class="text-xs opacity-60 mt-2">${timestamp}</div>
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
            <div class="flex items-start animate-fade-in mt-4">
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
    },

    /**
     * Get the last message ID from the DOM
     */
    getLastMessageId() {
        const messages = document.querySelectorAll('[data-message-id]');
        if (messages.length === 0) return null;
        const lastMessage = messages[messages.length - 1];
        return lastMessage ? lastMessage.getAttribute('data-message-id') : null;
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

function scrollChatToBottom() {
    ChatMessageInjector.scrollToBottom();
}
