<?php
/**
 * Twilio Webhook Entry Point
 * Direct entry point for Twilio callbacks - bypasses SuiteCRM authentication
 *
 * Webhook URLs:
 *   TwiML:     https://yourdomain.com/legacy/twilio_webhook.php?action=twiml
 *   Recording: https://yourdomain.com/legacy/twilio_webhook.php?action=recording
 *   Status:    https://yourdomain.com/legacy/twilio_webhook.php?action=status
 */

// Prevent CLI execution
if (php_sapi_name() === 'cli') {
    die('CLI not supported');
}

// Change to SuiteCRM legacy root
// File is at /bitnami/suitecrm/public/legacy/twilio_webhook.php
$legacyRoot = dirname(__FILE__);
if (!file_exists($legacyRoot . '/config.php')) {
    // Fallback: try if we're in modules subdirectory
    $legacyRoot = dirname(__FILE__) . '/../../..';
}
if (!file_exists($legacyRoot . '/config.php')) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Configuration error</Say><Hangup/></Response>';
    exit;
}

chdir($legacyRoot);
define('sugarEntry', true);

// Use SuiteCRM's entryPoint for proper bootstrap (loads all required classes)
require_once('include/entryPoint.php');

// Get action parameter
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'twiml';
$dialAction = isset($_REQUEST['dial_action']) ? $_REQUEST['dial_action'] : 'outbound';

$GLOBALS['log']->info("Twilio Webhook - Action: $action, DialAction: $dialAction, Method: " . $_SERVER['REQUEST_METHOD']);

