/**
 * SuiteCRM PowerPack - Twilio Incoming Call Handler
 * Global script that runs on all pages to handle incoming browser calls
 *
 * This script initializes a Twilio Device with incoming capability and
 * displays an incoming call modal when a call arrives.
 */
(function() {
    'use strict';

    // Prevent multiple initializations
    if (window.POWERPACK_TWILIO_INCOMING_INIT) {
        return;
    }
    window.POWERPACK_TWILIO_INCOMING_INIT = true;

    // Configuration
    const CONFIG = {
        tokenEndpoint: 'legacy/twilio_webhook.php?action=token',
        callerLookupEndpoint: 'legacy/twilio_webhook.php?action=caller_lookup',
        tokenRefreshInterval: 50 * 60 * 1000, // 50 minutes (tokens last 1 hour)
        ringTimeout: 30000, // 30 seconds before auto-dismiss
        tabCoordinationKey: 'twilio_incoming_call_active_tab',
        callActiveKey: 'twilio_incoming_call_answered'
    };

    let device = null;
    let activeCall = null;
    let incomingCall = null;
    let callerInfo = null;
    let ringInterval = null;
    let ringTimeout = null;
    let callTimer = null;
    let callStartTime = null;
    let isLeadTab = false;
    let tabId = Math.random().toString(36).substring(2, 15);

    console.log('[TwilioIncoming] Initializing incoming call handler...');

    // =========================================================================
    // Tab Coordination (prevent multiple tabs from all ringing)
    // =========================================================================

    function claimIncomingCall(callSid) {
        const existing = localStorage.getItem(CONFIG.callActiveKey);
        if (existing) {
            const data = JSON.parse(existing);
            // If another tab already answered this call, don't ring
            if (data.callSid === callSid && data.answered) {
                return false;
            }
        }

        // Try to become the lead tab for this call
        localStorage.setItem(CONFIG.tabCoordinationKey, JSON.stringify({
            tabId: tabId,
            callSid: callSid,
            timestamp: Date.now()
        }));

        // Small delay to check if we won the race
        return true;
    }

    function markCallAnswered(callSid) {
        localStorage.setItem(CONFIG.callActiveKey, JSON.stringify({
            tabId: tabId,
            callSid: callSid,
            answered: true,
            timestamp: Date.now()
        }));
    }

    function clearCallClaim() {
        localStorage.removeItem(CONFIG.tabCoordinationKey);
        localStorage.removeItem(CONFIG.callActiveKey);
    }

    // Listen for other tabs answering
    window.addEventListener('storage', (e) => {
        if (e.key === CONFIG.callActiveKey && e.newValue) {
            const data = JSON.parse(e.newValue);
            // Another tab answered the call - dismiss our modal
            if (data.answered && data.tabId !== tabId && incomingCall) {
                console.log('[TwilioIncoming] Another tab answered the call');
                dismissIncomingModal();
            }
        }
    });

    // =========================================================================
    // Twilio Device Initialization
    // =========================================================================

    async function initTwilioDevice() {
        try {
            // Check if Twilio SDK is loaded
            if (typeof Twilio === 'undefined' || !Twilio.Device) {
                console.log('[TwilioIncoming] Twilio SDK not loaded, loading now...');
                await loadTwilioSdk();
            }

            const tokenData = await getAccessToken();
            if (!tokenData || !tokenData.token) {
                console.log('[TwilioIncoming] No token available - user may not have Twilio access');
                return;
            }

            console.log('[TwilioIncoming] Initializing device with identity:', tokenData.identity);

            device = new Twilio.Device(tokenData.token, {
                codecPreferences: ['opus', 'pcmu'],
                edge: 'ashburn',
                logLevel: 1
            });

            // Register event handlers
            device.on('registered', () => {
                console.log('[TwilioIncoming] Device registered - ready for incoming calls');
            });

            device.on('unregistered', () => {
                console.log('[TwilioIncoming] Device unregistered');
            });

            device.on('error', (error) => {
                console.error('[TwilioIncoming] Device error:', error);
            });

            device.on('tokenWillExpire', async () => {
                console.log('[TwilioIncoming] Token expiring, refreshing...');
                try {
                    const newTokenData = await getAccessToken();
                    if (newTokenData && newTokenData.token) {
                        device.updateToken(newTokenData.token);
                        console.log('[TwilioIncoming] Token refreshed');
                    }
                } catch (err) {
                    console.error('[TwilioIncoming] Token refresh failed:', err);
                }
            });

            // INCOMING CALL HANDLER - This is the main event!
            device.on('incoming', handleIncomingCall);

            // Register the device
            await device.register();

        } catch (error) {
            console.error('[TwilioIncoming] Failed to initialize device:', error);
        }
    }

    function loadTwilioSdk() {
        return new Promise((resolve, reject) => {
            if (typeof Twilio !== 'undefined' && Twilio.Device) {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://unpkg.com/@twilio/voice-sdk@2.10.2/dist/twilio.min.js';
            script.onload = () => {
                console.log('[TwilioIncoming] Twilio SDK loaded');
                resolve();
            };
            script.onerror = () => {
                console.error('[TwilioIncoming] Failed to load Twilio SDK');
                reject(new Error('Failed to load Twilio SDK'));
            };
            document.head.appendChild(script);
        });
    }

    async function getAccessToken() {
        try {
            const response = await fetch(CONFIG.tokenEndpoint, {
                method: 'GET',
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            if (data.success) {
                return data;
            }

            console.log('[TwilioIncoming] Token error:', data.error);
            return null;
        } catch (error) {
            console.error('[TwilioIncoming] Failed to get token:', error);
            return null;
        }
    }

    // =========================================================================
    // Incoming Call Handler
    // =========================================================================

    async function handleIncomingCall(call) {
        console.log('[TwilioIncoming] Incoming call from:', call.parameters.From);

        incomingCall = call;
        const callSid = call.parameters.CallSid;

        // Check if another tab already claimed this call
        if (!claimIncomingCall(callSid)) {
            console.log('[TwilioIncoming] Call already handled by another tab');
            return;
        }

        // Lookup caller info
        callerInfo = await lookupCaller(call.parameters.From);

        // Show incoming call modal
        showIncomingModal(call.parameters.From, callerInfo);

        // Start ringing sound
        startRinging();

        // Set timeout to auto-dismiss
        ringTimeout = setTimeout(() => {
            console.log('[TwilioIncoming] Ring timeout - dismissing');
            dismissIncomingModal();
        }, CONFIG.ringTimeout);

        // Handle call events
        call.on('cancel', () => {
            console.log('[TwilioIncoming] Call canceled by caller');
            dismissIncomingModal();
        });

        call.on('disconnect', () => {
            console.log('[TwilioIncoming] Call disconnected');
            handleCallEnd();
        });

        call.on('reject', () => {
            console.log('[TwilioIncoming] Call rejected');
            dismissIncomingModal();
        });
    }

    async function lookupCaller(phoneNumber) {
        try {
            const response = await fetch(
                CONFIG.callerLookupEndpoint + '&phone=' + encodeURIComponent(phoneNumber),
                { credentials: 'same-origin' }
            );
            const data = await response.json();

            if (data.success && data.found) {
                return data;
            }
            return null;
        } catch (error) {
            console.error('[TwilioIncoming] Caller lookup failed:', error);
            return null;
        }
    }

    function acceptCall() {
        if (!incomingCall) return;

        console.log('[TwilioIncoming] Accepting call');
        stopRinging();
        clearTimeout(ringTimeout);

        markCallAnswered(incomingCall.parameters.CallSid);
        incomingCall.accept();
        activeCall = incomingCall;

        // Switch to active call UI
        showActiveCallUI();
        startCallTimer();
    }

    function rejectCall() {
        if (!incomingCall) return;

        console.log('[TwilioIncoming] Rejecting call');
        stopRinging();
        clearTimeout(ringTimeout);

        incomingCall.reject();
        dismissIncomingModal();
    }

    function endCall() {
        if (activeCall) {
            activeCall.disconnect();
        }
        handleCallEnd();
    }

    function handleCallEnd() {
        stopRinging();
        stopCallTimer();
        clearCallClaim();

        activeCall = null;
        incomingCall = null;
        callerInfo = null;

        hideCallUI();
    }

    // =========================================================================
    // Ringing Sound
    // =========================================================================

    let audioContext = null;
    let isRinging = false;

    function startRinging() {
        stopRinging(); // Clear any existing
        isRinging = true;

        try {
            // Create or resume audio context
            if (!audioContext) {
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (audioContext.state === 'suspended') {
                audioContext.resume();
            }

            function playRingTone() {
                if (!isRinging || !audioContext) return;

                // Create dual-tone ring (like a real phone: 440Hz + 480Hz)
                const osc1 = audioContext.createOscillator();
                const osc2 = audioContext.createOscillator();
                const gainNode = audioContext.createGain();

                osc1.connect(gainNode);
                osc2.connect(gainNode);
                gainNode.connect(audioContext.destination);

                osc1.frequency.value = 440; // A4
                osc2.frequency.value = 480; // Slightly higher for ring effect
                osc1.type = 'sine';
                osc2.type = 'sine';
                gainNode.gain.value = 0.2;

                const now = audioContext.currentTime;
                osc1.start(now);
                osc2.start(now);
                osc1.stop(now + 0.4);
                osc2.stop(now + 0.4);
            }

            // Play immediately with double ring pattern
            playRingTone();
            setTimeout(playRingTone, 500);

            // Repeat every 3 seconds (ring-ring...pause...ring-ring)
            ringInterval = setInterval(() => {
                if (!isRinging) return;
                playRingTone();
                setTimeout(playRingTone, 500);
            }, 3000);

            console.log('[TwilioIncoming] Ringing started');

        } catch (e) {
            console.log('[TwilioIncoming] Audio not supported:', e.message);
        }
    }

    function stopRinging() {
        isRinging = false;
        if (ringInterval) {
            clearInterval(ringInterval);
            ringInterval = null;
        }
        console.log('[TwilioIncoming] Ringing stopped');
    }

    // =========================================================================
    // Call Timer
    // =========================================================================

    function startCallTimer() {
        callStartTime = Date.now();
        updateTimerDisplay();
        callTimer = setInterval(updateTimerDisplay, 1000);
    }

    function stopCallTimer() {
        if (callTimer) {
            clearInterval(callTimer);
            callTimer = null;
        }
        callStartTime = null;
    }

    function updateTimerDisplay() {
        const timerEl = document.getElementById('twilio-call-timer');
        if (!timerEl || !callStartTime) return;

        const elapsed = Math.floor((Date.now() - callStartTime) / 1000);
        const mins = Math.floor(elapsed / 60);
        const secs = elapsed % 60;
        timerEl.textContent = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }

    // =========================================================================
    // UI - Incoming Call Modal
    // =========================================================================

    function showIncomingModal(phoneNumber, callerInfo) {
        // Remove any existing modal
        hideCallUI();

        const callerName = callerInfo?.name || 'Unknown Caller';
        const callerModule = callerInfo?.module || '';
        const funnelType = callerInfo?.funnel_type || '';
        const recordLink = callerInfo?.record_id
            ? `legacy/index.php?module=${callerInfo.module}&action=DetailView&record=${callerInfo.record_id}`
            : null;

        const modal = document.createElement('div');
        modal.id = 'twilio-incoming-modal';
        modal.innerHTML = `
            <style>
                #twilio-incoming-modal {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.7);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 100000;
                    animation: twiModalFadeIn 0.3s ease;
                }
                @keyframes twiModalFadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                @keyframes twiPulse {
                    0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7); }
                    50% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(40, 167, 69, 0); }
                }
                @keyframes twiRing {
                    0%, 100% { transform: rotate(0deg); }
                    10%, 30%, 50%, 70%, 90% { transform: rotate(-10deg); }
                    20%, 40%, 60%, 80% { transform: rotate(10deg); }
                }
                .twi-modal-content {
                    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
                    border-radius: 24px;
                    padding: 40px;
                    text-align: center;
                    max-width: 400px;
                    width: 90%;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
                    border: 1px solid rgba(255, 255, 255, 0.1);
                }
                .twi-incoming-label {
                    color: #4ecca3;
                    font-size: 14px;
                    text-transform: uppercase;
                    letter-spacing: 3px;
                    margin-bottom: 20px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                }
                .twi-incoming-label .phone-icon {
                    animation: twiRing 0.5s ease infinite;
                    display: inline-block;
                }
                .twi-avatar {
                    width: 100px;
                    height: 100px;
                    background: linear-gradient(135deg, #4ecca3, #28a745);
                    border-radius: 50%;
                    margin: 0 auto 20px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 40px;
                    animation: twiPulse 2s ease infinite;
                }
                .twi-caller-name {
                    color: #fff;
                    font-size: 24px;
                    font-weight: 600;
                    margin-bottom: 8px;
                }
                .twi-caller-name a {
                    color: #fff;
                    text-decoration: none;
                }
                .twi-caller-name a:hover {
                    color: #4ecca3;
                }
                .twi-caller-phone {
                    color: rgba(255, 255, 255, 0.6);
                    font-size: 16px;
                    margin-bottom: 8px;
                }
                .twi-caller-meta {
                    color: rgba(255, 255, 255, 0.4);
                    font-size: 13px;
                    margin-bottom: 30px;
                }
                .twi-call-actions {
                    display: flex;
                    justify-content: center;
                    gap: 40px;
                }
                .twi-action-btn {
                    width: 70px;
                    height: 70px;
                    border-radius: 50%;
                    border: none;
                    cursor: pointer;
                    font-size: 28px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: all 0.2s ease;
                }
                .twi-action-btn:hover {
                    transform: scale(1.1);
                }
                .twi-btn-accept {
                    background: linear-gradient(135deg, #28a745, #20c997);
                    color: white;
                    box-shadow: 0 8px 24px rgba(40, 167, 69, 0.4);
                }
                .twi-btn-reject {
                    background: linear-gradient(135deg, #dc3545, #c82333);
                    color: white;
                    box-shadow: 0 8px 24px rgba(220, 53, 69, 0.4);
                }
                .twi-action-label {
                    color: rgba(255, 255, 255, 0.5);
                    font-size: 12px;
                    margin-top: 8px;
                }
            </style>
            <div class="twi-modal-content">
                <div class="twi-incoming-label">
                    <span class="phone-icon">📞</span>
                    INCOMING CALL
                </div>
                <div class="twi-avatar">👤</div>
                <div class="twi-caller-name">
                    ${recordLink
                        ? `<a href="${recordLink}" target="_blank" title="Open ${callerModule}">${escapeHtml(callerName)}</a>`
                        : escapeHtml(callerName)
                    }
                </div>
                <div class="twi-caller-phone">${escapeHtml(formatPhoneNumber(phoneNumber))}</div>
                <div class="twi-caller-meta">
                    ${callerModule ? `${callerModule}` : 'New Caller'}
                    ${funnelType ? ` - ${funnelType.replace('_', ' ')}` : ''}
                </div>
                <div class="twi-call-actions">
                    <div>
                        <button class="twi-action-btn twi-btn-reject" onclick="window.PowerPackTwilio.rejectCall()" title="Reject">
                            ✕
                        </button>
                        <div class="twi-action-label">Decline</div>
                    </div>
                    <div>
                        <button class="twi-action-btn twi-btn-accept" onclick="window.PowerPackTwilio.acceptCall()" title="Accept">
                            ✓
                        </button>
                        <div class="twi-action-label">Accept</div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
    }

    function showActiveCallUI() {
        const modal = document.getElementById('twilio-incoming-modal');
        if (!modal) return;

        const callerName = callerInfo?.name || 'Unknown Caller';
        const phoneNumber = incomingCall?.parameters?.From || '';

        modal.querySelector('.twi-modal-content').innerHTML = `
            <div class="twi-incoming-label" style="animation: none;">
                <span>🔊</span>
                CALL IN PROGRESS
            </div>
            <div class="twi-avatar" style="animation: none; background: linear-gradient(135deg, #4a90d9, #357abd);">
                👤
            </div>
            <div class="twi-caller-name">${escapeHtml(callerName)}</div>
            <div class="twi-caller-phone">${escapeHtml(formatPhoneNumber(phoneNumber))}</div>
            <div id="twilio-call-timer" style="font-size: 48px; color: #fff; font-weight: 300; margin: 30px 0; font-family: 'SF Mono', Monaco, monospace;">
                00:00
            </div>
            <div class="twi-call-actions">
                <div>
                    <button class="twi-action-btn twi-btn-reject" onclick="window.PowerPackTwilio.endCall()" title="End Call">
                        📵
                    </button>
                    <div class="twi-action-label">End Call</div>
                </div>
            </div>
        `;
    }

    function dismissIncomingModal() {
        stopRinging();
        clearTimeout(ringTimeout);
        incomingCall = null;
        hideCallUI();
    }

    function hideCallUI() {
        const modal = document.getElementById('twilio-incoming-modal');
        if (modal) {
            modal.remove();
        }
    }

    // =========================================================================
    // Helper Functions
    // =========================================================================

    function formatPhoneNumber(phone) {
        if (!phone) return '';

        // Remove non-digits
        const digits = phone.replace(/\D/g, '');

        // Format US numbers
        if (digits.length === 11 && digits[0] === '1') {
            return `+1 (${digits.slice(1, 4)}) ${digits.slice(4, 7)}-${digits.slice(7)}`;
        }
        if (digits.length === 10) {
            return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
        }

        return phone;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // =========================================================================
    // Initialization
    // =========================================================================

    function init() {
        // Delay initialization to let page load
        setTimeout(() => {
            initTwilioDevice();
        }, 3000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // =========================================================================
    // SMS Functions
    // =========================================================================

    async function sendSms(to, body, leadId = null) {
        try {
            const params = new URLSearchParams({
                to: to,
                body: body
            });
            if (leadId) {
                params.append('lead_id', leadId);
            }

            const response = await fetch('legacy/twilio_webhook.php?action=send_sms', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: params.toString()
            });

            const result = await response.json();

            if (result.success) {
                console.log('[TwilioIncoming] SMS sent successfully:', result.message_sid);
                showSmsNotification('SMS sent successfully!', 'success');
            } else {
                console.error('[TwilioIncoming] SMS failed:', result.error);
                showSmsNotification('Failed to send SMS: ' + result.error, 'error');
            }

            return result;
        } catch (error) {
            console.error('[TwilioIncoming] SMS error:', error);
            showSmsNotification('Error sending SMS', 'error');
            return { success: false, error: error.message };
        }
    }

    function showSmsNotification(message, type = 'info') {
        // Create a simple toast notification
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 100001;
            animation: slideIn 0.3s ease;
            background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        `;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Add CSS for toast animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);

    // Expose functions for UI buttons
    window.PowerPackTwilio = {
        acceptCall: acceptCall,
        rejectCall: rejectCall,
        endCall: endCall,
        sendSms: sendSms,
        getDevice: () => device,
        isConnected: () => device?.state === 'registered'
    };

    console.log('[TwilioIncoming] Script loaded - Call & SMS ready');
})();
