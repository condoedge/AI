/**
 * Chat Message Injector
 *
 * Implements the placeholder injection pattern (from auth/roles-manager.js)
 * to avoid full page reloads when adding new messages.
 *
 * Templates are injected by PHP with theme-aware classes:
 * - $USER_BUBBLE_TEMPLATE - User message bubble HTML
 * - $TYPING_INDICATOR_TEMPLATE - Typing indicator HTML
 * - $USER_AVATAR_HTML - User avatar HTML
 */

const ChatMessageInjector = {
    panelId: 'chat-messages-panel',
    loadingPanelId: 'temp-message-loading',

    // Templates injected by PHP (with theme classes)
    userBubbleTemplate: '$USER_BUBBLE_TEMPLATE',
    typingIndicatorTemplate: '$TYPING_INDICATOR_TEMPLATE',
    userAvatarHtml: '$USER_AVATAR_HTML',

    /**
     * Create a placeholder for a new user message + typing indicator
     * Called immediately when user clicks send
     */
    precreateMessagePlaceholder(userMessage, avatarHtml) {
        const loadingPanel = document.getElementById(this.loadingPanelId);
        if (!loadingPanel) {
            console.warn('Loading panel not found:', this.loadingPanelId);
            return;
        }

        // Create user message from template
        const timestamp = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        const avatar = avatarHtml || this.userAvatarHtml;

        let userBubbleHtml = this.userBubbleTemplate
            .replace('$MESSAGE', this.escapeHtml(userMessage))
            .replace('$TIMESTAMP', timestamp)
            .replace('$AVATAR', avatar ? `<div class="ml-3 flex-shrink-0">${avatar}</div>` : '');

        const userBubble = document.createElement('div');
        userBubble.setAttribute('data-placeholder', 'user-message');
        userBubble.setAttribute('data-void', 'true');
        userBubble.innerHTML = userBubbleHtml;

        // Create typing indicator from template
        const typingIndicator = document.createElement('div');
        typingIndicator.setAttribute('data-placeholder', 'typing-indicator');
        typingIndicator.setAttribute('data-void', 'true');
        typingIndicator.innerHTML = this.typingIndicatorTemplate;

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
