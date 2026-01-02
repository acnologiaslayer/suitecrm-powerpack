/**
 * LeadJourney Buttons for SuiteCRM 8 Angular Frontend
 * Injects Timeline, Recordings buttons; Messenger-style compose bar in timeline modal
 * v2.3.5 - Fix SMS filter to include inbound_sms type naming
 */
(function() {
    'use strict';

    const BUTTON_CONTAINER_ID = 'leadjourney-action-buttons';
    const MODAL_ID = 'leadjourney-modal';
    const CHECK_INTERVAL = 500;
    const MAX_RETRIES = 60;

    let lastUrl = '';
    let retryCount = 0;
    let currentViewMode = 'flat'; // 'flat' or 'threaded'
    let currentFilter = 'all';
    let cachedTimelineData = null;
    let cachedRecordData = null; // Cache lead/contact data for communication actions
    let currentModule = null;    // Current module for modal actions
    let currentRecordId = null;  // Current record ID for modal actions

    // Type categories for filtering (includes legacy types and both naming conventions)
    // Note: verbacall_signup_sent and verbacall_payment_email_sent are also emails
    // Supports both direction_type (call_inbound) and type_direction (inbound_sms) naming
    const TYPE_CATEGORIES = {
        all: { label: 'All', types: null },
        calls: { label: 'Calls', types: ['call_outbound', 'call_inbound', 'outbound_call', 'inbound_call', 'call', 'voicemail'] },
        sms: { label: 'SMS', types: ['sms_outbound', 'sms_inbound', 'outbound_sms', 'inbound_sms', 'sms'] },
        emails: { label: 'Emails', types: ['email_outbound', 'email_inbound', 'outbound_email', 'inbound_email', 'email', 'verbacall_signup_sent', 'verbacall_payment_email_sent'] },
        verbacall: { label: 'Verbacall', types: ['verbacall_signup_sent', 'verbacall_discount_offer', 'verbacall_payment_email_sent'] },
        other: { label: 'Other', types: ['note', 'meeting', 'task'] }
    };

    // Communication URLs
    const COMM_CONFIG = {
        callUrl: 'legacy/index.php?module=TwilioIntegration&action=makecall&phone=',
        smsUrl: 'legacy/index.php?module=TwilioIntegration&action=sendsms&phone=',
        emailComposeUrl: '#/emails/compose'
    };

    /**
     * Fetch lead/contact record data for communication actions
     */
    async function fetchRecordData(module, recordId) {
        if (cachedRecordData && cachedRecordData.id === recordId) {
            return cachedRecordData;
        }

        try {
            // Use SuiteCRM's GraphQL API to fetch record data
            const moduleName = module.toLowerCase();
            const response = await fetch(`api/graphql`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    query: `
                        query GetRecord($module: String!, $id: ID!) {
                            getRecord(module: $module, id: $id) {
                                id
                                attributes
                            }
                        }
                    `,
                    variables: {
                        module: moduleName,
                        id: recordId
                    }
                })
            });

            const data = await response.json();
            if (data.data && data.data.getRecord) {
                const attrs = data.data.getRecord.attributes || {};
                cachedRecordData = {
                    id: recordId,
                    module: module,
                    firstName: attrs.first_name || '',
                    lastName: attrs.last_name || '',
                    fullName: `${attrs.first_name || ''} ${attrs.last_name || ''}`.trim(),
                    phone: attrs.phone_mobile || attrs.phone_work || attrs.phone_home || '',
                    email: attrs.email1 || ''
                };
                return cachedRecordData;
            }
        } catch (error) {
            console.error('[LeadJourney] Error fetching record data:', error);
        }

        // Fallback: try to extract from page
        return extractRecordDataFromPage(module, recordId);
    }

    /**
     * Extract record data from the current page DOM
     */
    function extractRecordDataFromPage(module, recordId) {
        const data = {
            id: recordId,
            module: module,
            firstName: '',
            lastName: '',
            fullName: '',
            phone: '',
            email: ''
        };

        // Try to find phone number from tel: links
        const telLink = document.querySelector('a[href^="tel:"]');
        if (telLink) {
            data.phone = telLink.href.replace('tel:', '').trim();
        }

        // Try to find email from mailto: links
        const mailtoLink = document.querySelector('a[href^="mailto:"]');
        if (mailtoLink) {
            data.email = mailtoLink.href.replace('mailto:', '').trim();
        }

        // Try to find name from page title or header
        const titleEl = document.querySelector('.record-view-title, scrm-record-header h1, h1');
        if (titleEl) {
            data.fullName = titleEl.textContent.trim();
        }

        cachedRecordData = data;
        return data;
    }

    /**
     * Initiate a phone call
     */
    async function initiateCall(module, recordId) {
        const record = await fetchRecordData(module, recordId);

        if (!record.phone) {
            showNotification('No phone number found for this record', 'warning');
            return;
        }

        window.open(
            COMM_CONFIG.callUrl + encodeURIComponent(record.phone) + '&parent_type=' + module + '&parent_id=' + recordId,
            'TwilioCall',
            'width=500,height=450,scrollbars=yes'
        );
    }

    /**
     * Show SMS compose modal
     */
    async function showSmsCompose(module, recordId) {
        const record = await fetchRecordData(module, recordId);

        if (!record.phone) {
            showNotification('No phone number found for this record', 'warning');
            return;
        }

        const existing = document.getElementById(MODAL_ID);
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.id = MODAL_ID;
        modal.style.cssText = `
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 10000;
            display: flex; align-items: center; justify-content: center;
        `;

        modal.innerHTML = `
            <div style="background: white; border-radius: 8px; max-width: 500px; width: 95%;
                        box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                <div style="padding: 16px 20px; background: #0d6efd; color: white;
                            display: flex; justify-content: space-between; align-items: center; border-radius: 8px 8px 0 0;">
                    <h3 style="margin: 0; font-size: 18px;">Send SMS</h3>
                    <button id="sms-modal-close" style="background: none; border: none;
                            color: white; font-size: 24px; cursor: pointer; padding: 0 8px;">&times;</button>
                </div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #495057;">To:</label>
                        <div style="padding: 10px 12px; background: #f8f9fa; border-radius: 4px; border: 1px solid #dee2e6;">
                            <span style="font-weight: 500;">${record.fullName || 'Unknown'}</span>
                            <span style="color: #6c757d; margin-left: 8px;">${record.phone}</span>
                        </div>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #495057;">Message:</label>
                        <textarea id="sms-message" style="width: 100%; min-height: 120px; padding: 12px;
                                  border: 1px solid #dee2e6; border-radius: 4px; font-size: 14px; resize: vertical;
                                  box-sizing: border-box;" placeholder="Type your message..."></textarea>
                        <div style="text-align: right; font-size: 12px; color: #6c757d; margin-top: 4px;">
                            <span id="sms-char-count">0</span>/160 characters
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button id="sms-cancel-btn" style="padding: 10px 20px; background: #6c757d; color: white;
                                border: none; border-radius: 4px; cursor: pointer; font-weight: 500;">Cancel</button>
                        <button id="sms-send-btn" style="padding: 10px 20px; background: #0d6efd; color: white;
                                border: none; border-radius: 4px; cursor: pointer; font-weight: 500;">
                            <span id="sms-send-text">Send SMS</span>
                            <span id="sms-send-spinner" style="display: none;">Sending...</span>
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Event handlers
        const messageEl = document.getElementById('sms-message');
        const charCountEl = document.getElementById('sms-char-count');
        const sendBtn = document.getElementById('sms-send-btn');
        const cancelBtn = document.getElementById('sms-cancel-btn');
        const closeBtn = document.getElementById('sms-modal-close');

        messageEl.addEventListener('input', () => {
            charCountEl.textContent = messageEl.value.length;
        });

        const closeModal = () => modal.remove();
        closeBtn.onclick = closeModal;
        cancelBtn.onclick = closeModal;
        modal.onclick = (e) => { if (e.target === modal) closeModal(); };

        sendBtn.onclick = async () => {
            const message = messageEl.value.trim();
            if (!message) {
                showNotification('Please enter a message', 'warning');
                return;
            }

            document.getElementById('sms-send-text').style.display = 'none';
            document.getElementById('sms-send-spinner').style.display = 'inline';
            sendBtn.disabled = true;

            try {
                const formData = new FormData();
                formData.append('action_type', 'send');
                formData.append('to', record.phone);
                formData.append('message', message);
                formData.append('parent_type', module);
                formData.append('parent_id', recordId);

                const response = await fetch('legacy/index.php?module=TwilioIntegration&action=sendsms', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });

                const result = await response.json();

                if (result.success) {
                    showNotification('SMS sent successfully!', 'success');
                    closeModal();
                } else {
                    showNotification(result.error || 'Failed to send SMS', 'error');
                }
            } catch (error) {
                console.error('[LeadJourney] SMS error:', error);
                showNotification('Failed to send SMS: ' + error.message, 'error');
            } finally {
                document.getElementById('sms-send-text').style.display = 'inline';
                document.getElementById('sms-send-spinner').style.display = 'none';
                sendBtn.disabled = false;
            }
        };

        messageEl.focus();
    }

    /**
     * Open email compose
     */
    async function openEmailCompose(module, recordId, prefillMessage = '') {
        const record = await fetchRecordData(module, recordId);

        // Navigate to email compose with parent context
        let emailUrl = `#/emails/compose?return_module=${module}&return_id=${recordId}&parent_type=${module}&parent_id=${recordId}`;

        if (record.email) {
            emailUrl += '&to_addrs=' + encodeURIComponent(record.email);
        }

        // Store prefill message in sessionStorage for the email compose to pick up
        if (prefillMessage) {
            sessionStorage.setItem('emailPrefillBody', prefillMessage);
        }

        window.location.hash = emailUrl;
    }

    /**
     * Show notification toast
     */
    function showNotification(message, type = 'info') {
        const existing = document.querySelectorAll('.leadjourney-notification');
        existing.forEach(n => n.remove());

        const colors = {
            success: { bg: '#198754', icon: '✓' },
            error: { bg: '#dc3545', icon: '✕' },
            warning: { bg: '#ffc107', icon: '⚠', textColor: '#000' },
            info: { bg: '#0d6efd', icon: 'ℹ' }
        };

        const config = colors[type] || colors.info;

        const notification = document.createElement('div');
        notification.className = 'leadjourney-notification';
        notification.style.cssText = `
            position: fixed; top: 20px; right: 20px; z-index: 10001;
            padding: 12px 20px; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            background: ${config.bg}; color: ${config.textColor || 'white'};
            font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px;
            animation: slideIn 0.3s ease;
        `;
        notification.innerHTML = `<span>${config.icon}</span> ${message}`;

        // Add animation styles
        if (!document.getElementById('leadjourney-notification-styles')) {
            const style = document.createElement('style');
            style.id = 'leadjourney-notification-styles';
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
            `;
            document.head.appendChild(style);
        }

        document.body.appendChild(notification);
        setTimeout(() => notification.remove(), 4000);
    }

    /**
     * Get current module and record ID from URL
     */
    function getRecordInfo() {
        const hash = window.location.hash;
        const match = hash.match(/#\/(leads|contacts)\/record\/([a-f0-9-]+)/i);

        if (match) {
            console.log('[LeadJourney] Matched URL:', hash, 'Module:', match[1], 'ID:', match[2]);
            return {
                module: match[1].toLowerCase() === 'leads' ? 'Leads' : 'Contacts',
                recordId: match[2],
                mode: 'record'
            };
        }
        return null;
    }

    /**
     * Format touchpoint type for display
     */
    function formatTouchpointType(type) {
        const typeMap = {
            'call_outbound': 'Outbound Call',
            'call_inbound': 'Inbound Call',
            'call': 'Call',
            'sms_outbound': 'SMS Sent',
            'sms_inbound': 'SMS Received',
            'sms': 'SMS',
            'email_outbound': 'Email Sent',
            'email_inbound': 'Email Received',
            'email': 'Email',
            'voicemail': 'Voicemail',
            'verbacall_signup_sent': 'Verbacall Signup Link',
            'verbacall_discount_offer': 'Discount Offer',
            'verbacall_payment_email_sent': 'Payment Link Sent',
            'note': 'Note',
            'meeting': 'Meeting',
            'task': 'Task'
        };
        return typeMap[type] || type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }

    /**
     * Get icon for touchpoint type
     */
    function getTypeIcon(type) {
        const icons = {
            'call_outbound': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>',
            'call_inbound': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M20 15.5c-1.25 0-2.45-.2-3.57-.57-.35-.11-.74-.03-1.02.24l-2.2 2.2c-2.83-1.44-5.15-3.75-6.59-6.59l2.2-2.21c.28-.26.36-.65.25-1C8.7 6.45 8.5 5.25 8.5 4c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1 0 9.39 7.61 17 17 17 .55 0 1-.45 1-1v-3.5c0-.55-.45-1-1-1zM19 12h2c0-4.97-4.03-9-9-9v2c3.87 0 7 3.13 7 7z"/></svg>',
            'call': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>',
            'sms_outbound': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>',
            'sms_inbound': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12zM7 9h2v2H7zm4 0h2v2h-2zm4 0h2v2h-2z"/></svg>',
            'sms': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>',
            'email_outbound': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
            'email_inbound': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V8l8 5 8-5v10zm-8-7L4 6h16l-8 5z"/></svg>',
            'email': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
            'voicemail': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M18.5 6C15.46 6 13 8.46 13 11.5c0 1.33.47 2.55 1.26 3.5H9.74c.79-.95 1.26-2.17 1.26-3.5C11 8.46 8.54 6 5.5 6S0 8.46 0 11.5 2.46 17 5.5 17h13c3.04 0 5.5-2.46 5.5-5.5S21.54 6 18.5 6zm-13 9C3.57 15 2 13.43 2 11.5S3.57 8 5.5 8 9 9.57 9 11.5 7.43 15 5.5 15zm13 0c-1.93 0-3.5-1.57-3.5-3.5S16.57 8 18.5 8 22 9.57 22 11.5 20.43 15 18.5 15z"/></svg>',
            'verbacall_signup_sent': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>',
            'verbacall_discount_offer': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></svg>',
            'verbacall_payment_email_sent': '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>'
        };
        return icons[type] || '<svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>';
    }

    /**
     * Get color for touchpoint type
     */
    function getTypeColor(type) {
        if (type.includes('call') || type === 'voicemail') return '#198754';
        if (type.includes('sms')) return '#0d6efd';
        if (type.includes('email')) return '#6f42c1';
        if (type.includes('verbacall')) return '#fd7e14';
        return '#6c757d';
    }

    /**
     * Format date for display
     */
    function formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    /**
     * Format relative time
     */
    function formatRelativeTime(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffHours < 24) return `${diffHours}h ago`;
        if (diffDays < 7) return `${diffDays}d ago`;
        return formatDate(dateStr);
    }

    /**
     * Group entries by thread (for threaded view)
     */
    function groupByThread(entries) {
        const threads = new Map();
        const THREAD_WINDOW_MS = 3600000; // 1 hour window for grouping

        entries.forEach(entry => {
            const threadId = entry.thread_id || null;
            const entryDate = new Date(entry.touchpoint_date || entry.date_entered).getTime();

            if (threadId) {
                // Use explicit thread_id if available
                if (!threads.has(threadId)) {
                    threads.set(threadId, []);
                }
                threads.get(threadId).push(entry);
            } else {
                // Group by type and time proximity
                let foundThread = false;
                const typePrefix = entry.touchpoint_type.split('_')[0]; // call, sms, email

                for (const [key, thread] of threads.entries()) {
                    if (key.startsWith('auto_')) {
                        const lastEntry = thread[thread.length - 1];
                        const lastDate = new Date(lastEntry.touchpoint_date || lastEntry.date_entered).getTime();
                        const lastTypePrefix = lastEntry.touchpoint_type.split('_')[0];

                        // Same type prefix and within time window
                        if (typePrefix === lastTypePrefix && Math.abs(entryDate - lastDate) < THREAD_WINDOW_MS) {
                            thread.push(entry);
                            foundThread = true;
                            break;
                        }
                    }
                }

                if (!foundThread) {
                    const autoKey = `auto_${entry.id}`;
                    threads.set(autoKey, [entry]);
                }
            }
        });

        // Convert to array and sort by most recent entry
        return Array.from(threads.values())
            .map(thread => {
                thread.sort((a, b) => new Date(b.touchpoint_date || b.date_entered) - new Date(a.touchpoint_date || a.date_entered));
                return thread;
            })
            .sort((a, b) => {
                const dateA = new Date(a[0].touchpoint_date || a[0].date_entered);
                const dateB = new Date(b[0].touchpoint_date || b[0].date_entered);
                return dateB - dateA;
            });
    }

    /**
     * Filter entries by type category
     */
    function filterByType(entries, category) {
        if (category === 'all' || !TYPE_CATEGORIES[category]) {
            return entries;
        }
        const allowedTypes = TYPE_CATEGORIES[category].types;
        return entries.filter(e => allowedTypes.includes(e.touchpoint_type));
    }

    /**
     * Create and show modal
     */
    function showModal(title, content, showControls = false) {
        // Remove existing modal
        const existing = document.getElementById(MODAL_ID);
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.id = MODAL_ID;
        modal.style.cssText = `
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 10000;
            display: flex; align-items: center; justify-content: center;
        `;

        const controlsHtml = showControls ? `
            <div id="leadjourney-controls" style="padding: 12px 20px; background: #f8f9fa; border-bottom: 1px solid #dee2e6;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <!-- View Mode Toggle -->
                    <div style="display: flex; gap: 4px; background: #e9ecef; border-radius: 6px; padding: 2px;">
                        <button id="view-mode-flat" class="view-mode-btn ${currentViewMode === 'flat' ? 'active' : ''}"
                            style="padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500;
                                   background: ${currentViewMode === 'flat' ? '#0d6efd' : 'transparent'};
                                   color: ${currentViewMode === 'flat' ? 'white' : '#495057'};">
                            Flat View
                        </button>
                        <button id="view-mode-threaded" class="view-mode-btn ${currentViewMode === 'threaded' ? 'active' : ''}"
                            style="padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500;
                                   background: ${currentViewMode === 'threaded' ? '#0d6efd' : 'transparent'};
                                   color: ${currentViewMode === 'threaded' ? 'white' : '#495057'};">
                            Threaded
                        </button>
                    </div>
                    <!-- Type Filter Tabs -->
                    <div id="type-tabs" style="display: flex; gap: 4px; flex-wrap: wrap;">
                        ${Object.entries(TYPE_CATEGORIES).map(([key, cat]) => `
                            <button class="type-tab ${currentFilter === key ? 'active' : ''}" data-type="${key}"
                                style="padding: 6px 14px; border: 1px solid ${currentFilter === key ? '#0d6efd' : '#dee2e6'};
                                       border-radius: 16px; cursor: pointer; font-size: 12px; font-weight: 500;
                                       background: ${currentFilter === key ? '#0d6efd' : 'white'};
                                       color: ${currentFilter === key ? 'white' : '#495057'};">
                                ${cat.label}
                            </button>
                        `).join('')}
                    </div>
                </div>
            </div>
        ` : '';

        // Messenger-style compose bar at bottom of modal
        const messengerBarHtml = showControls && currentModule && currentRecordId ? `
            <div id="messenger-bar" style="padding: 12px 16px; background: #f8f9fa; border-top: 1px solid #dee2e6;">
                <div style="display: flex; align-items: flex-end; gap: 10px;">
                    <!-- Call Button -->
                    <button id="modal-call-btn" style="display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;background:#198754;color:white;border:none;border-radius:50%;cursor:pointer;transition:all 0.2s;flex-shrink:0;" title="Make Call">
                        <svg style="width:20px;height:20px" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                        </svg>
                    </button>

                    <!-- Message Input Area -->
                    <div style="flex:1;display:flex;flex-direction:column;gap:8px;">
                        <textarea id="messenger-input" placeholder="Type your message..." style="width:100%;min-height:60px;max-height:120px;padding:10px 12px;border:1px solid #dee2e6;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;outline:none;transition:border-color 0.2s;" onfocus="this.style.borderColor='#0d6efd'" onblur="this.style.borderColor='#dee2e6'"></textarea>
                    </div>

                    <!-- Channel Selector + Send -->
                    <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                        <select id="messenger-channel" style="padding:8px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;font-weight:500;background:white;cursor:pointer;outline:none;">
                            <option value="sms">📱 SMS</option>
                            <option value="email">✉️ Email</option>
                        </select>
                        <button id="modal-send-btn" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 24px;background:#0d6efd;color:white;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all 0.2s;gap:6px;" title="Send Message">
                            <span>Send</span>
                            <svg style="width:16px;height:16px" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        ` : '';

        modal.innerHTML = `
            <div style="background: white; border-radius: 8px; max-width: 900px; width: 95%;
                        max-height: 85vh; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.3); display: flex; flex-direction: column;">
                <div style="padding: 16px 20px; background: #0d6efd; color: white;
                            display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 18px;">${title}</h3>
                    <button id="leadjourney-modal-close" style="background: none; border: none;
                            color: white; font-size: 24px; cursor: pointer; padding: 0 8px;">&times;</button>
                </div>
                ${controlsHtml}
                <div id="leadjourney-content" style="padding: 20px; overflow-y: auto; flex: 1; max-height: calc(85vh - ${showControls ? '200px' : '120px'});">
                    ${content}
                </div>
                ${messengerBarHtml}
            </div>
        `;

        document.body.appendChild(modal);

        // Close handlers
        document.getElementById('leadjourney-modal-close').onclick = () => modal.remove();
        modal.onclick = (e) => { if (e.target === modal) modal.remove(); };
        document.addEventListener('keydown', function closeOnEsc(e) {
            if (e.key === 'Escape') {
                modal.remove();
                document.removeEventListener('keydown', closeOnEsc);
            }
        });

        // Messenger bar button handlers
        if (showControls && currentModule && currentRecordId) {
            const callBtn = document.getElementById('modal-call-btn');
            const sendBtn = document.getElementById('modal-send-btn');
            const channelSelect = document.getElementById('messenger-channel');
            const messageInput = document.getElementById('messenger-input');

            if (callBtn) {
                callBtn.onmouseover = () => { callBtn.style.transform = 'scale(1.05)'; callBtn.style.background = '#157347'; };
                callBtn.onmouseout = () => { callBtn.style.transform = 'scale(1)'; callBtn.style.background = '#198754'; };
                callBtn.onclick = () => initiateCall(currentModule, currentRecordId);
            }

            if (sendBtn && channelSelect && messageInput) {
                sendBtn.onmouseover = () => { sendBtn.style.background = '#0b5ed7'; };
                sendBtn.onmouseout = () => { sendBtn.style.background = '#0d6efd'; };

                sendBtn.onclick = async () => {
                    const channel = channelSelect.value;
                    const message = messageInput.value.trim();

                    if (!message) {
                        messageInput.style.borderColor = '#dc3545';
                        messageInput.focus();
                        setTimeout(() => messageInput.style.borderColor = '#dee2e6', 2000);
                        return;
                    }

                    if (channel === 'sms') {
                        // Send SMS directly
                        sendBtn.disabled = true;
                        sendBtn.innerHTML = '<span>Sending...</span>';

                        try {
                            const record = await fetchRecordData(currentModule, currentRecordId);
                            if (!record || !record.phone) {
                                alert('No phone number found for this record');
                                sendBtn.disabled = false;
                                sendBtn.innerHTML = '<span>Send</span><svg style="width:16px;height:16px" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';
                                return;
                            }

                            const formData = new FormData();
                            formData.append('action_type', 'send');
                            formData.append('to', record.phone);
                            formData.append('message', message);
                            formData.append('parent_type', currentModule);
                            formData.append('parent_id', currentRecordId);

                            const response = await fetch('legacy/index.php?module=TwilioIntegration&action=sendsms', {
                                method: 'POST',
                                body: formData,
                                credentials: 'same-origin'
                            });

                            const result = await response.json();
                            if (result.success) {
                                messageInput.value = '';
                                showNotification('SMS sent successfully!', 'success');
                                // Refresh timeline
                                setTimeout(() => showTimeline(currentModule, currentRecordId), 1000);
                            } else {
                                showNotification('Failed to send SMS: ' + (result.error || 'Unknown error'), 'error');
                            }
                        } catch (error) {
                            showNotification('Error sending SMS: ' + error.message, 'error');
                        }

                        sendBtn.disabled = false;
                        sendBtn.innerHTML = '<span>Send</span><svg style="width:16px;height:16px" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';

                    } else if (channel === 'email') {
                        // Open email compose with pre-filled message
                        modal.remove();
                        openEmailCompose(currentModule, currentRecordId, message);
                    }
                };

                // Enter key to send (Shift+Enter for new line)
                messageInput.onkeydown = (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendBtn.click();
                    }
                };
            }
        }

        return modal;
    }

    /**
     * Fix recording URL to include legacy/ prefix
     */
    function fixRecordingUrl(url) {
        if (!url) return null;
        // Add legacy/ prefix if URL starts with index.php
        if (url.startsWith('index.php')) {
            return 'legacy/' + url;
        }
        return url;
    }

    /**
     * Render single timeline entry
     */
    function renderEntry(entry) {
        const touchpointData = entry.touchpoint_data || {};
        let details = '';
        let extraInfo = '';
        const recordingUrl = fixRecordingUrl(entry.recording_url);

        // Handle Verbacall-specific details
        if (entry.touchpoint_type === 'verbacall_signup_sent') {
            if (touchpointData.signup_url) {
                details = `<a href="${touchpointData.signup_url}" target="_blank" style="color:#fd7e14;font-weight:500;">Open Signup Link</a>`;
            }
            if (touchpointData.sent_to) {
                extraInfo = `<div style="font-size: 11px; color: #6c757d; margin-top: 4px;">Sent to: ${touchpointData.sent_to}</div>`;
            }
        } else if (entry.touchpoint_type === 'verbacall_discount_offer') {
            if (touchpointData.discount_url) {
                details = `<a href="${touchpointData.discount_url}" target="_blank" style="color:#fd7e14;font-weight:500;">View Discount Offer</a>`;
            }
            if (touchpointData.discount_percentage) {
                extraInfo = `<div style="font-size: 11px; color: #198754; margin-top: 4px;"><strong>${touchpointData.discount_percentage}% discount</strong>${touchpointData.expiry_days ? ` • Expires in ${touchpointData.expiry_days} days` : ''}</div>`;
            }
        } else if (entry.touchpoint_type === 'verbacall_payment_email_sent') {
            if (touchpointData.payment_url) {
                details = `<a href="${touchpointData.payment_url}" target="_blank" style="color:#fd7e14;font-weight:500;">View Payment Link</a>`;
            }
        } else {
            // Standard handling for calls/SMS/emails
            if (touchpointData.duration) {
                details = `Duration: ${touchpointData.duration}s`;
            }
            if (recordingUrl) {
                details += ` <a href="${recordingUrl}" target="_blank" style="color:#198754;margin-left:8px;">Play Recording</a>`;
            }
        }

        const typeColor = getTypeColor(entry.touchpoint_type);

        return `
            <div style="border-left: 3px solid ${typeColor}; padding: 12px 16px; background: #f8f9fa; border-radius: 0 6px 6px 0; margin-bottom: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: ${typeColor};">${getTypeIcon(entry.touchpoint_type)}</span>
                        <strong style="font-size: 13px; color: #212529;">${formatTouchpointType(entry.touchpoint_type)}</strong>
                    </div>
                    <span style="font-size: 11px; color: #6c757d;" title="${formatDate(entry.touchpoint_date || entry.date_entered)}">
                        ${formatRelativeTime(entry.touchpoint_date || entry.date_entered)}
                    </span>
                </div>
                ${entry.name ? `<div style="font-size: 13px; color: #495057; margin-bottom: 4px;">${entry.name}</div>` : ''}
                ${entry.description ? `<div style="font-size: 12px; color: #6c757d;">${entry.description}</div>` : ''}
                ${extraInfo}
                ${details ? `<div style="font-size: 12px; margin-top: 6px;">${details}</div>` : ''}
            </div>
        `;
    }

    /**
     * Render threaded timeline
     */
    function renderThreadedTimeline(threads) {
        if (threads.length === 0) {
            return '<div style="text-align:center;padding:40px;color:#6c757d;">No entries match the selected filter.</div>';
        }

        let html = '';
        threads.forEach((thread, idx) => {
            const firstEntry = thread[0];
            const typePrefix = firstEntry.touchpoint_type.split('_')[0];
            const threadColor = getTypeColor(firstEntry.touchpoint_type);

            if (thread.length === 1) {
                // Single entry - no thread wrapper
                html += renderEntry(firstEntry);
            } else {
                // Multiple entries - show as conversation thread
                const threadLabel = typePrefix === 'call' ? 'Call Thread' :
                                   typePrefix === 'sms' ? 'SMS Conversation' :
                                   typePrefix === 'email' ? 'Email Thread' : 'Thread';

                html += `
                    <div style="border: 1px solid #dee2e6; border-radius: 8px; margin-bottom: 12px; overflow: hidden;">
                        <div style="background: ${threadColor}15; padding: 10px 16px; border-bottom: 1px solid #dee2e6;
                                    display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: ${threadColor};">${getTypeIcon(firstEntry.touchpoint_type)}</span>
                                <strong style="font-size: 13px; color: ${threadColor};">${threadLabel}</strong>
                                <span style="font-size: 11px; color: #6c757d; background: #e9ecef; padding: 2px 8px; border-radius: 10px;">
                                    ${thread.length} messages
                                </span>
                            </div>
                            <span style="font-size: 11px; color: #6c757d;">
                                ${formatRelativeTime(firstEntry.touchpoint_date || firstEntry.date_entered)}
                            </span>
                        </div>
                        <div style="padding: 8px;">
                            ${thread.map(entry => `
                                <div style="padding: 8px 12px; border-left: 2px solid #dee2e6; margin: 4px 0 4px 8px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <span style="font-size: 12px; font-weight: 500; color: #495057;">
                                            ${formatTouchpointType(entry.touchpoint_type)}
                                        </span>
                                        <span style="font-size: 10px; color: #6c757d;">
                                            ${formatRelativeTime(entry.touchpoint_date || entry.date_entered)}
                                        </span>
                                    </div>
                                    ${entry.name ? `<div style="font-size: 12px; color: #6c757d;">${entry.name}</div>` : ''}
                                    ${entry.recording_url ? `<a href="${fixRecordingUrl(entry.recording_url)}" target="_blank" style="font-size: 11px; color: #198754;">Play Recording</a>` : ''}
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
        });

        return html;
    }

    /**
     * Render flat timeline
     */
    function renderFlatTimeline(entries) {
        if (entries.length === 0) {
            return '<div style="text-align:center;padding:40px;color:#6c757d;">No entries match the selected filter.</div>';
        }
        return entries.map(renderEntry).join('');
    }

    /**
     * Update timeline display based on current mode and filter
     */
    function updateTimelineDisplay() {
        if (!cachedTimelineData) return;

        const contentDiv = document.getElementById('leadjourney-content');
        if (!contentDiv) return;

        const filteredData = filterByType(cachedTimelineData.data, currentFilter);

        let html;
        if (currentViewMode === 'threaded') {
            const threads = groupByThread(filteredData);
            html = renderThreadedTimeline(threads);
        } else {
            html = renderFlatTimeline(filteredData);
        }

        contentDiv.innerHTML = html;

        // Update count in title
        const modal = document.getElementById(MODAL_ID);
        if (modal) {
            const titleEl = modal.querySelector('h3');
            if (titleEl) {
                titleEl.textContent = `Journey Timeline (${filteredData.length} entries)`;
            }
        }
    }

    /**
     * Attach control event handlers
     */
    function attachControlHandlers() {
        // View mode buttons
        const flatBtn = document.getElementById('view-mode-flat');
        const threadedBtn = document.getElementById('view-mode-threaded');

        if (flatBtn) {
            flatBtn.onclick = () => {
                currentViewMode = 'flat';
                flatBtn.style.background = '#0d6efd';
                flatBtn.style.color = 'white';
                threadedBtn.style.background = 'transparent';
                threadedBtn.style.color = '#495057';
                updateTimelineDisplay();
            };
        }

        if (threadedBtn) {
            threadedBtn.onclick = () => {
                currentViewMode = 'threaded';
                threadedBtn.style.background = '#0d6efd';
                threadedBtn.style.color = 'white';
                flatBtn.style.background = 'transparent';
                flatBtn.style.color = '#495057';
                updateTimelineDisplay();
            };
        }

        // Type filter tabs
        document.querySelectorAll('.type-tab').forEach(tab => {
            tab.onclick = () => {
                currentFilter = tab.dataset.type;

                // Update tab styles
                document.querySelectorAll('.type-tab').forEach(t => {
                    t.style.background = 'white';
                    t.style.color = '#495057';
                    t.style.borderColor = '#dee2e6';
                });
                tab.style.background = '#0d6efd';
                tab.style.color = 'white';
                tab.style.borderColor = '#0d6efd';

                updateTimelineDisplay();
            };
        });
    }

    /**
     * Fetch and display timeline
     */
    async function showTimeline(module, recordId) {
        // Store for modal action buttons
        currentModule = module;
        currentRecordId = recordId;

        showModal('Loading...', '<div style="text-align:center;padding:40px;">Loading timeline...</div>');

        try {
            const response = await fetch(`legacy/leadjourney_api.php?api_action=timeline&parent_type=${module}&parent_id=${recordId}`);
            const data = await response.json();

            if (!data.success || data.count === 0) {
                showModal('Journey Timeline', `
                    <div style="text-align:center;padding:40px;color:#6c757d;">
                        <svg style="width:48px;height:48px;margin-bottom:16px;" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                        <p style="font-size:16px;">No timeline entries found for this record.</p>
                        <p style="font-size:13px;">Timeline entries are created when calls, SMS, or emails are logged.</p>
                    </div>
                `);
                return;
            }

            // Cache data and reset filters
            cachedTimelineData = data;
            currentFilter = 'all';
            currentViewMode = 'flat';

            // Show modal with controls
            const html = renderFlatTimeline(data.data);
            showModal(`Journey Timeline (${data.count} entries)`, html, true);

            // Attach event handlers after modal is shown
            setTimeout(attachControlHandlers, 100);

        } catch (error) {
            console.error('[LeadJourney] Error fetching timeline:', error);
            showModal('Error', `<div style="color:#dc3545;padding:20px;">Failed to load timeline: ${error.message}</div>`);
        }
    }

    /**
     * Fetch and display recordings
     */
    async function showRecordings(module, recordId) {
        showModal('Loading...', '<div style="text-align:center;padding:40px;">Loading recordings...</div>');

        try {
            const response = await fetch(`legacy/leadjourney_api.php?api_action=recordings&parent_type=${module}&parent_id=${recordId}`);
            const data = await response.json();

            if (!data.success || data.count === 0) {
                showModal('Call Recordings', `
                    <div style="text-align:center;padding:40px;color:#6c757d;">
                        <svg style="width:48px;height:48px;margin-bottom:16px;" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/>
                            <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                        </svg>
                        <p style="font-size:16px;">No call recordings found.</p>
                        <p style="font-size:13px;">Recordings appear here after calls with recording enabled.</p>
                    </div>
                `);
                return;
            }

            let html = '<div style="display:flex;flex-direction:column;gap:12px;">';
            for (const rec of data.data) {
                const direction = rec.direction === 'outbound' ? 'Outbound' : 'Inbound';
                const dirColor = rec.direction === 'outbound' ? '#198754' : '#0d6efd';
                html += `
                    <div style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
                        <div style="background:${dirColor}10;padding:12px 16px;border-bottom:1px solid #dee2e6;">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span style="color:${dirColor};">${getTypeIcon(rec.direction === 'outbound' ? 'call_outbound' : 'call_inbound')}</span>
                                    <strong style="color:${dirColor};">${direction} Call</strong>
                                </div>
                                <span style="font-size:12px;color:#6c757d;">${formatDate(rec.touchpoint_date || rec.date_entered)}</span>
                            </div>
                            <div style="font-size:13px;margin-top:6px;color:#495057;">
                                ${rec.from_number || 'Unknown'} &rarr; ${rec.to_number || 'Unknown'}
                                ${rec.duration ? `<span style="color:#6c757d;margin-left:8px;">(${rec.duration}s)</span>` : ''}
                            </div>
                        </div>
                        <div style="padding:12px 16px;">
                            <audio controls style="width:100%;" src="${fixRecordingUrl(rec.recording_url)}">
                                Your browser does not support audio playback.
                            </audio>
                        </div>
                    </div>
                `;
            }
            html += '</div>';

            showModal(`Call Recordings (${data.count})`, html);
        } catch (error) {
            console.error('[LeadJourney] Error fetching recordings:', error);
            showModal('Error', `<div style="color:#dc3545;padding:20px;">Failed to load recordings: ${error.message}</div>`);
        }
    }

    /**
     * Create action buttons
     */
    function createButtons(module, recordId) {
        const container = document.createElement('div');
        container.id = BUTTON_CONTAINER_ID;
        container.style.cssText = `
            display: flex; gap: 8px; padding: 8px 16px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 6px; margin: 8px 0; border: 1px solid #dee2e6;
            align-items: center; flex-wrap: wrap;
        `;

        // Label
        const label = document.createElement('span');
        label.textContent = 'PowerPack:';
        label.style.cssText = 'font-size:12px;color:#6c757d;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-right:4px;';

        // Timeline button
        const timelineBtn = document.createElement('button');
        timelineBtn.innerHTML = `
            <svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm8.94 3c-.46-4.17-3.77-7.48-7.94-7.94V1h-2v2.06C6.83 3.52 3.52 6.83 3.06 11H1v2h2.06c.46 4.17 3.77 7.48 7.94 7.94V23h2v-2.06c4.17-.46 7.48-3.77 7.94-7.94H23v-2h-2.06zM12 19c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z"/>
            </svg>
            Timeline
        `;
        timelineBtn.style.cssText = `
            display:inline-flex;align-items:center;padding:8px 14px;background:#0d6efd;
            color:white;border:none;border-radius:4px;font-size:13px;font-weight:500;
            cursor:pointer;transition:background 0.2s;white-space:nowrap;
        `;
        timelineBtn.onmouseover = () => timelineBtn.style.background = '#0b5ed7';
        timelineBtn.onmouseout = () => timelineBtn.style.background = '#0d6efd';
        timelineBtn.onclick = () => showTimeline(module, recordId);

        // Recordings button
        const recordingsBtn = document.createElement('button');
        recordingsBtn.innerHTML = `
            <svg style="width:16px;height:16px;margin-right:6px;vertical-align:middle" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.91-3c-.49 0-.9.36-.98.85C16.52 14.2 14.47 16 12 16s-4.52-1.8-4.93-4.15c-.08-.49-.49-.85-.98-.85-.61 0-1.09.54-1 1.14.49 3 2.89 5.35 5.91 5.78V20c0 .55.45 1 1 1s1-.45 1-1v-2.08c3.02-.43 5.42-2.78 5.91-5.78.1-.6-.39-1.14-1-1.14z"/>
            </svg>
            Recordings
        `;
        recordingsBtn.style.cssText = `
            display:inline-flex;align-items:center;padding:8px 14px;background:#6c757d;
            color:white;border:none;border-radius:4px;font-size:13px;font-weight:500;
            cursor:pointer;transition:background 0.2s;white-space:nowrap;
        `;
        recordingsBtn.onmouseover = () => recordingsBtn.style.background = '#5c636a';
        recordingsBtn.onmouseout = () => recordingsBtn.style.background = '#6c757d';
        recordingsBtn.onclick = () => showRecordings(module, recordId);

        container.appendChild(label);
        container.appendChild(timelineBtn);
        container.appendChild(recordingsBtn);

        return container;
    }

    /**
     * Find injection point
     */
    function findInjectionPoint() {
        const selectors = [
            '.record-view-hr-container',
            '.record-view-container',
            '.record-view',
            'scrm-record-header',
            'scrm-record-container'
        ];

        for (const selector of selectors) {
            const element = document.querySelector(selector);
            if (element) {
                console.log('[LeadJourney] Found injection point:', selector);
                return { element, selector };
            }
        }
        return null;
    }

    /**
     * Inject buttons
     */
    function injectButtons() {
        const recordInfo = getRecordInfo();
        const existingButtons = document.getElementById(BUTTON_CONTAINER_ID);

        if (existingButtons) {
            if (!recordInfo || lastUrl !== window.location.hash) {
                existingButtons.remove();
            } else {
                return;
            }
        }

        if (!recordInfo) {
            retryCount = 0;
            return;
        }

        const result = findInjectionPoint();
        if (!result) {
            if (retryCount < MAX_RETRIES) {
                retryCount++;
                setTimeout(injectButtons, CHECK_INTERVAL);
            }
            return;
        }

        const { element: injectionPoint, selector } = result;
        const buttons = createButtons(recordInfo.module, recordInfo.recordId);

        if (injectionPoint.firstChild) {
            injectionPoint.insertBefore(buttons, injectionPoint.firstChild);
        } else {
            injectionPoint.appendChild(buttons);
        }

        lastUrl = window.location.hash;
        retryCount = 0;
        console.log('[LeadJourney] Buttons injected for', recordInfo.module, recordInfo.recordId);
    }

    /**
     * Initialize
     */
    function init() {
        console.log('[LeadJourney] Initializing button injection v2.1.0...');

        window.addEventListener('hashchange', () => {
            retryCount = 0;
            setTimeout(injectButtons, 300);
        });

        const observer = new MutationObserver(() => {
            if (window.location.hash !== lastUrl) {
                retryCount = 0;
                setTimeout(injectButtons, 300);
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });
        setTimeout(injectButtons, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
