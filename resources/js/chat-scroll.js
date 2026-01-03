/**
 * Chat Scroll Manager
 *
 * Handles reverse scroll pagination for chat messages.
 * - Loads older messages when scrolling UP (to top)
 * - Maintains scroll position when new messages are loaded
 * - Smooth scroll to bottom for new messages
 */
class ChatScrollManager {
    static SCROLL_THRESHOLD = 100;

    constructor(containerId) {
        this.containerId = containerId;
        this.container = null;
        this.boundHandleScroll = null;
        this.isLoadingMore = false;
        this.previousScrollHeight = 0;
        this.init();
    }

    init() {
        this.waitForContainer().then(container => {
            this.container = container;
            this.setupScrollListener();
            this.scrollToBottom(false);
        }).catch(err => {
            console.warn('ChatScrollManager:', err.message);
        });
    }

    waitForContainer(maxRetries = 50) {
        return new Promise((resolve, reject) => {
            let retries = 0;
            const check = () => {
                const panel = document.getElementById(this.containerId);
                const wrapper = panel?.querySelector('.vlQueryWrapper');
                if (wrapper) {
                    resolve(wrapper);
                } else if (retries >= maxRetries) {
                    reject(new Error('Chat container not found after ' + maxRetries + ' retries'));
                } else {
                    retries++;
                    setTimeout(check, 100);
                }
            };
            check();
        });
    }

    setupScrollListener() {
        this.boundHandleScroll = this.handleScroll.bind(this);
        this.container.addEventListener('scroll', this.boundHandleScroll);
    }

    handleScroll() {
        if (this.container.scrollTop < ChatScrollManager.SCROLL_THRESHOLD && !this.isLoadingMore) {
            this.loadOlderMessages();
        }
    }

    loadOlderMessages() {
        this.previousScrollHeight = this.container.scrollHeight;
        this.isLoadingMore = true;

        const event = new CustomEvent('chat-load-older', {
            detail: { containerId: this.containerId }
        });
        document.dispatchEvent(event);
    }

    onMessagesLoaded() {
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

    addNewMessage(messageHtml, isUser = false) {
        const placeholder = document.createElement('div');
        placeholder.setAttribute('data-new-message', 'true');
        placeholder.innerHTML = messageHtml;

        const loadingPanel = document.getElementById('temp-message-loading');
        if (loadingPanel) {
            loadingPanel.parentNode.insertBefore(placeholder, loadingPanel);
        } else {
            this.container.appendChild(placeholder);
        }

        this.scrollToBottom(true);
    }

    destroy() {
        if (this.container && this.boundHandleScroll) {
            this.container.removeEventListener('scroll', this.boundHandleScroll);
        }
        this.container = null;
        this.boundHandleScroll = null;
        this.isLoadingMore = false;
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
        // Cleanup existing instance
        if (window.chatScrollManager) {
            window.chatScrollManager.destroy();
        }
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
