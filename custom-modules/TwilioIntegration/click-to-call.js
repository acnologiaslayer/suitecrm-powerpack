/**
 * Click-to-call and Click-to-text functionality for SuiteCRM
 */
(function() {
    'use strict';
    
    // Initialize click-to-call and click-to-text on phone fields
    function initClickToCall() {
        const phoneFields = document.querySelectorAll('span[field="phone_work"], span[field="phone_mobile"], span[field="phone_office"]');
        
        phoneFields.forEach(function(field) {
            const phoneNumber = field.textContent.trim();
            if (phoneNumber && phoneNumber !== '') {
                // Remove existing buttons if any
                const existingButtons = field.parentElement.querySelector('.twilio-actions');
                if (existingButtons) {
                    existingButtons.remove();
                }
                
                // Create action buttons container
                const actionButtons = document.createElement('span');
                actionButtons.className = 'twilio-actions';
                actionButtons.style.cssText = 'margin-left: 10px;';
                
                // Call button
                const callButton = document.createElement('button');
                callButton.innerHTML = '📞 Call';
                callButton.className = 'btn btn-xs btn-primary twilio-call-btn';
                callButton.style.cssText = 'margin-right: 5px;';
                callButton.title = 'Click to call ' + phoneNumber;
                callButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    makeCall(phoneNumber);
                });
                
                // SMS button
                const smsButton = document.createElement('button');
                smsButton.innerHTML = '💬 SMS';
                smsButton.className = 'btn btn-xs btn-success twilio-sms-btn';
                smsButton.title = 'Click to send SMS to ' + phoneNumber;
                smsButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    showSMSDialog(phoneNumber);
                });
                
                actionButtons.appendChild(callButton);
                actionButtons.appendChild(smsButton);
                field.parentElement.appendChild(actionButtons);
            }
        });
    }
    
    // Make call via Twilio
    function makeCall(phoneNumber) {
        if (!confirm('Call ' + phoneNumber + '?')) {
            return;
        }
        
        const recordId = getRecordId();
        const moduleName = getModuleName();
        
        fetch('index.php?module=TwilioIntegration&action=makeCall', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                to: phoneNumber,
                record_id: recordId,
                module: moduleName
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Call initiated successfully!', 'success');
            } else {
                showNotification('Failed to initiate call: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showNotification('Error: ' + error.message, 'error');
        });
    }
    
    // Show SMS dialog
    function showSMSDialog(phoneNumber) {
        // Create modal overlay
        const overlay = document.createElement('div');
        overlay.className = 'twilio-sms-overlay';
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10001;
        `;
        
        // Create modal
        const modal = document.createElement('div');
        modal.className = 'twilio-sms-modal';
        modal.style.cssText = `
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
        `;
        
        modal.innerHTML = `
            <h3 style="margin-top: 0; color: #333;">Send SMS to ${phoneNumber}</h3>
            <textarea id="twilio-sms-message" 
                      placeholder="Enter your message..." 
                      style="width: 100%; height: 120px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-family: inherit; resize: vertical;"
                      maxlength="1600"></textarea>
            <div style="margin-top: 10px; color: #666; font-size: 12px;">
                <span id="twilio-char-count">0</span> / 1600 characters
            </div>
            <div style="margin-top: 20px; text-align: right;">
                <button id="twilio-sms-cancel" class="btn btn-default" style="margin-right: 10px;">Cancel</button>
                <button id="twilio-sms-send" class="btn btn-primary">Send SMS</button>
            </div>
        `;
        
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        
        // Focus on textarea
        const textarea = document.getElementById('twilio-sms-message');
        textarea.focus();
        
        // Character counter
        textarea.addEventListener('input', function() {
            document.getElementById('twilio-char-count').textContent = this.value.length;
        });
        
        // Cancel button
        document.getElementById('twilio-sms-cancel').addEventListener('click', function() {
            overlay.remove();
        });
        
        // Close on overlay click
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                overlay.remove();
            }
        });
        
        // Send button
        document.getElementById('twilio-sms-send').addEventListener('click', function() {
            const message = textarea.value.trim();
            if (!message) {
                showNotification('Please enter a message', 'error');
                return;
            }
            
            sendSMS(phoneNumber, message);
            overlay.remove();
        });
        
        // Send on Ctrl+Enter
        textarea.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'Enter') {
                document.getElementById('twilio-sms-send').click();
            }
        });
    }
    
    // Send SMS via Twilio
    function sendSMS(phoneNumber, message) {
        const recordId = getRecordId();
        const moduleName = getModuleName();
        
        showNotification('Sending SMS...', 'info');
        
        fetch('index.php?module=TwilioIntegration&action=sendSMS', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                to: phoneNumber,
                message: message,
                record_id: recordId,
                module: moduleName
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('SMS sent successfully!', 'success');
            } else {
                showNotification('Failed to send SMS: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showNotification('Error: ' + error.message, 'error');
        });
    }
    
    // Get current record ID
    function getRecordId() {
        const match = window.location.search.match(/record=([^&]+)/);
        return match ? match[1] : null;
    }
    
    // Get current module name
    function getModuleName() {
        const match = window.location.search.match(/module=([^&]+)/);
        return match ? match[1] : null;
    }
    
    // Show notification
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = 'twilio-notification twilio-notification-' + type;
        notification.textContent = message;
        
        let bgColor = '#4caf50'; // success
        if (type === 'error') bgColor = '#f44336';
        if (type === 'info') bgColor = '#2196F3';
        
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background: ${bgColor};
            color: white;
            border-radius: 4px;
            z-index: 10000;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(function() {
            notification.remove();
        }, 3000);
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initClickToCall);
    } else {
        initClickToCall();
    }
    
    // Re-initialize on AJAX content updates
    const observer = new MutationObserver(function(mutations) {
        initClickToCall();
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
})();
