<?php
/**
 * Twilio Make Call View - Browser-based calling using Twilio Client SDK
 * The browser becomes a phone using WebRTC
 */
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class TwilioIntegrationViewMakecall extends SugarView {

    public function preDisplay() {
        // Disable SuiteCRM template completely for this view
        $this->options['show_header'] = false;
        $this->options['show_footer'] = false;
        $this->options['show_title'] = false;
        $this->options['show_subpanels'] = false;
    }

    public function display() {
        global $current_user, $sugar_config;

        $phone = isset($_REQUEST['phone']) ? htmlspecialchars($_REQUEST['phone']) : '';
        $action_type = isset($_REQUEST['action_type']) ? $_REQUEST['action_type'] : '';

        // Handle API calls - check both POST and GET for action_type
        if (!empty($_POST['action_type'])) {
            $action_type = $_POST['action_type'];
        }

        // Handle AJAX API requests
        if ($action_type === 'get_token') {
            $this->getAccessToken();
            return;
        }
        if ($action_type === 'end_call') {
            $this->endCall();
            return;
        }

        $this->displayCallUI($phone);
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
                $GLOBALS['log']->info("Twilio Call Config - Using user config for user {$current_user->id}: " . $row['phone_number']);
                return array(
                    'account_sid' => $row['account_sid'],
                    'auth_token' => $row['auth_token'],
                    'phone_number' => $row['phone_number'],
                    'twiml_app_sid' => getenv('TWILIO_TWIML_APP_SID') ?: ($sugar_config['twilio_twiml_app_sid'] ?? ''),
                    'api_key' => getenv('TWILIO_API_KEY') ?: ($sugar_config['twilio_api_key'] ?? ''),
                    'api_secret' => getenv('TWILIO_API_SECRET') ?: ($sugar_config['twilio_api_secret'] ?? ''),
                );
            }
        }

        // Fall back to environment variables / sugar_config
        $GLOBALS['log']->info("Twilio Call Config - Using default config from env/config");
        return array(
            'account_sid' => getenv('TWILIO_ACCOUNT_SID') ?: ($sugar_config['twilio_account_sid'] ?? ''),
            'auth_token' => getenv('TWILIO_AUTH_TOKEN') ?: ($sugar_config['twilio_auth_token'] ?? ''),
            'phone_number' => getenv('TWILIO_PHONE_NUMBER') ?: ($sugar_config['twilio_phone_number'] ?? ''),
            'twiml_app_sid' => getenv('TWILIO_TWIML_APP_SID') ?: ($sugar_config['twilio_twiml_app_sid'] ?? ''),
            'api_key' => getenv('TWILIO_API_KEY') ?: ($sugar_config['twilio_api_key'] ?? ''),
            'api_secret' => getenv('TWILIO_API_SECRET') ?: ($sugar_config['twilio_api_secret'] ?? ''),
        );
    }

    /**
     * Generate Twilio Access Token for browser-based calling
     */
    private function getAccessToken() {
        // Clear any output buffers and send clean JSON
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-cache');

        global $current_user;

        $config = $this->getTwilioConfig();

        if (empty($config['account_sid']) || empty($config['auth_token'])) {
            echo json_encode(['success' => false, 'error' => 'Twilio is not configured']);
            exit;
        }

        // Identity for this client (use user ID or username)
        $identity = 'user_' . ($current_user->id ?? 'anonymous');

        // Check if we have API Key credentials (preferred) or use Account credentials
        $apiKey = $config['api_key'] ?: $config['account_sid'];
        $apiSecret = $config['api_secret'] ?: $config['auth_token'];
        $twimlAppSid = $config['twiml_app_sid'];

        // If no TwiML App SID, we need to create one or use direct outgoing
        if (empty($twimlAppSid)) {
            // Generate token without TwiML App - we'll use the voice grant differently
            $token = $this->generateAccessToken($config['account_sid'], $apiKey, $apiSecret, $identity, null);
        } else {
            $token = $this->generateAccessToken($config['account_sid'], $apiKey, $apiSecret, $identity, $twimlAppSid);
        }

        if ($token) {
            echo json_encode([
                'success' => true,
                'token' => $token,
                'identity' => $identity,
                'caller_id' => $config['phone_number']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to generate token']);
        }
        exit;
    }

    /**
     * Generate JWT Access Token for Twilio Client
     */
    private function generateAccessToken($accountSid, $apiKey, $apiSecret, $identity, $twimlAppSid) {
        // Token expires in 1 hour
        $ttl = 3600;
        $now = time();

        // JWT Header
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
            'cty' => 'twilio-fpa;v=1'
        ];

        // Build grants
        $grants = [
            'identity' => $identity
        ];

        // Voice grant for outgoing calls
        $voiceGrant = [
            'outgoing' => [
                'application_sid' => $twimlAppSid
            ]
        ];

        // If no TwiML App, we'll handle it differently
        if (empty($twimlAppSid)) {
            // Without TwiML App, create a minimal voice grant
            // The actual call will be made via REST API with TwiML URL
            $voiceGrant = [];
        }

        if (!empty($voiceGrant)) {
            $grants['voice'] = $voiceGrant;
        }

        // JWT Payload
        $payload = [
            'jti' => $apiKey . '-' . $now,
            'iss' => $apiKey,
            'sub' => $accountSid,
            'exp' => $now + $ttl,
            'grants' => $grants
        ];

        // Encode JWT
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $apiSecret, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function endCall() {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');

        $callSid = $_POST['call_sid'] ?? '';
        if (empty($callSid)) {
            echo json_encode(['success' => true]); // No call to end
            exit;
        }

        $config = $this->getTwilioConfig();

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Calls/{$callSid}.json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['Status' => 'completed']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $config['account_sid'] . ':' . $config['auth_token'],
            CURLOPT_TIMEOUT => 10
        ]);

        curl_exec($ch);
        curl_close($ch);

        echo json_encode(['success' => true]);
        exit;
    }

    private function displayCallUI($phone) {
        global $sugar_config;

        $config = $this->getTwilioConfig();
        $twilioFrom = $config['phone_number'];
        $hasTwimlApp = !empty($config['twiml_app_sid']);

        // Get webhook base URL for direct dialing fallback
        $siteUrl = getenv('APP_URL') ?: rtrim($sugar_config['site_url'] ?? '', '/');
        $webhookBase = rtrim($siteUrl, '/') . '/legacy/twilio_webhook.php';

        echo '<!DOCTYPE html>
<html>
<head>
    <title>Twilio Call</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://unpkg.com/@twilio/voice-sdk@2.10.2/dist/twilio.min.js"></script>
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
        .header { text-align: center; margin-bottom: 24px; }
        .header h1 { color: #fff; font-size: 18px; font-weight: 500; letter-spacing: 1px; }
        .phone-display {
            background: rgba(0,0,0,0.3); padding: 24px; border-radius: 16px;
            margin-bottom: 24px; text-align: center;
        }
        .phone-number { color: #fff; font-size: 28px; font-weight: 600; letter-spacing: 1px; }
        .from-info { color: rgba(255,255,255,0.5); font-size: 13px; margin-top: 8px; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            color: rgba(255,255,255,0.7); font-size: 12px; display: block;
            margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .form-group input {
            width: 100%; padding: 14px 16px;
            border: 1px solid rgba(255,255,255,0.1); border-radius: 12px;
            background: rgba(0,0,0,0.2); color: #fff; font-size: 16px;
        }
        .form-group input:focus { outline: none; border-color: #4a90d9; }
        .btn {
            width: 100%; padding: 16px; font-size: 16px; font-weight: 600;
            border: none; border-radius: 12px; cursor: pointer; margin-top: 8px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-call { background: linear-gradient(135deg, #28a745, #20c997); color: white; }
        .btn-call:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(40,167,69,0.4); }
        .btn-cancel { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.7); }

        #callScreen { display: none; text-align: center; }
        .call-status { font-size: 14px; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 8px; }
        .status-connecting { color: #ffc107; }
        .status-ringing { color: #ffc107; animation: pulse 1.5s infinite; }
        .status-connected { color: #28a745; }
        .status-ended { color: #dc3545; }

        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        .avatar {
            width: 100px; height: 100px;
            background: linear-gradient(135deg, #4a90d9, #357abd);
            border-radius: 50%; margin: 24px auto;
            display: flex; align-items: center; justify-content: center;
            font-size: 40px; box-shadow: 0 8px 32px rgba(74,144,217,0.3);
        }
        .caller-name { color: #fff; font-size: 22px; font-weight: 600; margin-bottom: 4px; }
        .caller-number { color: rgba(255,255,255,0.5); font-size: 14px; }
        .timer {
            font-size: 48px; color: #fff; font-weight: 300;
            margin: 32px 0; font-family: "SF Mono", Monaco, monospace;
        }
        .call-actions { display: flex; justify-content: center; gap: 16px; margin-top: 24px; }
        .action-btn {
            width: 64px; height: 64px; border-radius: 50%; border: none;
            cursor: pointer; font-size: 24px;
            display: flex; align-items: center; justify-content: center;
        }
        .action-btn:hover { transform: scale(1.1); }
        .btn-mute { background: rgba(255,255,255,0.1); color: #fff; }
        .btn-mute.active { background: #4a90d9; }
        .btn-end {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white; width: 72px; height: 72px; font-size: 28px;
        }

        .status-msg {
            padding: 12px 16px; border-radius: 8px; margin-top: 16px;
            font-size: 14px; text-align: center;
        }
        .status-msg.error { background: rgba(220,53,69,0.2); color: #ff6b6b; }
        .status-msg.success { background: rgba(40,167,69,0.2); color: #51cf66; }
        .status-msg.info { background: rgba(74,144,217,0.2); color: #74c0fc; }
        .status-msg.hidden { display: none; }

        .device-status {
            text-align: center; padding: 8px; margin-bottom: 16px;
            border-radius: 8px; font-size: 12px;
        }
        .device-ready { background: rgba(40,167,69,0.2); color: #51cf66; }
        .device-offline { background: rgba(220,53,69,0.2); color: #ff6b6b; }
        .device-loading { background: rgba(255,193,7,0.2); color: #ffc107; }
    </style>
</head>
<body>
    <div class="call-container">
        <div id="dialScreen">
            <div class="header"><h1>📞 BROWSER PHONE</h1></div>
            <div class="device-status device-loading" id="deviceStatus">Initializing phone...</div>
            <div class="phone-display">
                <div class="phone-number" id="displayPhone">' . htmlspecialchars($phone) . '</div>
                <div class="from-info">Caller ID: ' . htmlspecialchars($twilioFrom) . '</div>
            </div>
            <div class="form-group">
                <label>Number to Call</label>
                <input type="tel" id="toNumber" value="' . htmlspecialchars($phone) . '" placeholder="Enter phone number">
            </div>
            <button class="btn btn-call" id="startCallBtn" disabled>
                <span>📞</span> Call Now
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
            </div>
        </div>
    </div>

    <script>
    (function() {
        var device = null;
        var activeCall = null;
        var timerInterval = null;
        var startTime = null;
        var callerId = "' . htmlspecialchars($twilioFrom) . '";

        var els = {
            dialScreen: document.getElementById("dialScreen"),
            callScreen: document.getElementById("callScreen"),
            dialStatus: document.getElementById("dialStatus"),
            deviceStatus: document.getElementById("deviceStatus"),
            callStatus: document.getElementById("callStatus"),
            callerName: document.getElementById("callerName"),
            callerNumber: document.getElementById("callerNumber"),
            timer: document.getElementById("timer"),
            toNumber: document.getElementById("toNumber"),
            displayPhone: document.getElementById("displayPhone"),
            startCallBtn: document.getElementById("startCallBtn"),
            endBtn: document.getElementById("endBtn"),
            muteBtn: document.getElementById("muteBtn")
        };

        // Initialize Twilio Device (SDK v2.x)
        initDevice();

        async function initDevice() {
            showDeviceStatus("loading", "Connecting to Twilio...");

            try {
                // Get access token from webhook endpoint (bypasses SuiteCRM MVC)
                var response = await fetch("' . $webhookBase . '?action=token", {
                    method: "GET",
                    credentials: "same-origin"
                });
                var data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || "Failed to get token");
                }

                callerId = data.caller_id || callerId;

                // Create Twilio Device (SDK v2.x)
                device = new Twilio.Device(data.token, {
                    codecPreferences: ["opus", "pcmu"],
                    edge: "ashburn",
                    logLevel: 1
                });

                // Register event handlers (SDK v2.x uses different events)
                device.on("registered", function() {
                    console.log("Twilio Device registered");
                    showDeviceStatus("ready", "Phone ready - click Call Now");
                    els.startCallBtn.disabled = false;
                });

                device.on("unregistered", function() {
                    console.log("Twilio Device unregistered");
                    showDeviceStatus("offline", "Phone offline - refresh page");
                    els.startCallBtn.disabled = true;
                });

                device.on("error", function(error) {
                    console.error("Twilio Device error:", error);
                    showDeviceStatus("offline", "Error: " + (error.message || error.code));
                    showStatus("error", error.message || "Device error");
                });

                device.on("tokenWillExpire", async function() {
                    console.log("Token expiring, refreshing...");
                    try {
                        var r = await fetch("' . $webhookBase . '?action=token", {
                            method: "GET",
                            credentials: "same-origin"
                        });
                        var d = await r.json();
                        if (d.success) {
                            device.updateToken(d.token);
                        }
                    } catch (e) {
                        console.error("Token refresh failed:", e);
                    }
                });

                // Register the device
                await device.register();
                console.log("Device registration initiated");

            } catch(err) {
                console.error("Init error:", err);
                showDeviceStatus("offline", "Setup failed: " + err.message);
                showStatus("error", err.message);
            }
        }

        function showDeviceStatus(type, message) {
            els.deviceStatus.className = "device-status device-" + type;
            els.deviceStatus.textContent = message;
        }

        els.toNumber.addEventListener("input", function() {
            els.displayPhone.textContent = this.value || "Enter number";
        });

        els.startCallBtn.addEventListener("click", startCall);
        els.endBtn.addEventListener("click", endCall);
        els.muteBtn.addEventListener("click", toggleMute);

        async function startCall() {
            var to = els.toNumber.value.trim();
            if (!to) {
                showStatus("error", "Please enter a phone number");
                return;
            }

            if (!device) {
                showStatus("error", "Phone not ready. Please refresh.");
                return;
            }

            showCallScreen(to);
            updateCallStatus("connecting");

            try {
                // Make outbound call via Twilio Device (SDK v2.x)
                var params = {
                    params: {
                        To: to,
                        CallerId: callerId
                    }
                };

                activeCall = await device.connect(params);
                console.log("Call initiated:", activeCall);

                activeCall.on("ringing", function(hasEarlyMedia) {
                    console.log("Call ringing, hasEarlyMedia:", hasEarlyMedia);
                    updateCallStatus("ringing");
                });

                activeCall.on("accept", function(call) {
                    console.log("Call accepted");
                    updateCallStatus("connected");
                    startTimer();
                });

                activeCall.on("disconnect", function(call) {
                    console.log("Call disconnected");
                    activeCall = null;
                    updateCallStatus("ended");
                    stopTimer();
                    setTimeout(showDialScreen, 2000);
                });

                activeCall.on("cancel", function() {
                    console.log("Call canceled");
                    activeCall = null;
                    updateCallStatus("canceled");
                    stopTimer();
                    setTimeout(showDialScreen, 2000);
                });

                activeCall.on("reject", function() {
                    console.log("Call rejected");
                    activeCall = null;
                    updateCallStatus("rejected");
                    stopTimer();
                    setTimeout(showDialScreen, 2000);
                });

                activeCall.on("error", function(error) {
                    console.error("Call error:", error);
                    showStatus("error", error.message || "Call error");
                    showDialScreen();
                });

            } catch (err) {
                console.error("Connect error:", err);
                showStatus("error", err.message);
                showDialScreen();
            }
        }

        function endCall() {
            if (activeCall) {
                activeCall.disconnect();
            }
            showDialScreen();
        }

        function toggleMute() {
            if (activeCall) {
                var muted = activeCall.isMuted();
                activeCall.mute(!muted);
                els.muteBtn.classList.toggle("active", !muted);
                els.muteBtn.textContent = !muted ? "🔈" : "🔇";
            }
        }

        function showCallScreen(phone) {
            els.dialScreen.style.display = "none";
            els.callScreen.style.display = "block";
            els.callerNumber.textContent = phone;
            els.callerName.textContent = "Calling...";
        }

        function showDialScreen() {
            els.callScreen.style.display = "none";
            els.dialScreen.style.display = "block";
            stopTimer();
            els.timer.textContent = "00:00";
        }

        function startTimer() {
            startTime = Date.now();
            timerInterval = setInterval(function() {
                var secs = Math.floor((Date.now() - startTime) / 1000);
                var m = Math.floor(secs / 60);
                var s = secs % 60;
                els.timer.textContent = (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s;
            }, 1000);
        }

        function stopTimer() {
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
        }

        function updateCallStatus(status) {
            var statusEl = els.callStatus;
            statusEl.className = "call-status";

            switch(status) {
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
                case "connected":
                    statusEl.textContent = "Connected";
                    statusEl.classList.add("status-connected");
                    els.callerName.textContent = "On Call";
                    break;
                case "ended":
                case "rejected":
                case "canceled":
                    statusEl.textContent = status.toUpperCase();
                    statusEl.classList.add("status-ended");
                    els.callerName.textContent = "Call Ended";
                    break;
            }
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
}
