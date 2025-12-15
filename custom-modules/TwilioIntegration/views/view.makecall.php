<?php
/**
 * Twilio Make Call View
 * Handles outbound calls via Twilio
 */
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class TwilioIntegrationViewMakecall extends SugarView {
    
    public function display() {
        global $current_user, $sugar_config;
        
        // Disable SuiteCRM template for API calls
        $this->options['show_header'] = false;
        $this->options['show_footer'] = false;
        
        $phone = isset($_REQUEST['phone']) ? htmlspecialchars($_REQUEST['phone']) : '';
        $action_type = isset($_REQUEST['action_type']) ? $_REQUEST['action_type'] : '';
        
        // Handle AJAX API calls
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action_type = isset($_POST['action_type']) ? $_POST['action_type'] : $action_type;
            
            switch ($action_type) {
                case 'call':
                    $this->initiateCall();
                    return;
                case 'end_call':
                    $this->endCall();
                    return;
                case 'call_status':
                    $this->getCallStatus();
                    return;
            }
        }
        
        // Display call UI
        $this->displayCallUI($phone);
    }
    
    private function displayCallUI($phone) {
        global $sugar_config;
        
        $config = $this->getTwilioConfig();
        $twilioFrom = $config['phone_number'];
        
        echo '<!DOCTYPE html>
<html>
<head>
    <title>Twilio Call</title>
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
        .call-container { 
            max-width: 400px; 
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
        .phone-display { 
            background: rgba(0,0,0,0.3); 
            padding: 24px; 
            border-radius: 16px; 
            margin-bottom: 24px;
            text-align: center;
        }
        .phone-number { 
            color: #fff; 
            font-size: 28px; 
            font-weight: 600; 
            letter-spacing: 1px;
        }
        .from-info { 
            color: rgba(255,255,255,0.5); 
            font-size: 13px; 
            margin-top: 8px;
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
        .btn-call { 
            background: linear-gradient(135deg, #28a745, #20c997); 
            color: white;
        }
        .btn-call:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 24px rgba(40,167,69,0.4);
        }
        .btn-cancel { 
            background: rgba(255,255,255,0.1); 
            color: rgba(255,255,255,0.7);
        }
        .btn-cancel:hover { 
            background: rgba(255,255,255,0.15);
        }
        
        #callScreen { 
            display: none; 
            text-align: center;
        }
        .call-status { 
            font-size: 14px; 
            text-transform: uppercase; 
            letter-spacing: 2px;
            margin-bottom: 8px;
        }
        .status-connecting { color: #ffc107; }
        .status-ringing { color: #ffc107; animation: pulse 1.5s infinite; }
        .status-connected { color: #28a745; }
        .status-ended { color: #dc3545; }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .avatar { 
            width: 100px; 
            height: 100px; 
            background: linear-gradient(135deg, #4a90d9, #357abd); 
            border-radius: 50%; 
            margin: 24px auto;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            box-shadow: 0 8px 32px rgba(74,144,217,0.3);
        }
        .caller-name { 
            color: #fff; 
            font-size: 22px; 
            font-weight: 600;
            margin-bottom: 4px;
        }
        .caller-number { 
            color: rgba(255,255,255,0.5); 
            font-size: 14px;
        }
        .timer { 
            font-size: 48px; 
            color: #fff; 
            font-weight: 300; 
            margin: 32px 0;
            font-family: "SF Mono", Monaco, monospace;
        }
        .call-actions { 
            display: flex; 
            justify-content: center; 
            gap: 16px;
            margin-top: 24px;
        }
        .action-btn { 
            width: 64px; 
            height: 64px; 
            border-radius: 50%; 
            border: none; 
            cursor: pointer;
            font-size: 24px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .action-btn:hover { transform: scale(1.1); }
        .btn-mute { background: rgba(255,255,255,0.1); color: #fff; }
        .btn-mute.active { background: #4a90d9; }
        .btn-end { 
            background: linear-gradient(135deg, #dc3545, #c82333); 
            color: white;
            width: 72px;
            height: 72px;
            font-size: 28px;
        }
        .btn-end:hover { box-shadow: 0 8px 24px rgba(220,53,69,0.4); }
        .btn-speaker { background: rgba(255,255,255,0.1); color: #fff; }
        .btn-speaker.active { background: #4a90d9; }
        
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
    </style>
</head>
<body>
    <div class="call-container">
        <div id="dialScreen">
            <div class="header">
                <h1>📞 MAKE A CALL</h1>
            </div>
            <div class="phone-display">
                <div class="phone-number" id="displayPhone">' . htmlspecialchars($phone) . '</div>
                <div class="from-info">From: ' . htmlspecialchars($twilioFrom) . '</div>
            </div>
            <div class="form-group">
                <label>Calling</label>
                <input type="tel" id="toNumber" value="' . htmlspecialchars($phone) . '" placeholder="Enter phone number">
            </div>
            <div class="form-group">
                <label>Your Twilio Number</label>
                <input type="tel" id="fromNumber" value="' . htmlspecialchars($twilioFrom) . '" readonly>
            </div>
            <button class="btn btn-call" id="startCallBtn">
                <span>📞</span> Start Call
            </button>
            <button class="btn btn-cancel" onclick="window.close()">Cancel</button>
            <div class="status-msg hidden" id="dialStatus"></div>
        </div>
        
        <div id="callScreen">
            <div class="call-status status-connecting" id="callStatus">Connecting...</div>
            <div class="avatar">📞</div>
            <div class="caller-name" id="callerName">Calling...</div>
            <div class="caller-number" id="callerNumber"></div>
            <div class="timer" id="timer">00:00</div>
            <div class="call-actions">
                <button class="action-btn btn-mute" id="muteBtn" title="Mute">🔇</button>
                <button class="action-btn btn-end" id="endBtn" title="End Call">📵</button>
                <button class="action-btn btn-speaker" id="speakerBtn" title="Speaker">🔊</button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var state = {
            callSid: null,
            timerInterval: null,
            statusInterval: null,
            startTime: null
        };
        
        var els = {
            dialScreen: document.getElementById("dialScreen"),
            callScreen: document.getElementById("callScreen"),
            dialStatus: document.getElementById("dialStatus"),
            callStatus: document.getElementById("callStatus"),
            callerName: document.getElementById("callerName"),
            callerNumber: document.getElementById("callerNumber"),
            timer: document.getElementById("timer"),
            toNumber: document.getElementById("toNumber"),
            fromNumber: document.getElementById("fromNumber"),
            displayPhone: document.getElementById("displayPhone")
        };
        
        els.toNumber.addEventListener("input", function() {
            els.displayPhone.textContent = this.value || "Enter number";
        });
        
        document.getElementById("startCallBtn").addEventListener("click", startCall);
        document.getElementById("endBtn").addEventListener("click", endCall);
        document.getElementById("muteBtn").addEventListener("click", function() {
            this.classList.toggle("active");
            this.textContent = this.classList.contains("active") ? "🔈" : "🔇";
        });
        document.getElementById("speakerBtn").addEventListener("click", function() {
            this.classList.toggle("active");
        });
        
        function startCall() {
            var to = els.toNumber.value.trim();
            var from = els.fromNumber.value.trim();
            
            if (!to) {
                showStatus("error", "Please enter a phone number");
                return;
            }
            
            showStatus("info", "Initiating call...");
            
            var formData = new FormData();
            formData.append("action_type", "call");
            formData.append("to", to);
            formData.append("from", from);
            
            fetch("index.php?module=TwilioIntegration&action=makecall", {
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
                if (data.success) {
                    state.callSid = data.call_sid;
                    showCallScreen(to);
                } else {
                    showStatus("error", data.error || "Failed to initiate call");
                }
            })
            .catch(function(err) {
                showStatus("error", "Error: " + err.message);
            });
        }
        
        function showCallScreen(phone) {
            els.dialScreen.style.display = "none";
            els.callScreen.style.display = "block";
            els.callerNumber.textContent = phone;
            els.callerName.textContent = "Calling...";
            updateCallStatus("connecting");
            startTimer();
            startStatusPolling();
        }
        
        function showDialScreen() {
            els.callScreen.style.display = "none";
            els.dialScreen.style.display = "block";
            stopTimer();
            stopStatusPolling();
            state.callSid = null;
        }
        
        function startTimer() {
            state.startTime = Date.now();
            state.timerInterval = setInterval(function() {
                var secs = Math.floor((Date.now() - state.startTime) / 1000);
                var m = Math.floor(secs / 60);
                var s = secs % 60;
                els.timer.textContent = (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s;
            }, 1000);
        }
        
        function stopTimer() {
            if (state.timerInterval) {
                clearInterval(state.timerInterval);
                state.timerInterval = null;
            }
        }
        
        function startStatusPolling() {
            state.statusInterval = setInterval(checkStatus, 2000);
        }
        
        function stopStatusPolling() {
            if (state.statusInterval) {
                clearInterval(state.statusInterval);
                state.statusInterval = null;
            }
        }
        
        function checkStatus() {
            if (!state.callSid) return;
            
            var formData = new FormData();
            formData.append("action_type", "call_status");
            formData.append("call_sid", state.callSid);
            
            fetch("index.php?module=TwilioIntegration&action=makecall", {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    updateCallStatus(data.status);
                }
            })
            .catch(function() {});
        }
        
        function updateCallStatus(status) {
            var statusEl = els.callStatus;
            statusEl.className = "call-status";
            
            switch(status) {
                case "queued":
                case "initiated":
                case "connecting":
                    statusEl.textContent = "Connecting...";
                    statusEl.classList.add("status-connecting");
                    els.callerName.textContent = "Connecting...";
                    break;
                case "ringing":
                    statusEl.textContent = "Ringing...";
                    statusEl.classList.add("status-ringing");
                    els.callerName.textContent = "Ringing...";
                    break;
                case "in-progress":
                case "answered":
                    statusEl.textContent = "Connected";
                    statusEl.classList.add("status-connected");
                    els.callerName.textContent = "On Call";
                    break;
                case "completed":
                case "busy":
                case "no-answer":
                case "canceled":
                case "failed":
                    statusEl.textContent = status.replace("-", " ").toUpperCase();
                    statusEl.classList.add("status-ended");
                    els.callerName.textContent = "Call Ended";
                    stopTimer();
                    stopStatusPolling();
                    setTimeout(function() {
                        showDialScreen();
                        showStatus("info", "Call " + status.replace("-", " "));
                    }, 2000);
                    break;
            }
        }
        
        function endCall() {
            if (!state.callSid) {
                showDialScreen();
                return;
            }
            
            els.callStatus.textContent = "Ending...";
            
            var formData = new FormData();
            formData.append("action_type", "end_call");
            formData.append("call_sid", state.callSid);
            
            fetch("index.php?module=TwilioIntegration&action=makecall", {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                updateCallStatus("completed");
            })
            .catch(function() {
                showDialScreen();
            });
        }
        
        function showStatus(type, message) {
            els.dialStatus.className = "status-msg " + type;
            els.dialStatus.textContent = message;
            if (type !== "error") {
                setTimeout(function() {
                    els.dialStatus.className = "status-msg hidden";
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
        global $sugar_config;
        return array(
            'account_sid' => getenv('TWILIO_ACCOUNT_SID') ?: ($sugar_config['twilio_account_sid'] ?? ''),
            'auth_token' => getenv('TWILIO_AUTH_TOKEN') ?: ($sugar_config['twilio_auth_token'] ?? ''),
            'phone_number' => getenv('TWILIO_PHONE_NUMBER') ?: ($sugar_config['twilio_phone_number'] ?? ''),
        );
    }
    
    private function initiateCall() {
        header('Content-Type: application/json');
        
        $to = $_POST['to'] ?? '';
        $from = $_POST['from'] ?? '';
        
        if (empty($to) || empty($from)) {
            echo json_encode(['success' => false, 'error' => 'Phone numbers are required']);
            exit;
        }
        
        $config = $this->getTwilioConfig();
        
        if (empty($config['account_sid']) || empty($config['auth_token'])) {
            echo json_encode(['success' => false, 'error' => 'Twilio is not configured']);
            exit;
        }
        
        try {
            global $sugar_config;
            // Use APP_URL env var for public webhook URLs (ngrok/production), fallback to site_url
            $siteUrl = getenv('APP_URL') ?: rtrim($sugar_config['site_url'] ?? '', '/');
            $siteUrl = rtrim($siteUrl, '/');

            // Use direct webhook URLs for Twilio callbacks (bypasses SuiteCRM auth)
            $webhookBase = $siteUrl . '/legacy/modules/TwilioIntegration/twilio_webhook.php';
            $twimlUrl = $webhookBase . '?action=twiml&dial_action=outbound&to=' . urlencode($to);
            $statusCallback = $webhookBase . '?action=status';
            $recordingCallback = $webhookBase . '?action=recording';

            $url = "https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Calls.json";

            $data = [
                'To' => $to,
                'From' => $from,
                'Url' => $twimlUrl,
                'StatusCallback' => $statusCallback,
                'StatusCallbackEvent' => 'initiated ringing answered completed',
                'Record' => 'true',
                'RecordingStatusCallback' => $recordingCallback,
                'RecordingStatusCallbackEvent' => 'completed'
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
                $this->logCallInitiation($result['sid'], $from, $to);
                
                echo json_encode([
                    'success' => true,
                    'call_sid' => $result['sid'],
                    'status' => $result['status'] ?? 'queued'
                ]);
            } else {
                $errorMsg = $result['message'] ?? $result['error_message'] ?? 'Failed to initiate call';
                echo json_encode(['success' => false, 'error' => $errorMsg]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    private function endCall() {
        header('Content-Type: application/json');
        
        $callSid = $_POST['call_sid'] ?? '';
        
        if (empty($callSid)) {
            echo json_encode(['success' => false, 'error' => 'Call SID is required']);
            exit;
        }
        
        $config = $this->getTwilioConfig();
        
        try {
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Calls/{$callSid}.json";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['Status' => 'completed']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $config['account_sid'] . ':' . $config['auth_token'],
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                echo json_encode(['success' => true]);
            } else {
                $result = json_decode($response, true);
                echo json_encode(['success' => false, 'error' => $result['message'] ?? 'Failed to end call']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    private function getCallStatus() {
        header('Content-Type: application/json');
        
        $callSid = $_POST['call_sid'] ?? '';
        
        if (empty($callSid)) {
            echo json_encode(['success' => false, 'error' => 'Call SID is required']);
            exit;
        }
        
        $config = $this->getTwilioConfig();
        
        try {
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Calls/{$callSid}.json";
            
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
                    'status' => $result['status'] ?? 'unknown',
                    'duration' => $result['duration'] ?? 0
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to get status']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    private function logCallInitiation($callSid, $from, $to) {
        global $current_user;
        $GLOBALS['log']->info("Twilio: Call initiated - SID: $callSid, From: $from, To: $to, User: " . ($current_user->id ?? 'unknown'));
    }
}
