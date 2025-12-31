<?php
/**
 * Twilio Send SMS View
 * Handles outbound SMS via Twilio
 */
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class TwilioIntegrationViewSendsms extends SugarView {
    
    public function display() {
        global $current_user, $sugar_config;
        
        $this->options['show_header'] = false;
        $this->options['show_footer'] = false;
        
        $phone = isset($_REQUEST['phone']) ? htmlspecialchars($_REQUEST['phone']) : '';
        $action_type = isset($_REQUEST['action_type']) ? $_REQUEST['action_type'] : '';
        
        // Handle AJAX API calls
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action_type = isset($_POST['action_type']) ? $_POST['action_type'] : $action_type;
            
            switch ($action_type) {
                case 'send':
                    $this->sendSMS();
                    return;
                case 'status':
                    $this->getMessageStatus();
                    return;
                case 'templates':
                    $this->getTemplates();
                    return;
            }
        }
        
        // Display SMS UI
        $this->displaySMSUI($phone);
    }
    
    private function displaySMSUI($phone) {
        global $sugar_config;
        
        $config = $this->getTwilioConfig();
        $twilioFrom = $config['phone_number'];
        
        echo '<!DOCTYPE html>
<html>
<head>
    <title>Send SMS</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); 
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center;
            padding: 20px;
        }
        .sms-container { 
            max-width: 450px; 
            width: 100%;
            background: rgba(255,255,255,0.05); 
            backdrop-filter: blur(10px);
            padding: 30px; 
            border-radius: 24px; 
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .header { 
            text-align: center; 
            margin-bottom: 24px;
        }
        .header h1 {
            color: #fff;
            font-size: 18px;
            font-weight: 500;
            letter-spacing: 1px;
        }
        .recipient-display { 
            background: rgba(0,0,0,0.3); 
            padding: 16px 20px; 
            border-radius: 12px; 
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .recipient-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4a90d9, #357abd);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .recipient-info {
            flex: 1;
        }
        .recipient-number { 
            color: #fff; 
            font-size: 16px; 
            font-weight: 600;
        }
        .from-info { 
            color: rgba(255,255,255,0.5); 
            font-size: 12px;
            margin-top: 2px;
        }
        .form-group { 
            margin-bottom: 16px; 
        }
        .form-group label { 
            color: rgba(255,255,255,0.7); 
            font-size: 12px; 
            display: block; 
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-group input { 
            width: 100%; 
            padding: 14px 16px; 
            border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 12px; 
            background: rgba(0,0,0,0.2); 
            color: #fff; 
            font-size: 16px;
            transition: all 0.2s;
        }
        .form-group input:focus { 
            outline: none;
            border-color: #4a90d9;
            background: rgba(0,0,0,0.3);
        }
        .form-group textarea { 
            width: 100%; 
            padding: 14px 16px; 
            border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 12px; 
            background: rgba(0,0,0,0.2); 
            color: #fff; 
            font-size: 16px;
            transition: all 0.2s;
            resize: vertical;
            min-height: 120px;
            font-family: inherit;
        }
        .form-group textarea:focus { 
            outline: none;
            border-color: #4a90d9;
            background: rgba(0,0,0,0.3);
        }
        .char-count {
            text-align: right;
            color: rgba(255,255,255,0.4);
            font-size: 12px;
            margin-top: 4px;
        }
        .char-count.warning { color: #ffc107; }
        .char-count.danger { color: #dc3545; }
        
        .templates-section {
            margin-bottom: 16px;
        }
        .templates-section label {
            color: rgba(255,255,255,0.7); 
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: block;
        }
        .template-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .template-chip {
            padding: 8px 12px;
            background: rgba(255,255,255,0.1);
            border: none;
            border-radius: 20px;
            color: rgba(255,255,255,0.8);
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .template-chip:hover {
            background: rgba(74,144,217,0.3);
            color: #fff;
        }
        
        .btn { 
            width: 100%; 
            padding: 16px; 
            font-size: 16px; 
            font-weight: 600;
            border: none; 
            border-radius: 12px; 
            cursor: pointer; 
            margin-top: 8px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-send { 
            background: linear-gradient(135deg, #4a90d9, #357abd); 
            color: white;
        }
        .btn-send:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 24px rgba(74,144,217,0.4);
        }
        .btn-send:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .btn-cancel { 
            background: rgba(255,255,255,0.1); 
            color: rgba(255,255,255,0.7);
        }
        .btn-cancel:hover { 
            background: rgba(255,255,255,0.15);
        }
        
        .status-msg { 
            padding: 12px 16px; 
            border-radius: 8px; 
            margin-top: 16px;
            font-size: 14px;
            text-align: center;
        }
        .status-msg.error { background: rgba(220,53,69,0.2); color: #ff6b6b; }
        .status-msg.success { background: rgba(40,167,69,0.2); color: #51cf66; }
        .status-msg.info { background: rgba(74,144,217,0.2); color: #74c0fc; }
        .status-msg.hidden { display: none; }
        
        .success-screen {
            text-align: center;
            display: none;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #28a745, #20c997);
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }
        .success-title {
            color: #fff;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .success-detail {
            color: rgba(255,255,255,0.6);
            font-size: 14px;
            margin-bottom: 24px;
        }
    </style>
</head>
<body>
    <div class="sms-container">
        <div id="composeScreen">
            <div class="header">
                <h1>💬 SEND SMS</h1>
            </div>
            
            <div class="recipient-display">
                <div class="recipient-avatar">👤</div>
                <div class="recipient-info">
                    <div class="recipient-number" id="displayPhone">' . htmlspecialchars($phone) . '</div>
                    <div class="from-info">From: ' . htmlspecialchars($twilioFrom) . '</div>
                </div>
            </div>
            
            <div class="form-group">
                <label>To</label>
                <input type="tel" id="toNumber" value="' . htmlspecialchars($phone) . '" placeholder="Enter phone number">
            </div>
            
            <div class="templates-section">
                <label>Quick Templates</label>
                <div class="template-chips">
                    <button type="button" class="template-chip" data-msg="Hi! Just following up on our conversation. Let me know if you have any questions.">Follow-up</button>
                    <button type="button" class="template-chip" data-msg="Thank you for your interest! I\'ll be in touch shortly.">Thanks</button>
                    <button type="button" class="template-chip" data-msg="Hi! This is a reminder about our scheduled call. Looking forward to speaking with you!">Reminder</button>
                    <button type="button" class="template-chip" data-msg="Hi! Just checking in to see how everything is going. Let me know if you need anything.">Check-in</button>
                </div>
            </div>
            
            <div class="form-group">
                <label>Message</label>
                <textarea id="message" placeholder="Type your message..." maxlength="1600"></textarea>
                <div class="char-count" id="charCount">0 / 160</div>
            </div>
            
            <input type="hidden" id="fromNumber" value="' . htmlspecialchars($twilioFrom) . '">
            
            <button class="btn btn-send" id="sendBtn">
                <span>📤</span> Send Message
            </button>
            <button class="btn btn-cancel" onclick="window.close()">Cancel</button>
            <div class="status-msg hidden" id="status"></div>
        </div>
        
        <div id="successScreen" class="success-screen">
            <div class="success-icon">✓</div>
            <div class="success-title">Message Sent!</div>
            <div class="success-detail" id="successDetail">Your SMS has been delivered.</div>
            <button class="btn btn-send" id="sendAnotherBtn">Send Another</button>
            <button class="btn btn-cancel" onclick="window.close()">Close</button>
        </div>
    </div>

    <script>
    (function() {
        var els = {
            composeScreen: document.getElementById("composeScreen"),
            successScreen: document.getElementById("successScreen"),
            toNumber: document.getElementById("toNumber"),
            fromNumber: document.getElementById("fromNumber"),
            message: document.getElementById("message"),
            charCount: document.getElementById("charCount"),
            status: document.getElementById("status"),
            displayPhone: document.getElementById("displayPhone"),
            sendBtn: document.getElementById("sendBtn"),
            successDetail: document.getElementById("successDetail")
        };
        
        // Update display when phone changes
        els.toNumber.addEventListener("input", function() {
            els.displayPhone.textContent = this.value || "Enter number";
        });
        
        // Character count
        els.message.addEventListener("input", function() {
            var len = this.value.length;
            var segments = Math.ceil(len / 160) || 1;
            els.charCount.textContent = len + " / " + (segments * 160) + " (" + segments + " segment" + (segments > 1 ? "s" : "") + ")";
            els.charCount.className = "char-count";
            if (len > 160) els.charCount.classList.add("warning");
            if (len > 320) els.charCount.classList.add("danger");
        });
        
        // Template chips
        document.querySelectorAll(".template-chip").forEach(function(chip) {
            chip.addEventListener("click", function() {
                els.message.value = this.getAttribute("data-msg");
                els.message.dispatchEvent(new Event("input"));
                els.message.focus();
            });
        });
        
        // Send button
        els.sendBtn.addEventListener("click", sendMessage);
        
        // Send another
        document.getElementById("sendAnotherBtn").addEventListener("click", function() {
            els.successScreen.style.display = "none";
            els.composeScreen.style.display = "block";
            els.message.value = "";
            els.message.dispatchEvent(new Event("input"));
        });
        
        function sendMessage() {
            var to = els.toNumber.value.trim();
            var from = els.fromNumber.value.trim();
            var message = els.message.value.trim();
            
            if (!to) {
                showStatus("error", "Please enter a phone number");
                return;
            }
            if (!message) {
                showStatus("error", "Please enter a message");
                return;
            }
            
            els.sendBtn.disabled = true;
            els.sendBtn.innerHTML = "<span>⏳</span> Sending...";
            showStatus("info", "Sending message...");
            
            var formData = new FormData();
            formData.append("action_type", "send");
            formData.append("to", to);
            formData.append("from", from);
            formData.append("message", message);
            
            fetch("index.php?module=TwilioIntegration&action=sendsms", {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            })
            .then(function(r) { 
                if (!r.ok) throw new Error("HTTP " + r.status);
                return r.text();
            })
            .then(function(text) {
                try {
                    return JSON.parse(text);
                } catch(e) {
                    console.error("Response:", text.substring(0, 200));
                    throw new Error("Server returned invalid response");
                }
            })
            .then(function(data) {
                els.sendBtn.disabled = false;
                els.sendBtn.innerHTML = "<span>📤</span> Send Message";
                
                if (data.success) {
                    els.successDetail.textContent = "SMS sent to " + to;
                    els.composeScreen.style.display = "none";
                    els.successScreen.style.display = "block";
                } else {
                    showStatus("error", data.error || "Failed to send message");
                }
            })
            .catch(function(err) {
                els.sendBtn.disabled = false;
                els.sendBtn.innerHTML = "<span>📤</span> Send Message";
                showStatus("error", "Error: " + err.message);
            });
        }
        
        function showStatus(type, message) {
            els.status.className = "status-msg " + type;
            els.status.textContent = message;
            if (type !== "error") {
                setTimeout(function() {
                    els.status.className = "status-msg hidden";
                }, 5000);
            }
        }
    })();
    </script>
</body>
</html>';
        exit;
    }
    
    private function getTwilioConfig() {
        global $sugar_config, $current_user;

        // Try to get config from twilio_integration table for current user
        if (!empty($current_user->id)) {
            $db = DBManagerFactory::getInstance();
            $sql = "SELECT account_sid, auth_token, phone_number
                    FROM twilio_integration
                    WHERE deleted = 0
                    AND assigned_user_id = '" . $db->quote($current_user->id) . "'
                    ORDER BY date_modified DESC
                    LIMIT 1";

            $result = $db->query($sql);
            $row = $db->fetchByAssoc($result);

            if ($row && !empty($row['account_sid']) && !empty($row['phone_number'])) {
                $GLOBALS['log']->info("Twilio SMS Config - Using user config for user {$current_user->id}: " . $row['phone_number']);
                return array(
                    'account_sid' => $row['account_sid'],
                    'auth_token' => $row['auth_token'],
                    'phone_number' => $row['phone_number'],
                );
            }
        }

        // Fall back to environment variables / sugar_config
        $GLOBALS['log']->info("Twilio SMS Config - Using default config from env/config");
        return array(
            'account_sid' => getenv('TWILIO_ACCOUNT_SID') ?: ($sugar_config['twilio_account_sid'] ?? ''),
            'auth_token' => getenv('TWILIO_AUTH_TOKEN') ?: ($sugar_config['twilio_auth_token'] ?? ''),
            'phone_number' => getenv('TWILIO_PHONE_NUMBER') ?: ($sugar_config['twilio_phone_number'] ?? ''),
        );
    }
    
    private function sendSMS() {
        header('Content-Type: application/json');

        $to = $_POST['to'] ?? '';
        $from = $_POST['from'] ?? '';
        $message = $_POST['message'] ?? '';
        $parentType = $_POST['parent_type'] ?? '';
        $parentId = $_POST['parent_id'] ?? '';

        if (empty($to) || empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Phone number and message are required']);
            exit;
        }

        $config = $this->getTwilioConfig();

        if (empty($config['account_sid']) || empty($config['auth_token'])) {
            echo json_encode(['success' => false, 'error' => 'Twilio is not configured']);
            exit;
        }

        if (empty($from)) {
            $from = $config['phone_number'];
        }

        try {
            global $sugar_config;
            // Use APP_URL env var for public webhook URLs (ngrok/production), fallback to site_url
            $siteUrl = getenv('APP_URL') ?: rtrim($sugar_config['site_url'] ?? '', '/');
            $siteUrl = rtrim($siteUrl, '/');
            $statusCallback = $siteUrl . '/legacy/twilio_webhook.php?action=sms';

            $url = "https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Messages.json";

            $data = [
                'To' => $to,
                'From' => $from,
                'Body' => $message,
                'StatusCallback' => $statusCallback
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $config['account_sid'] . ':' . $config['auth_token'],
                CURLOPT_TIMEOUT => 30
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                echo json_encode(['success' => false, 'error' => 'Connection error: ' . $error]);
                exit;
            }

            $result = json_decode($response, true);

            if ($httpCode >= 200 && $httpCode < 300 && isset($result['sid'])) {
                // Log the SMS - use provided parent if available
                $this->logSMSSent($result['sid'], $from, $to, $message, $result['status'] ?? 'sent', $parentType, $parentId);

                echo json_encode([
                    'success' => true,
                    'message_sid' => $result['sid'],
                    'status' => $result['status'] ?? 'sent'
                ]);
            } else {
                $errorMsg = $result['message'] ?? $result['error_message'] ?? 'Failed to send SMS';
                echo json_encode(['success' => false, 'error' => $errorMsg]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    private function getMessageStatus() {
        header('Content-Type: application/json');
        
        $messageSid = $_POST['message_sid'] ?? '';
        
        if (empty($messageSid)) {
            echo json_encode(['success' => false, 'error' => 'Message SID is required']);
            exit;
        }
        
        $config = $this->getTwilioConfig();
        
        try {
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Messages/{$messageSid}.json";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $config['account_sid'] . ':' . $config['auth_token'],
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                echo json_encode([
                    'success' => true,
                    'status' => $result['status'] ?? 'unknown'
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to get status']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    private function getTemplates() {
        header('Content-Type: application/json');
        
        // Return default templates - could be expanded to load from database
        $templates = [
            ['id' => 'followup', 'name' => 'Follow-up', 'message' => "Hi! Just following up on our conversation. Let me know if you have any questions."],
            ['id' => 'thanks', 'name' => 'Thanks', 'message' => "Thank you for your interest! I'll be in touch shortly."],
            ['id' => 'reminder', 'name' => 'Reminder', 'message' => "Hi! This is a reminder about our scheduled call. Looking forward to speaking with you!"],
            ['id' => 'checkin', 'name' => 'Check-in', 'message' => "Hi! Just checking in to see how everything is going. Let me know if you need anything."],
        ];
        
        echo json_encode(['success' => true, 'templates' => $templates]);
        exit;
    }
    
    private function logSMSSent($messageSid, $from, $to, $message, $status, $parentType = '', $parentId = '') {
        global $current_user;

        $GLOBALS['log']->info("Twilio: SMS sent - SID: $messageSid, From: $from, To: $to, User: " . ($current_user->id ?? 'unknown'));

        // Use provided parent if available, otherwise try to find by phone
        if (!empty($parentType) && !empty($parentId)) {
            $contact = [
                'module' => $parentType,
                'id' => $parentId
            ];
        } else {
            $contact = $this->findContactByPhone($to);
        }

        if ($contact) {
            try {
                $note = BeanFactory::newBean('Notes');
                $note->name = "SMS to " . $to;
                $note->description = $message . "\n\n---\nTwilio Message SID: " . $messageSid . "\nStatus: " . $status;
                $note->parent_type = $contact['module'];
                $note->parent_id = $contact['id'];
                $note->assigned_user_id = $current_user->id ?? '';
                $note->save();
            } catch (Exception $e) {
                $GLOBALS['log']->error("Twilio: Failed to create note for SMS - " . $e->getMessage());
            }
        }
    }
    
    private function findContactByPhone($phone) {
        global $db;
        
        // Clean phone number for comparison
        $cleanPhone = preg_replace('/[^\d]/', '', $phone);
        $patterns = [
            $phone,
            $cleanPhone,
            '+1' . $cleanPhone,
            '(' . substr($cleanPhone, 0, 3) . ') ' . substr($cleanPhone, 3, 3) . '-' . substr($cleanPhone, 6),
        ];
        
        // Search in Contacts
        foreach ($patterns as $p) {
            $escaped = $db->quote($p);
            $sql = "SELECT id, first_name, last_name, assigned_user_id FROM contacts 
                    WHERE deleted = 0 AND (phone_mobile = '$escaped' OR phone_work = '$escaped' OR phone_home = '$escaped')
                    LIMIT 1";
            $result = $db->query($sql);
            if ($row = $db->fetchByAssoc($result)) {
                return [
                    'module' => 'Contacts',
                    'id' => $row['id'],
                    'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                    'assigned_user_id' => $row['assigned_user_id']
                ];
            }
        }
        
        // Search in Leads
        foreach ($patterns as $p) {
            $escaped = $db->quote($p);
            $sql = "SELECT id, first_name, last_name, assigned_user_id FROM leads 
                    WHERE deleted = 0 AND (phone_mobile = '$escaped' OR phone_work = '$escaped' OR phone_home = '$escaped')
                    LIMIT 1";
            $result = $db->query($sql);
            if ($row = $db->fetchByAssoc($result)) {
                return [
                    'module' => 'Leads',
                    'id' => $row['id'],
                    'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                    'assigned_user_id' => $row['assigned_user_id']
                ];
            }
        }
        
        return null;
    }
}
