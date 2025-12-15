<?php
/**
 * Twilio Webhook Entry Point
 * Direct entry point for Twilio callbacks - bypasses SuiteCRM authentication
 *
 * Webhook URLs to configure in Twilio:
 *   TwiML:     https://yourdomain.com/legacy/modules/TwilioIntegration/twilio_webhook.php?action=twiml
 *   Recording: https://yourdomain.com/legacy/modules/TwilioIntegration/twilio_webhook.php?action=recording
 *   Status:    https://yourdomain.com/legacy/modules/TwilioIntegration/twilio_webhook.php?action=status
 */

// Prevent CLI execution
if (php_sapi_name() === 'cli') {
    die('CLI not supported');
}

// Change to SuiteCRM legacy root
$legacyRoot = dirname(__FILE__) . '/../../..';
if (!file_exists($legacyRoot . '/config.php')) {
    // Try alternate path structure
    $legacyRoot = dirname(__FILE__) . '/../../../public/legacy';
}

if (!file_exists($legacyRoot . '/config.php')) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Configuration error</Say><Hangup/></Response>';
    exit;
}

chdir($legacyRoot);
define('sugarEntry', true);

// Load SuiteCRM config
require_once('config.php');
$GLOBALS['sugar_config'] = $sugar_config;

// Initialize logging
require_once('include/SugarLogger/LoggerManager.php');
$GLOBALS['log'] = LoggerManager::getLogger('SugarCRM');

// Initialize database
require_once('include/database/DBManagerFactory.php');
$GLOBALS['db'] = DBManagerFactory::getInstance();

// Get action parameter
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'twiml';
$dialAction = isset($_REQUEST['dial_action']) ? $_REQUEST['dial_action'] : 'outbound';

$GLOBALS['log']->info("Twilio Webhook - Action: $action, DialAction: $dialAction, Method: " . $_SERVER['REQUEST_METHOD']);

// Route to handler
switch ($action) {
    case 'twiml':
        handleTwiml($dialAction);
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

    $to = $_REQUEST['To'] ?? $_REQUEST['to'] ?? '';
    $from = $_REQUEST['From'] ?? $_REQUEST['from'] ?? '';

    $GLOBALS['log']->info("TwiML - DialAction: $dialAction, To: $to, From: $from");

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
        echo '<Say voice="Polly.Joanna">Connecting your call. Please wait.</Say>';
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
    return $baseUrl . '/legacy/modules/TwilioIntegration/twilio_webhook.php';
}

function cleanPhone($phone) {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) === 10) {
        $digits = '1' . $digits;
    }
    return '+' . $digits;
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
