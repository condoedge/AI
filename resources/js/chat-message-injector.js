/**
 * Chat Message Injector
 *
 * Implements the placeholder injection pattern (from auth/roles-manager.js)
 * to avoid full page reloads when adding new messages.
 *
 * Two-panel architecture:
 * - Staging panel (hidden, in bottom()) - receives raw HTML from server via inPanel()
 * - Display panel (JS-created, inside vlQueryWrapper) - shows placeholders and messages
 *
 * Templates are injected by PHP with theme-aware classes:
 * - $USER_BUBBLE_TEMPLATE - User message bubble HTML
 * - $TYPING_INDICATOR_TEMPLATE - Typing indicator HTML
 * - $USER_AVATAR_HTML - User avatar HTML
 */

const ChatMessageInjector = {
    panelId: 'chat-messages-panel',
    stagingPanelId: 'temp-message-staging',
    displayPanelId: 'temp-message-display',

    // Templates injected by PHP (with theme classes)
    // Using backticks to avoid quote escaping issues with SVG attributes
    userBubbleTemplate: `$USER_BUBBLE_TEMPLATE`,
    typingIndicatorTemplate: `$TYPING_INDICATOR_TEMPLATE`,
    userAvatarHtml: `$USER_AVATAR_HTML`,

    /**
     * Ensure display panel exists inside vlQueryWrapper
     * Creates it at the end of the items container if not present
     */
    ensureDisplayPanel() {
        let displayPanel = document.getElementById(this.displayPanelId);
        if (displayPanel) {
            return displayPanel;
        }

        const panel = document.getElementById(this.panelId);
        if (!panel) {
            console.warn('Chat panel not found:', this.panelId);
            return null;
        }

        // Find the items container inside vlQueryWrapper
        const wrapper = panel.querySelector('.vlQueryWrapper');
        const itemsContainer = wrapper?.querySelector(':scope > div');

        if (!itemsContainer) {
            console.warn('Items container not found inside vlQueryWrapper');
            return null;
        }

        // Create display panel at the end of items container
        displayPanel = document.createElement('div');
        displayPanel.id = this.displayPanelId;
        displayPanel.className = 'mt-4';
        itemsContainer.appendChild(displayPanel);

        return displayPanel;
    },

    /**
     * Create a placeholder for a new user message + typing indicator
     * Called immediately when user clicks send
     */
    precreateMessagePlaceholder(userMessage, avatarHtml) {
        const displayPanel = this.ensureDisplayPanel();
        if (!displayPanel) {
            console.warn('Could not create display panel');
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

        // Insert into display panel
        displayPanel.innerHTML = '';
        displayPanel.appendChild(userBubble);
        displayPanel.appendChild(typingIndicator);

        // Scroll to bottom
        this.scrollToBottom();
    },

    /**
     * Process server response from staging panel
     * Replaces typing indicator's inner content (dots) with assistant message
     */
    processServerResponse() {
        const stagingPanel = document.getElementById(this.stagingPanelId);
        if (!stagingPanel || !stagingPanel.innerHTML.trim()) {
            console.warn('Staging panel empty or not found');
            return;
        }

        // Get the response HTML from staging
        const responseHtml = stagingPanel.innerHTML;

        // Find the typing indicator content by ID and replace it
        const contentArea = document.getElementById('typing-indicator-content');
        if (contentArea) {
            contentArea.innerHTML = responseHtml;
        }

        // Remove placeholder markers
        const typingIndicator = document.querySelector('[data-placeholder="typing-indicator"]');
        if (typingIndicator) {
            typingIndicator.removeAttribute('data-placeholder');
            typingIndicator.removeAttribute('data-void');
        }

        // Remove void marker from user message placeholder (it's now permanent)
        const userPlaceholder = document.querySelector('[data-placeholder="user-message"]');
        if (userPlaceholder) {
            userPlaceholder.removeAttribute('data-void');
            userPlaceholder.removeAttribute('data-placeholder');
        }

        // Clear staging panel
        stagingPanel.innerHTML = '';

        // Scroll to show new message
        this.scrollToBottom();
    },

    /**
     * Add a new message before the display panel
     * @param {string} messageHtml - The HTML content to insert
     */
    addNewMessage(messageHtml) {
        const displayPanel = this.ensureDisplayPanel();
        if (!displayPanel) {
            return;
        }

        const placeholder = document.createElement('div');
        placeholder.setAttribute('data-new-message', 'true');
        placeholder.innerHTML = messageHtml;

        // Insert before the display panel
        displayPanel.parentNode.insertBefore(placeholder, displayPanel);

        this.scrollToBottom();
    },

    /**
     * Inject the actual assistant response, replacing the typing indicator
     * @deprecated Use processServerResponse() instead
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
        const displayPanel = document.getElementById(this.displayPanelId);
        if (displayPanel) {
            displayPanel.innerHTML = '';
        }

        const stagingPanel = document.getElementById(this.stagingPanelId);
        if (stagingPanel) {
            stagingPanel.innerHTML = '';
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

function processServerResponse() {
    ChatMessageInjector.processServerResponse();
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

window.precreateMessagePlaceholder = precreateMessagePlaceholder;
window.processServerResponse = processServerResponse;
window.injectAssistantMessage = injectAssistantMessage;
window.clearMessagePlaceholders = clearMessagePlaceholders;
window.scrollChatToBottom = scrollChatToBottom;
