/**
 * Chat Scroll Manager
 *
 * Scrolls the chat messages container to the bottom on load and after refresh.
 *
 * Older-message pagination is handled by Kompo's built-in Scroll pagination
 * (MessagesQuery: paginationType='Scroll', topPagination=true) — this manager only
 * owns the "snap to newest message" behavior.
 */
class ChatScrollManager {
    constructor(containerId) {
        this.containerId = containerId;
        this.container = null;
        this.init();
    }

    init() {
        this.waitForContainer().then(container => {
            this.container = container;
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

    scrollToBottom(smooth = true) {
        if (!this.container) return;

        setTimeout(() => {
            this.container.scrollTo({
                top: this.container.scrollHeight,
                behavior: smooth ? 'smooth' : 'auto'
            });
        }, 100);
    }

    destroy() {
        this.container = null;
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