// Route to handler
switch ($action) {
    case 'twiml':
        handleTwiml($dialAction);
        break;
    case 'voice':
        // Handle Twilio Client SDK outbound calls (TwiML App Voice URL)
        handleBrowserCall();
        break;
    case 'token':
        // Generate access token for Twilio Client SDK
        handleGetToken();
        break;
    case 'incoming':
        // Handle incoming calls - route to browser clients
        handleIncomingCall();
        break;
    case 'caller_lookup':
        // Lookup caller info by phone number
        handleCallerLookup();
        break;
    case 'recording':
        handleRecording();
        break;
    case 'status':
        handleStatus();
        break;
    case 'sms':
        handleSms();
        break;
    default:
        header('Content-Type: application/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Unknown action</Say><Hangup/></Response>';
}
exit;

// ============================================================================
// Handler Functions
// ============================================================================

function handleTwiml($dialAction) {
    header('Content-Type: application/xml');

    // IMPORTANT: Use $_GET for our URL params, $_POST for Twilio's params
    // Our URL has ?to=RECIPIENT, Twilio POSTs To=AGENT_PHONE
    $to = isset($_GET['to']) ? $_GET['to'] : (isset($_POST['To']) ? $_POST['To'] : '');
    $from = isset($_GET['from']) ? $_GET['from'] : (isset($_POST['From']) ? $_POST['From'] : '');

    $GLOBALS['log']->info("TwiML - DialAction: $dialAction, To: $to, From: $from, GET_to: " . ($_GET['to'] ?? 'none') . ", POST_To: " . ($_POST['To'] ?? 'none'));

    // Get Twilio config
    $config = getTwilioConfig();
    $baseUrl = getWebhookBaseUrl();

    switch ($dialAction) {
        case 'outbound':
        default:
            outputOutbound($to, $config, $baseUrl);
            break;
        case 'dial_status':
            outputDialStatus();
            break;
        case 'voicemail':
            outputVoicemail($from, $baseUrl);
            break;
        case 'recording':
            outputRecordingComplete();
            break;
        case 'inbound':
            outputInbound($from, $config, $baseUrl);
            break;
    }
}

function handleRecording() {
    $recordingSid = $_REQUEST['RecordingSid'] ?? '';
    $callSid = $_REQUEST['CallSid'] ?? '';
    $recordingUrl = $_REQUEST['RecordingUrl'] ?? '';
    $status = $_REQUEST['RecordingStatus'] ?? '';
    $duration = $_REQUEST['RecordingDuration'] ?? '0';

    $GLOBALS['log']->info("Recording Webhook - SID: $recordingSid, CallSID: $callSid, Status: $status");

    // Log to audit if table exists
    if ($status === 'completed' && $recordingSid) {
        try {
            $db = $GLOBALS['db'];
            $id = generateGuid();
            $sql = "INSERT INTO twilio_audit_log (id, date_entered, call_sid, recording_sid, recording_url, duration, status)
                    VALUES ('$id', NOW(), '" . $db->quote($callSid) . "', '" . $db->quote($recordingSid) . "',
                    '" . $db->quote($recordingUrl) . "', " . intval($duration) . ", '" . $db->quote($status) . "')";
            $db->query($sql);
        } catch (Exception $e) {
            $GLOBALS['log']->error("Recording log failed: " . $e->getMessage());
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
}

function handleStatus() {
    $callSid = $_REQUEST['CallSid'] ?? '';
    $callStatus = $_REQUEST['CallStatus'] ?? '';
    $duration = $_REQUEST['CallDuration'] ?? '0';

    $GLOBALS['log']->info("Status Webhook - SID: $callSid, Status: $callStatus, Duration: $duration");

    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'call_status' => $callStatus]);
}

function handleSms() {
    $from = $_REQUEST['From'] ?? '';
    $body = $_REQUEST['Body'] ?? '';

    $GLOBALS['log']->info("SMS Webhook - From: $from, Body: " . substr($body, 0, 50));

    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
}

/**
 * Handle incoming calls - route to browser clients
 * This is called when someone calls a Twilio phone number
 * It dials all users assigned to that number via browser (Twilio Client)
 */
function handleIncomingCall() {
    header('Content-Type: application/xml');

    $calledNumber = $_REQUEST['To'] ?? '';     // The Twilio number that was called
    $callerNumber = $_REQUEST['From'] ?? '';   // The person calling
    $callSid = $_REQUEST['CallSid'] ?? '';

    $GLOBALS['log']->info("Incoming Call - To: $calledNumber, From: $callerNumber, CallSid: $callSid");

    // Clean the called number for lookup
    $cleanCalledNumber = cleanPhone($calledNumber);

    // Find all users assigned to this phone number in twilio_integration table
    $assignedUsers = getUsersForPhoneNumber($cleanCalledNumber);

    $GLOBALS['log']->info("Incoming Call - Found " . count($assignedUsers) . " users for number $cleanCalledNumber");

    $baseUrl = getWebhookBaseUrl();
    $voicemailUrl = $baseUrl . '?action=twiml&dial_action=voicemail&from=' . urlencode($callerNumber);

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';

    if (!empty($assignedUsers)) {
        // Dial all assigned browser clients simultaneously
        // First one to answer gets the call
        echo '<Dial timeout="30" action="' . h($voicemailUrl) . '" method="POST">';

        foreach ($assignedUsers as $userId) {
            // Client identity must match what's used in token generation
            $clientIdentity = 'agent_' . $userId;
            echo '<Client>' . h($clientIdentity) . '</Client>';
            $GLOBALS['log']->info("Incoming Call - Dialing client: $clientIdentity");
        }

        echo '</Dial>';
    } else {
        // No users assigned to this number, try fallback phone or go to voicemail
        $fallbackPhone = $GLOBALS['sugar_config']['twilio_fallback_phone'] ?? '';

        if ($fallbackPhone) {
            $GLOBALS['log']->info("Incoming Call - No users found, trying fallback: $fallbackPhone");
            echo '<Say voice="Polly.Joanna">Please hold while we connect your call.</Say>';
            echo '<Dial timeout="20" action="' . h($voicemailUrl) . '" method="POST">';
            echo '<Number>' . h(cleanPhone($fallbackPhone)) . '</Number>';
            echo '</Dial>';
        } else {
            $GLOBALS['log']->info("Incoming Call - No users or fallback, going to voicemail");
            echo '<Say voice="Polly.Joanna">Thank you for calling. Please leave a message after the tone.</Say>';
            echo '<Record maxLength="120" playBeep="true" action="' . h($baseUrl . '?action=twiml&dial_action=recording&from=' . urlencode($callerNumber)) . '"/>';
            echo '<Say voice="Polly.Joanna">Goodbye.</Say>';
            echo '<Hangup/>';
        }
    }

    echo '</Response>';

    // Log the incoming call to audit
    logIncomingCall($callSid, $callerNumber, $calledNumber, $assignedUsers);
}

/**
 * Get all user IDs assigned to a specific phone number
 */
function getUsersForPhoneNumber($phoneNumber) {
    $db = $GLOBALS['db'];
    $users = [];

    // Clean phone number for comparison (remove formatting)
    $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);

    // Try multiple formats for matching
    $searchPatterns = [
        $phoneNumber,                          // +11234567890
        $cleanPhone,                           // 11234567890
        substr($cleanPhone, -10),              // 1234567890 (last 10 digits)
        '+' . $cleanPhone,                     // +11234567890
        '+1' . substr($cleanPhone, -10),       // +11234567890 (US format)
    ];

    $conditions = [];
    foreach ($searchPatterns as $pattern) {
        $conditions[] = "REPLACE(REPLACE(REPLACE(phone_number, '-', ''), ' ', ''), '+', '') LIKE '%" . $db->quote(preg_replace('/[^0-9]/', '', $pattern)) . "%'";
    }

    $sql = "SELECT DISTINCT assigned_user_id
            FROM twilio_integration
            WHERE deleted = 0
            AND assigned_user_id IS NOT NULL
            AND assigned_user_id != ''
            AND (" . implode(' OR ', $conditions) . ")";

    $GLOBALS['log']->info("Incoming Call - User lookup SQL: $sql");

    $result = $db->query($sql);
    while ($row = $db->fetchByAssoc($result)) {
        if (!empty($row['assigned_user_id'])) {
            $users[] = $row['assigned_user_id'];
        }
    }

    return $users;
}

