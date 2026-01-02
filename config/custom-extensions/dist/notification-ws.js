/**
 * SuiteCRM PowerPack Real-Time Notifications
 * WebSocket client for instant notification delivery
 *
 * This script connects to the WebSocket server and displays toast notifications
 * in real-time as they arrive, without waiting for the standard 1-minute polling.
 */
(function() {
    'use strict';

    // Prevent multiple initializations
    if (window.POWERPACK_NOTIFICATION_WS_INIT) {
        return;
    }
    window.POWERPACK_NOTIFICATION_WS_INIT = true;

    // Configuration
    const CONFIG = {
        // WebSocket URL - auto-detect from current host or use configured URL
        wsUrl: window.POWERPACK_WS_URL ||
               (window.location.protocol === 'https:' ? 'wss://' : 'ws://') +
               window.location.hostname + ':3001',
        tokenEndpoint: 'legacy/notification_token.php',
        reconnectDelay: 3000,
        maxReconnectDelay: 30000,
        toastDuration: 10000,
        maxToasts: 5
    };

    let ws = null;
    let reconnectAttempts = 0;
    let authToken = null;
    let isConnected = false;

    console.log('[NotifyWS] Initializing real-time notifications...');

    // =========================================================================
    // Toast Notification UI
    // =========================================================================

    /**
     * Create toast container if not exists
     */
    function getToastContainer() {
        let container = document.getElementById('powerpack-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'powerpack-toast-container';
            container.style.cssText = `
                position: fixed;
                top: 70px;
                right: 20px;
                z-index: 10000;
                display: flex;
                flex-direction: column;
                gap: 10px;
                max-width: 380px;
                pointer-events: none;
            `;
            document.body.appendChild(container);
        }
        return container;
    }

    /**
     * Get color for notification type
     */
    function getTypeColor(type) {
        const colors = {
            'info': '#3498db',
            'success': '#27ae60',
            'warning': '#f39c12',
            'error': '#e74c3c'
        };
        return colors[type] || colors.info;
    }

    /**
     * Get icon for notification type
     */
    function getTypeIcon(type) {
        const icons = {
            'info': 'info-circle',
            'success': 'check-circle',
            'warning': 'exclamation-triangle',
            'error': 'times-circle'
        };
        return icons[type] || icons.info;
    }

    /**
     * Show toast notification
     */
    function showToast(notification) {
        const container = getToastContainer();

        // Limit number of visible toasts
        const existingToasts = container.querySelectorAll('.powerpack-toast');
        if (existingToasts.length >= CONFIG.maxToasts) {
            existingToasts[0].remove();
        }

        const toast = document.createElement('div');
        toast.className = 'powerpack-toast';
        toast.style.cssText = `
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            padding: 16px;
            display: flex;
            gap: 12px;
            animation: ppSlideIn 0.3s ease;
            cursor: pointer;
            border-left: 4px solid ${getTypeColor(notification.type)};
            pointer-events: auto;
            max-width: 100%;
            transition: transform 0.2s ease, opacity 0.2s ease;
        `;

        toast.innerHTML = `
            <div style="color: ${getTypeColor(notification.type)}; font-size: 20px; line-height: 1;">
                <i class="fa fa-${getTypeIcon(notification.type)}"></i>
            </div>
            <div style="flex: 1; min-width: 0;">
                <div style="font-weight: 600; margin-bottom: 4px; color: #333; word-wrap: break-word;">
                    ${escapeHtml(notification.title || 'Notification')}
                </div>
                <div style="font-size: 13px; color: #666; word-wrap: break-word;">
                    ${escapeHtml(notification.message || '')}
                </div>
                ${notification.priority === 'urgent' ? '<div style="font-size: 11px; color: #e74c3c; margin-top: 4px; font-weight: 600;">URGENT</div>' : ''}
            </div>
            <button style="
                background: none;
                border: none;
                cursor: pointer;
                font-size: 18px;
                color: #999;
                padding: 0;
                line-height: 1;
                align-self: flex-start;
            ">&times;</button>
        `;

        // Click handler - navigate to related record
        toast.addEventListener('click', (e) => {
            if (e.target.tagName !== 'BUTTON') {
                navigateToNotification(notification);
                toast.remove();
                acknowledgeNotification(notification.id);
            }
        });

        // Close button handler
        toast.querySelector('button').addEventListener('click', (e) => {
            e.stopPropagation();
            removeToast(toast);
            acknowledgeNotification(notification.id);
        });

        // Hover effect
        toast.addEventListener('mouseenter', () => {
            toast.style.transform = 'translateX(-5px)';
        });
        toast.addEventListener('mouseleave', () => {
            toast.style.transform = 'translateX(0)';
        });

        container.appendChild(toast);

        // Play notification sound if available
        playNotificationSound(notification.priority);

        // Update badge count
        updateBadgeCount(1);

        // Auto-remove after duration
        setTimeout(() => {
            if (toast.parentElement) {
                removeToast(toast);
            }
        }, CONFIG.toastDuration);
    }

    /**
     * Remove toast with animation
     */
    function removeToast(toast) {
        toast.style.animation = 'ppSlideOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
    }

    /**
     * Navigate to notification target
     */
    function navigateToNotification(notification) {
        let url = null;

        if (notification.url_redirect) {
            url = notification.url_redirect;
        } else if (notification.target_module && notification.target_record) {
            url = `index.php?module=${notification.target_module}&action=DetailView&record=${notification.target_record}`;
        }

        if (url) {
            // Use SuiteCRM's navigation if available, otherwise direct navigation
            if (window.location.pathname.includes('/legacy/')) {
                window.location.href = url.startsWith('http') ? url : `/legacy/${url}`;
            } else {
                // In Angular frontend, navigate to legacy URL
                window.location.href = `/legacy/${url}`;
            }
        }
    }

    /**
     * Update notification badge count
     */
    function updateBadgeCount(increment) {
        // Find SuiteCRM notification badges (try multiple selectors)
        const selectors = [
            '.notification-badge',
            '.alert-badge',
            '[class*="notification"] .badge',
            '.navbar-nav .badge'
        ];

        for (const selector of selectors) {
            const badge = document.querySelector(selector);
            if (badge) {
                const current = parseInt(badge.textContent) || 0;
                const newCount = current + increment;
                badge.textContent = newCount;
                badge.style.display = newCount > 0 ? 'inline-block' : 'none';
                break;
            }
        }
    }

    /**
     * Play notification sound
     */
    function playNotificationSound(priority) {
        // Only play sound for high priority notifications
        if (priority !== 'high' && priority !== 'urgent') {
            return;
        }

        try {
            // Use Web Audio API for simple beep
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            oscillator.frequency.value = priority === 'urgent' ? 880 : 440;
            oscillator.type = 'sine';
            gainNode.gain.value = 0.1;

            oscillator.start();
            setTimeout(() => oscillator.stop(), 150);
        } catch (e) {
            // Audio not supported or blocked
        }
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // =========================================================================
    // WebSocket Connection
    // =========================================================================

    /**
     * Get authentication token from server
     */
    async function getAuthToken() {
        try {
            const response = await fetch(CONFIG.tokenEndpoint, {
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            if (data.success && data.token) {
                return data.token;
            }

            console.log('[NotifyWS] Token response:', data);
            return null;
        } catch (error) {
            console.error('[NotifyWS] Failed to get auth token:', error.message);
            return null;
        }
    }

    /**
     * Acknowledge notification receipt
     */
    function acknowledgeNotification(notificationId) {
        if (ws && ws.readyState === WebSocket.OPEN && notificationId) {
            ws.send(JSON.stringify({
                type: 'ack',
                notificationId: notificationId
            }));
        }
    }

    /**
     * Connect to WebSocket server
     */
    async function connect() {
        // Get auth token if not available
        if (!authToken) {
            authToken = await getAuthToken();
            if (!authToken) {
                console.log('[NotifyWS] No auth token available, will retry...');
                scheduleReconnect();
                return;
            }
        }

        try {
            console.log('[NotifyWS] Connecting to', CONFIG.wsUrl);
            ws = new WebSocket(CONFIG.wsUrl);

            ws.onopen = () => {
                console.log('[NotifyWS] Connected');
                reconnectAttempts = 0;
            };

            ws.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    handleMessage(data);
                } catch (error) {
                    console.error('[NotifyWS] Message parse error:', error);
                }
            };

            ws.onclose = (event) => {
                console.log('[NotifyWS] Disconnected:', event.code, event.reason);
                isConnected = false;

                // If auth failed, clear token
                if (event.code === 4001) {
                    authToken = null;
                }

                scheduleReconnect();
            };

            ws.onerror = (error) => {
                console.error('[NotifyWS] WebSocket error');
            };

        } catch (error) {
            console.error('[NotifyWS] Connection error:', error);
            scheduleReconnect();
        }
    }

    /**
     * Handle incoming WebSocket message
     */
    function handleMessage(data) {
        switch (data.type) {
            case 'auth_required':
                // Send authentication token
                ws.send(JSON.stringify({
                    type: 'auth',
                    token: authToken
                }));
                break;

            case 'auth_success':
                console.log('[NotifyWS] Authenticated as user:', data.userId);
                isConnected = true;
                break;

            case 'auth_failed':
                console.log('[NotifyWS] Authentication failed:', data.error);
                authToken = null;
                break;

            case 'notification':
                console.log('[NotifyWS] Received notification:', data.title);
                showToast(data);
                break;

            case 'pong':
                // Heartbeat response
                break;

            case 'error':
                console.error('[NotifyWS] Server error:', data.message);
                break;

            default:
                console.log('[NotifyWS] Unknown message type:', data.type);
        }
    }

    /**
     * Schedule reconnection with exponential backoff
     */
    function scheduleReconnect() {
        reconnectAttempts++;
        const delay = Math.min(
            CONFIG.reconnectDelay * Math.pow(1.5, reconnectAttempts - 1),
            CONFIG.maxReconnectDelay
        );

        console.log(`[NotifyWS] Reconnecting in ${Math.round(delay/1000)}s... (attempt ${reconnectAttempts})`);
        setTimeout(connect, delay);
    }

    // =========================================================================
    // Initialization
    // =========================================================================

    // Inject CSS animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes ppSlideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        @keyframes ppSlideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(style);

    // Start connection after page loads
    function init() {
        // Delay initial connection to let page fully load
        setTimeout(connect, 2000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for debugging
    window.PowerPackNotifications = {
        connect: connect,
        isConnected: () => isConnected,
        showToast: showToast
    };

})();
