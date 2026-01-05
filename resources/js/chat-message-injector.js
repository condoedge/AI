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
    dotColor: `$DOT_COLOR`,

    // Typing animation settings (injected by PHP from user settings)
    typingStyle: `$TYPING_STYLE`,
    dotsAnimationHtml: `$DOTS_ANIMATION_HTML`,
    waveAnimationHtml: `$WAVE_ANIMATION_HTML`,
    pulseAnimationHtml: `$PULSE_ANIMATION_HTML`,
    brainAnimationHtml: `$BRAIN_ANIMATION_HTML`,

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
        displayPanel.className = 'mt-4 space-y-4'; // mt-4 from top, space-y-4 between messages
        itemsContainer.prepend(displayPanel);

        return displayPanel;
    },

    /**
     * Create a placeholder for a new user message + typing indicator
     * Called immediately when user clicks send
     */
    precreateMessagePlaceholder(userMessage, avatarHtml) {
        // Hide welcome/empty state content when sending first message
        const welcomeState = document.getElementById('chat-welcome-state');
        const emptyState = document.getElementById('chat-empty-state');
        if (welcomeState) welcomeState.style.display = 'none';
        if (emptyState) emptyState.style.display = 'none';

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

        // Remove any existing typing indicator (but keep previous messages)
        const existingTyping = displayPanel.querySelector('[data-placeholder="typing-indicator"]');
        if (existingTyping) {
            existingTyping.remove();
        }

        // Append new user bubble and typing indicator (preserve previous messages)
        displayPanel.appendChild(userBubble);
        displayPanel.appendChild(typingIndicator);

        // Scroll to bottom
        this.scrollToBottom();
    },

    /**
     * Process server response from staging panel
     * - Moves content + action bar to typing indicator
     * - Moves proxies into message elements for persistence
     * - Wires visible buttons to proxy buttons
     */
    processServerResponse() {
        const stagingPanel = document.getElementById(this.stagingPanelId);
        if (!stagingPanel || !stagingPanel.innerHTML.trim()) {
            console.warn('Staging panel empty or not found');
            return;
        }

        // 1. Get content and action bar from staging
        const content = stagingPanel.querySelector('.chat-staged-content')?.outerHTML || '';
        const actionBar = stagingPanel.querySelector('.chat-staged-action-bar')?.outerHTML || '';
        const combinedHtml = content + actionBar;

        // 2. Find the LATEST typing indicator and inject content
        const contentAreas = document.querySelectorAll('.typing-indicator-content');
        const contentArea = contentAreas.length > 0 ? contentAreas[contentAreas.length - 1] : null;
        if (contentArea) {
            contentArea.innerHTML = combinedHtml;
            contentArea.classList.remove('typing-indicator-content');
        }

        // 3. Get message IDs from staging
        const userMessageId = stagingPanel.querySelector('[data-user-message-id]')?.getAttribute('data-user-message-id');
        const assistantMessageId = stagingPanel.querySelector('[data-assistant-message-id]')?.getAttribute('data-assistant-message-id');

        // 4. Handle typing indicator element (becomes assistant message)
        const typingIndicators = document.querySelectorAll('[data-placeholder="typing-indicator"]');
        const typingIndicator = typingIndicators.length > 0 ? typingIndicators[typingIndicators.length - 1] : null;
        if (typingIndicator) {
            typingIndicator.removeAttribute('data-placeholder');
            typingIndicator.removeAttribute('data-void');

            if (assistantMessageId) {
                typingIndicator.setAttribute('data-message-id', assistantMessageId);
            }

            // Move assistant proxies INTO this element (preserves Kompo bindings)
            const assistantProxies = stagingPanel.querySelector('.chat-staged-assistant-proxies');
            if (assistantProxies) {
                typingIndicator.appendChild(assistantProxies);
            }

            // Wire visible action buttons to proxies within same element
            this.wireActionButtons(typingIndicator);
        }

        // 5. Handle user message (placeholder OR existing message after edit)
        let userMessage = null;

        // First try to find a placeholder (new message flow)
        const userPlaceholders = document.querySelectorAll('[data-placeholder="user-message"]');
        if (userPlaceholders.length > 0) {
            userMessage = userPlaceholders[userPlaceholders.length - 1];
            userMessage.removeAttribute('data-void');
            userMessage.removeAttribute('data-placeholder');
            if (userMessageId) {
                userMessage.setAttribute('data-message-id', userMessageId);
            }
        } else if (userMessageId) {
            // Edit flow: user message already exists with data-message-id
            userMessage = document.querySelector(`[data-message-id="${userMessageId}"]`);
        }

        if (userMessage) {
            // Move user edit proxy INTO user message element
            const editProxy = stagingPanel.querySelector('.js-action-edit-proxy');
            if (editProxy) {
                // Remove any existing proxy container first (for re-edit scenarios)
                const existingContainer = userMessage.querySelector('.js-proxy-container');
                if (existingContainer) {
                    existingContainer.remove();
                }

                const proxyContainer = document.createElement('div');
                proxyContainer.className = 'js-proxy-container hidden';
                proxyContainer.appendChild(editProxy);
                userMessage.appendChild(proxyContainer);
            }

            // Wire edit button to proxy within same element
            this.wireActionButtons(userMessage);
        }

        // 6. Clear staging (proxies have been moved out)
        stagingPanel.innerHTML = '';

        // 7. Scroll to show new message
        this.scrollToBottom();
    },

    /**
     * Wire visible action buttons to their proxy counterparts within the same element.
     * Uses class naming convention: js-action-X → js-action-X-proxy
     */
    wireActionButtons(messageElement) {
        if (!messageElement) return;

        // Find all visible action buttons (have js-action-* but not *-proxy)
        const visibleButtons = messageElement.querySelectorAll('[class*="js-action-"]:not([class*="-proxy"])');

        visibleButtons.forEach(btn => {
            // Extract action class (e.g., "js-action-copy" → look for "js-action-copy-proxy")
            const actionClass = [...btn.classList].find(c => /^js-action-[\w-]+$/.test(c) && !c.includes('-proxy'));
            if (!actionClass) return;

            const proxyClass = actionClass + '-proxy';
            const proxy = messageElement.querySelector('.' + proxyClass);

            if (proxy) {
                btn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    // Handle feedback button color persistence
                    if (actionClass === 'js-action-feedback-pos') {
                        this.setFeedbackState(messageElement, 'positive');
                    } else if (actionClass === 'js-action-feedback-neg') {
                        this.setFeedbackState(messageElement, 'negative');
                    }

                    // Handle regenerate loading indicator
                    if (actionClass === 'js-action-regenerate') {
                        this.showRegeneratingIndicator(messageElement);
                    }

                    // Trigger the proxy click
                    proxy.click();
                };
            }
        });

        // Also handle legacy .js-edit-message class (from user bubble template)
        messageElement.querySelectorAll('.js-edit-message').forEach(btn => {
            const proxy = messageElement.querySelector('.js-action-edit-proxy');
            if (proxy) {
                btn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    proxy.click();
                };
            }
        });
    },

    /**
     * Show regenerating indicator on assistant message
     * Uses the configured typing animation style (dots, wave, pulse, brain)
     */
    showRegeneratingIndicator(messageElement) {
        const content = messageElement.querySelector('.prose');
        if (content) {
            const animationHtml = this.getTypingAnimationHtml();
            content.innerHTML = `
                ${animationHtml}
                <div class="text-xs text-gray-400 mt-2">Regenerating...</div>
            `;
        }
    },

    /**
     * Get the appropriate typing animation HTML based on user's style preference
     */
    getTypingAnimationHtml() {
        switch(this.typingStyle) {
            case 'wave':
                return this.waveAnimationHtml;
            case 'pulse':
                return this.pulseAnimationHtml;
            case 'brain':
                return this.brainAnimationHtml;
            default:
                return this.dotsAnimationHtml;
        }
    },

    /**
     * Set feedback button visual state (positive/negative)
     * Matches the PHP-rendered classes from MessagesQuery
     */
    setFeedbackState(messageElement, type) {
        const posBtn = messageElement.querySelector('.js-action-feedback-pos');
        const negBtn = messageElement.querySelector('.js-action-feedback-neg');

        // Reset both buttons to inactive state
        const inactiveClasses = ['hover:bg-emerald-50', 'hover:bg-red-50', 'text-gray-400', 'hover:text-emerald-600', 'hover:text-red-600'];
        const activePositiveClasses = ['bg-emerald-100', 'text-emerald-600'];
        const activeNegativeClasses = ['bg-red-100', 'text-red-600'];

        if (posBtn) {
            // Remove active classes
            posBtn.classList.remove(...activePositiveClasses, ...activeNegativeClasses);
            if (type === 'positive') {
                // Apply active state
                posBtn.classList.remove(...inactiveClasses);
                posBtn.classList.add(...activePositiveClasses);
            } else {
                // Reset to inactive
                posBtn.classList.add('text-gray-400', 'hover:bg-emerald-50', 'hover:text-emerald-600');
            }
        }

        if (negBtn) {
            // Remove active classes
            negBtn.classList.remove(...activePositiveClasses, ...activeNegativeClasses);
            if (type === 'negative') {
                // Apply active state
                negBtn.classList.remove(...inactiveClasses);
                negBtn.classList.add(...activeNegativeClasses);
            } else {
                // Reset to inactive
                negBtn.classList.add('text-gray-400', 'hover:bg-red-50', 'hover:text-red-600');
            }
        }
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
     * Update the content of a user message in place with pulse animation
     * @param {string|number} messageId - The message ID to update
     * @param {string} newContent - The new message content
     */
    updateMessageContent(messageId, newContent) {
        const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!messageEl) {
            console.warn('Message not found for update:', messageId);
            return;
        }

        // Find the content area (whitespace-pre-wrap element)
        const contentEl = messageEl.querySelector('.whitespace-pre-wrap');
        if (!contentEl) {
            console.warn('Content element not found in message:', messageId);
            return;
        }

        // Update the content
        contentEl.textContent = newContent;

        // Add pulse animation
        messageEl.style.transition = 'transform 0.15s ease-out, box-shadow 0.15s ease-out';
        messageEl.style.transform = 'scale(1.02)';
        messageEl.style.boxShadow = '0 0 0 2px rgba(59, 130, 246, 0.5)';

        setTimeout(() => {
            messageEl.style.transform = 'scale(1)';
            messageEl.style.boxShadow = 'none';
        }, 150);

        setTimeout(() => {
            messageEl.style.transition = '';
            messageEl.style.transform = '';
            messageEl.style.boxShadow = '';
        }, 300);
    },

    /**
     * Remove all messages AFTER the given message ID with fade-out animation
     * Due to flex-col-reverse, messages BEFORE in DOM are AFTER chronologically
     * @param {string|number} messageId - The message ID to remove messages after
     * @returns {Promise} - Resolves when all animations are complete
     */
    removeMessagesAfter(messageId) {
        return new Promise((resolve) => {
            const targetId = parseInt(messageId);
            if (isNaN(targetId)) {
                console.warn('Invalid message ID:', messageId);
                resolve();
                return;
            }

            const messagesToRemove = [];

            // 1. Find all messages with ID > targetId (chronologically after)
            // This works for both PHP-rendered AND JS-injected messages
            const allMessages = document.querySelectorAll('[data-message-id]');
            allMessages.forEach(el => {
                const elId = parseInt(el.getAttribute('data-message-id'));
                if (!isNaN(elId) && elId > targetId) {
                    messagesToRemove.push(el);
                }
            });

            // 2. Remove any placeholders (typing indicators, void messages)
            document.querySelectorAll('[data-placeholder]').forEach(el => {
                if (!messagesToRemove.includes(el)) {
                    messagesToRemove.push(el);
                }
            });

            // 3. Remove void markers (uncommitted messages without ID)
            document.querySelectorAll('[data-void="true"]').forEach(el => {
                if (!messagesToRemove.includes(el)) {
                    messagesToRemove.push(el);
                }
            });

            if (messagesToRemove.length === 0) {
                resolve();
                return;
            }

            // Apply staggered fade-out animation
            messagesToRemove.forEach((msg, index) => {
                msg.style.transition = `opacity 0.3s ease-out ${index * 0.05}s, transform 0.3s ease-out ${index * 0.05}s`;
                msg.style.opacity = '0';
                msg.style.transform = 'translateY(-10px)';
            });

            // Remove elements after animation completes
            const totalDuration = 300 + (messagesToRemove.length - 1) * 50;
            setTimeout(() => {
                messagesToRemove.forEach(msg => msg.remove());
                resolve();
            }, totalDuration);
        });
    },

    /**
     * Show typing indicator after a specific message
     * Handles both flex-col-reverse (PHP) and regular flow (JS display panel)
     * @param {string|number} messageId - The message ID to show typing after
     */
    showTypingAfterMessage(messageId) {
        const targetMessage = document.querySelector(`[data-message-id="${messageId}"]`);
        if (!targetMessage) {
            console.warn('Target message not found for typing indicator:', messageId);
            return;
        }

        // Remove any existing typing indicator
        const existingTyping = document.querySelector('[data-placeholder="typing-indicator"]');
        if (existingTyping) {
            existingTyping.remove();
        }

        // Create typing indicator from template
        const typingIndicator = document.createElement('div');
        typingIndicator.setAttribute('data-placeholder', 'typing-indicator');
        typingIndicator.setAttribute('data-void', 'true');
        typingIndicator.innerHTML = this.typingIndicatorTemplate;

        // Check if target is in display panel (regular flow) vs main container (flex-col-reverse)
        const displayPanel = document.getElementById(this.displayPanelId);
        const isInDisplayPanel = displayPanel && displayPanel.contains(targetMessage);

        if (isInDisplayPanel) {
            // Display panel uses regular top-to-bottom flow: insert AFTER in DOM
            targetMessage.parentNode.insertBefore(typingIndicator, targetMessage.nextSibling);
        } else {
            // Main container uses flex-col-reverse: insert BEFORE in DOM to appear AFTER visually
            targetMessage.parentNode.insertBefore(typingIndicator, targetMessage);
        }

        // Add fade-in animation
        typingIndicator.style.opacity = '0';
        typingIndicator.style.transform = 'translateY(10px)';
        typingIndicator.style.transition = 'opacity 0.2s ease-out, transform 0.2s ease-out';

        // Trigger animation
        requestAnimationFrame(() => {
            typingIndicator.style.opacity = '1';
            typingIndicator.style.transform = 'translateY(0)';
        });

        this.scrollToBottom();
    },

    /**
     * Remove a message AND all messages after it with fade-out animation
     * Used for delete operations
     * @param {string|number} messageId - The message ID to remove (and all after)
     * @returns {Promise} - Resolves when all animations are complete
     */
    removeMessageAndAfter(messageId) {
        return new Promise((resolve) => {
            const targetId = parseInt(messageId);
            if (isNaN(targetId)) {
                console.warn('Invalid message ID:', messageId);
                resolve();
                return;
            }

            const messagesToRemove = [];

            // 1. Find all messages with ID >= targetId (this message and all after)
            // This works for both PHP-rendered AND JS-injected messages
            const allMessages = document.querySelectorAll('[data-message-id]');
            allMessages.forEach(el => {
                const elId = parseInt(el.getAttribute('data-message-id'));
                if (!isNaN(elId) && elId >= targetId) {
                    messagesToRemove.push(el);
                }
            });

            // 2. Remove any placeholders (typing indicators, void messages)
            document.querySelectorAll('[data-placeholder]').forEach(el => {
                if (!messagesToRemove.includes(el)) {
                    messagesToRemove.push(el);
                }
            });

            // 3. Remove void markers (uncommitted messages without ID)
            document.querySelectorAll('[data-void="true"]').forEach(el => {
                if (!messagesToRemove.includes(el)) {
                    messagesToRemove.push(el);
                }
            });

            if (messagesToRemove.length === 0) {
                resolve();
                return;
            }

            // Apply staggered fade-out animation
            messagesToRemove.forEach((msg, index) => {
                msg.style.transition = `opacity 0.3s ease-out ${index * 0.05}s, transform 0.3s ease-out ${index * 0.05}s`;
                msg.style.opacity = '0';
                msg.style.transform = 'translateY(-10px)';
            });

            // Remove elements after animation completes
            const totalDuration = 300 + (messagesToRemove.length - 1) * 50;
            setTimeout(() => {
                messagesToRemove.forEach(msg => msg.remove());
                resolve();
            }, totalDuration);
        });
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

/**
 * Helper functions for edit/delete operations
 */
function updateMessageContent(messageId, newContent) {
    ChatMessageInjector.updateMessageContent(messageId, newContent);
}

function removeMessagesAfter(messageId) {
    return ChatMessageInjector.removeMessagesAfter(messageId);
}

function showTypingAfterMessage(messageId) {
    ChatMessageInjector.showTypingAfterMessage(messageId);
}

function removeMessageAndAfter(messageId) {
    return ChatMessageInjector.removeMessageAndAfter(messageId);
}

window.updateMessageContent = updateMessageContent;
window.removeMessagesAfter = removeMessagesAfter;
window.showTypingAfterMessage = showTypingAfterMessage;
window.removeMessageAndAfter = removeMessageAndAfter;