/**
 * Log incoming call to audit table
 */
function logIncomingCall($callSid, $callerNumber, $calledNumber, $assignedUsers) {
    try {
        $db = $GLOBALS['db'];
        $id = generateGuid();
        $userList = implode(',', $assignedUsers);

        $sql = "INSERT INTO twilio_audit_log (id, action, data, date_created)
                VALUES ('" . $db->quote($id) . "', 'incoming_call',
                '" . $db->quote(json_encode([
                    'call_sid' => $callSid,
                    'caller' => $callerNumber,
                    'called' => $calledNumber,
                    'assigned_users' => $assignedUsers
                ])) . "', NOW())";
        $db->query($sql);
    } catch (Exception $e) {
        $GLOBALS['log']->error("Failed to log incoming call: " . $e->getMessage());
    }
}

/**
 * Lookup caller information by phone number
 * Returns Lead or Contact info if found
 */
function handleCallerLookup() {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');

    $phone = $_REQUEST['phone'] ?? '';

    if (empty($phone)) {
        echo json_encode(['success' => false, 'error' => 'Phone number required']);
        return;
    }

    $result = lookupCallerByPhone($phone);

    if ($result) {
        echo json_encode([
            'success' => true,
            'found' => true,
            'name' => $result['name'],
            'module' => $result['module'],
            'record_id' => $result['record_id'],
            'status' => $result['status'] ?? '',
            'funnel_type' => $result['funnel_type'] ?? ''
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'found' => false,
            'name' => null,
            'module' => null,
            'record_id' => null
        ]);
    }
}

/**
 * Search for a caller in Leads and Contacts tables by phone number
 */
function lookupCallerByPhone($phone) {
    $db = $GLOBALS['db'];

    // Clean phone number - keep only digits
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

    // Get last 10 digits for US number matching
    $last10 = substr($cleanPhone, -10);

    if (strlen($last10) < 7) {
        return null; // Too short to be a valid phone
    }

    // Search Leads first
    $sql = "SELECT id, first_name, last_name, status, funnel_type_c
            FROM leads
            WHERE deleted = 0
            AND (
                REPLACE(REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_other, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_fax, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
            )
            ORDER BY date_modified DESC
            LIMIT 1";

    $result = $db->query($sql);
    $row = $db->fetchByAssoc($result);

    if ($row) {
        $name = trim($row['first_name'] . ' ' . $row['last_name']);
        return [
            'name' => $name ?: 'Unknown Lead',
            'module' => 'Leads',
            'record_id' => $row['id'],
            'status' => $row['status'] ?? '',
            'funnel_type' => $row['funnel_type_c'] ?? ''
        ];
    }

    // Search Contacts if not found in Leads
    $sql = "SELECT id, first_name, last_name
            FROM contacts
            WHERE deleted = 0
            AND (
                REPLACE(REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_other, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_fax, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
            )
            ORDER BY date_modified DESC
            LIMIT 1";

    $result = $db->query($sql);
    $row = $db->fetchByAssoc($result);

    if ($row) {
        $name = trim($row['first_name'] . ' ' . $row['last_name']);
        return [
            'name' => $name ?: 'Unknown Contact',
            'module' => 'Contacts',
            'record_id' => $row['id']
        ];
    }

    // Search Accounts by phone
    $sql = "SELECT id, name
            FROM accounts
            WHERE deleted = 0
            AND (
                REPLACE(REPLACE(REPLACE(REPLACE(phone_office, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_alternate, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_fax, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
            )
            ORDER BY date_modified DESC
            LIMIT 1";

    $result = $db->query($sql);
    $row = $db->fetchByAssoc($result);

    if ($row) {
        return [
            'name' => $row['name'] ?: 'Unknown Account',
            'module' => 'Accounts',
            'record_id' => $row['id']
        ];
    }

    return null;
}

/**
 * Generate access token for Twilio Client SDK
 */
function handleGetToken() {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');

    $config = getTwilioConfig();

    if (empty($config['account_sid']) || empty($config['auth_token'])) {
        echo json_encode(['success' => false, 'error' => 'Twilio is not configured']);
        return;
    }

    // Get TwiML App SID and API credentials
    global $sugar_config;
    $twimlAppSid = getenv('TWILIO_TWIML_APP_SID') ?: ($sugar_config['twilio_twiml_app_sid'] ?? '');
    $apiKey = getenv('TWILIO_API_KEY') ?: ($sugar_config['twilio_api_key'] ?? '');
    $apiSecret = getenv('TWILIO_API_SECRET') ?: ($sugar_config['twilio_api_secret'] ?? '');

    // Fall back to account credentials if no API key
    if (empty($apiKey)) {
        $apiKey = $config['account_sid'];
        $apiSecret = $config['auth_token'];
    }

    if (empty($twimlAppSid)) {
        echo json_encode(['success' => false, 'error' => 'TwiML App SID not configured. Go to Twilio Config to set it up.']);
        return;
    }

    // Generate identity from session or default
    $identity = 'agent_' . (isset($_SESSION['authenticated_user_id']) ? $_SESSION['authenticated_user_id'] : 'web');

    // Generate JWT token
    $token = generateTwilioToken($config['account_sid'], $apiKey, $apiSecret, $identity, $twimlAppSid);

    echo json_encode([
        'success' => true,
        'token' => $token,
        'identity' => $identity,
        'caller_id' => $config['phone_number']
    ]);
}

/**
 * Generate JWT Access Token for Twilio Client
 * Includes both outgoing and incoming call capabilities
 */
function generateTwilioToken($accountSid, $apiKey, $apiSecret, $identity, $twimlAppSid, $includeIncoming = true) {
    $ttl = 3600;
    $now = time();

    $header = [
        'typ' => 'JWT',
        'alg' => 'HS256',
        'cty' => 'twilio-fpa;v=1'
    ];

    // Build voice grant with both outgoing and incoming capabilities
    $voiceGrant = [
        'outgoing' => [
            'application_sid' => $twimlAppSid
        ]
    ];

    // Add incoming capability for receiving calls
    if ($includeIncoming) {
        $voiceGrant['incoming'] = [
            'allow' => true
        ];
    }

    $grants = [
        'identity' => $identity,
        'voice' => $voiceGrant
    ];

    $payload = [
        'jti' => $apiKey . '-' . $now,
        'iss' => $apiKey,
        'sub' => $accountSid,
        'exp' => $now + $ttl,
        'grants' => $grants
    ];

    $headerEncoded = base64UrlEncode(json_encode($header));
    $payloadEncoded = base64UrlEncode(json_encode($payload));
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $apiSecret, true);
    $signatureEncoded = base64UrlEncode($signature);

    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Handle browser-originated calls from Twilio Client SDK
 * This is called when a call is made via device.connect() in the browser
 */
function handleBrowserCall() {
    header('Content-Type: application/xml');

    // Get parameters passed from Twilio Client SDK
    $to = $_REQUEST['To'] ?? '';
    $callerId = $_REQUEST['CallerId'] ?? $_REQUEST['From'] ?? '';
    $from = $_REQUEST['From'] ?? ''; // This is the client identity

    $GLOBALS['log']->info("Browser Call - To: $to, CallerId: $callerId, From: $from");

    // Clean and format the destination number
    $to = cleanPhone($to);

    // Get Twilio config for caller ID
    $config = getTwilioConfig();
    if (empty($callerId)) {
        $callerId = $config['phone_number'] ?? '';
    }

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';

    if (!empty($to)) {
        // Dial the destination number
        // The browser (Twilio Client) is already connected, this connects to the recipient
        echo '<Dial callerId="' . h($callerId) . '" timeout="30">';
        echo '<Number>' . h($to) . '</Number>';
        echo '</Dial>';
    } else {
        echo '<Say voice="Polly.Joanna">No destination number provided.</Say>';
        echo '<Hangup/>';
    }

    echo '</Response>';
}

// ============================================================================
// TwiML Output Functions
// ============================================================================

function outputOutbound($to, $config, $baseUrl) {
    $to = cleanPhone($to);
    $callerId = $config['phone_number'] ?? '';
    $statusUrl = $baseUrl . '?action=twiml&dial_action=dial_status';

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    if ($to) {
        // Agent hears this message, then gets connected to the recipient
        echo '<Say voice="Polly.Joanna">Connecting you to ' . h(formatPhoneForSpeech($to)) . '. Please hold.</Say>';
        echo '<Dial callerId="' . h($callerId) . '" timeout="30" action="' . h($statusUrl) . '" method="POST">';
        echo '<Number>' . h($to) . '</Number>';
        echo '</Dial>';
    } else {
        echo '<Say voice="Polly.Joanna">No destination number provided.</Say>';
        echo '<Hangup/>';
    }
    echo '</Response>';
}

function outputDialStatus() {
    $dialStatus = $_REQUEST['DialCallStatus'] ?? '';
    $GLOBALS['log']->info("Dial Status: $dialStatus");

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response></Response>';
}

function outputVoicemail($from, $baseUrl) {
    $dialStatus = $_REQUEST['DialCallStatus'] ?? '';

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';

    if ($dialStatus === 'completed') {
        echo '<Hangup/>';
    } else {
        $recordUrl = $baseUrl . '?action=twiml&dial_action=recording&from=' . urlencode($from);
        echo '<Say voice="Polly.Joanna">Please leave a message after the tone.</Say>';
        echo '<Record maxLength="120" playBeep="true" action="' . h($recordUrl) . '"/>';
        echo '<Say voice="Polly.Joanna">Goodbye.</Say>';
        echo '<Hangup/>';
    }
    echo '</Response>';
}

function outputRecordingComplete() {
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Polly.Joanna">Thank you for your message. Goodbye.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

function outputInbound($from, $config, $baseUrl) {
    $fallbackPhone = $GLOBALS['sugar_config']['twilio_fallback_phone'] ?? '';

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Polly.Joanna">Thank you for calling.</Say>';

    if ($fallbackPhone) {
        $voicemailUrl = $baseUrl . '?action=twiml&dial_action=voicemail&from=' . urlencode($from);
        echo '<Dial timeout="20" action="' . h($voicemailUrl) . '" method="POST">';
        echo '<Number>' . h(cleanPhone($fallbackPhone)) . '</Number>';
        echo '</Dial>';
    } else {
        echo '<Say voice="Polly.Joanna">We are unavailable. Please try again later.</Say>';
        echo '<Hangup/>';
    }
    echo '</Response>';
}

// ============================================================================
// Helper Functions
// ============================================================================

function getTwilioConfig() {
    global $sugar_config;
    return [
        'account_sid' => getenv('TWILIO_ACCOUNT_SID') ?: ($sugar_config['twilio_account_sid'] ?? ''),
        'auth_token' => getenv('TWILIO_AUTH_TOKEN') ?: ($sugar_config['twilio_auth_token'] ?? ''),
        'phone_number' => getenv('TWILIO_PHONE_NUMBER') ?: ($sugar_config['twilio_phone_number'] ?? ''),
    ];
}

function getWebhookBaseUrl() {
    global $sugar_config;
    $baseUrl = getenv('APP_URL') ?: ($sugar_config['site_url'] ?? '');
    $baseUrl = rtrim($baseUrl, '/');
    return $baseUrl . '/legacy/twilio_webhook.php';
}

function cleanPhone($phone) {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) === 10) {
        $digits = '1' . $digits;
    }
    return '+' . $digits;
}

function formatPhoneForSpeech($phone) {
    // Make phone number more readable for TTS
    // +16315551234 -> "6 3 1. 5 5 5. 1 2 3 4"
    $digits = preg_replace('/[^0-9]/', '', $phone);
    // Skip country code for US numbers
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }
    // Add spaces between digits and pauses between groups
    if (strlen($digits) === 10) {
        $area = substr($digits, 0, 3);
        $prefix = substr($digits, 3, 3);
        $line = substr($digits, 6, 4);
        return implode(' ', str_split($area)) . '. ' .
               implode(' ', str_split($prefix)) . '. ' .
               implode(' ', str_split($line));
    }
    return implode(' ', str_split($digits));
}

function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function generateGuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}
