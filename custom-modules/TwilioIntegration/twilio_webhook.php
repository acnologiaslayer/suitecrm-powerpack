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

// START OUTPUT BUFFERING IMMEDIATELY - SuiteCRM's entryPoint may output content
// We'll discard all output and only send clean TwiML/JSON responses
ob_start();

// Prevent CLI execution
if (php_sapi_name() === 'cli') {
    die('CLI not supported');
}

// Change to SuiteCRM legacy root
// File can be at /bitnami/suitecrm/public/legacy/twilio_webhook.php (root)
// or at /bitnami/suitecrm/public/legacy/modules/TwilioIntegration/twilio_webhook.php (module)
$legacyRoot = dirname(__FILE__);
if (!file_exists($legacyRoot . '/config.php')) {
    // Fallback: try if we're in modules/TwilioIntegration subdirectory (2 levels up)
    $legacyRoot = dirname(__FILE__, 3); // Go up 3 levels: TwilioIntegration -> modules -> legacy
}
if (!file_exists($legacyRoot . '/config.php')) {
    // Try alternate: maybe 2 levels up
    $legacyRoot = dirname(__FILE__, 2);
}
if (!file_exists($legacyRoot . '/config.php')) {
    while (ob_get_level()) { ob_end_clean(); }
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Configuration error</Say><Hangup/></Response>';
    exit;
}

chdir($legacyRoot);
define('sugarEntry', true);

// Use SuiteCRM's entryPoint for proper bootstrap (loads all required classes)
require_once('include/entryPoint.php');

// For token endpoint, we need to authenticate the user from session
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Try to load current user from session
global $current_user;
if (empty($current_user) || empty($current_user->id)) {
    if (!empty($_SESSION['authenticated_user_id'])) {
        $current_user = BeanFactory::getBean('Users', $_SESSION['authenticated_user_id']);
    } elseif (!empty($_SESSION['user_id'])) {
        $current_user = BeanFactory::getBean('Users', $_SESSION['user_id']);
    }
}

// Get action parameter
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'twiml';
$dialAction = isset($_REQUEST['dial_action']) ? $_REQUEST['dial_action'] : 'outbound';

$GLOBALS['log']->info("Twilio Webhook - Action: $action, DialAction: $dialAction, Method: " . $_SERVER['REQUEST_METHOD']);

// Set up error handler to return TwiML on errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $GLOBALS['log']->error("Twilio Webhook Error: $errstr in $errfile:$errline");
    return false; // Continue with normal error handling
});

set_exception_handler(function($exception) {
    $GLOBALS['log']->error("Twilio Webhook Exception: " . $exception->getMessage());
    // Clear ALL output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response><Say voice="Polly.Joanna">An error occurred. Please try again.</Say><Hangup/></Response>';
    exit;
});

// Route to handler
switch ($action) {
    case 'twiml':
        handleTwiml($dialAction);
        break;
    case 'voice':
        // Handle Twilio Voice calls (TwiML App Voice URL)
        // This handles BOTH outgoing browser calls AND incoming calls to the Twilio number
        handleVoiceCall();
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
    case 'send_sms':
        handleSendSms();
        break;
    default:
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Unknown action</Say><Hangup/></Response>';
}
exit;

// ============================================================================
// Handler Functions
// ============================================================================

function handleTwiml($dialAction) {
    // Clear any output buffers to ensure clean TwiML
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/xml');

    // IMPORTANT: Use $_GET for our URL params, $_POST for Twilio's params
    // Our URL has ?to=RECIPIENT, Twilio POSTs To=AGENT_PHONE
    $to = isset($_GET['to']) ? $_GET['to'] : (isset($_POST['To']) ? $_POST['To'] : '');
    $from = isset($_GET['from']) ? $_GET['from'] : (isset($_POST['From']) ? $_POST['From'] : '');

    $GLOBALS['log']->info("TwiML - DialAction: $dialAction, To: $to, From: $from, GET_to: " . ($_GET['to'] ?? 'none') . ", POST_To: " . ($_POST['To'] ?? 'none'));

    // Extract user ID from the From parameter if it's a client identity
    $userId = null;
    if (preg_match('/agent_([a-f0-9\-]+)/i', $from, $matches)) {
        $userId = $matches[1];
    }

    // Get Twilio config (use user-specific config if available)
    $config = getTwilioConfig($userId);
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
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    $recordingSid = $_REQUEST['RecordingSid'] ?? '';
    $callSid = $_REQUEST['CallSid'] ?? '';
    $recordingUrl = $_REQUEST['RecordingUrl'] ?? '';
    $status = $_REQUEST['RecordingStatus'] ?? '';
    $duration = $_REQUEST['RecordingDuration'] ?? '0';

    $GLOBALS['log']->info("Recording Webhook - SID: $recordingSid, CallSID: $callSid, Status: $status, URL: $recordingUrl");

    // Log to audit and update call record when recording is completed
    if ($status === 'completed' && $recordingSid && $recordingUrl) {
        $db = $GLOBALS['db'];
        
        // Add .mp3 extension if not present for playback
        $recordingUrlMp3 = $recordingUrl;
        if (strpos($recordingUrl, '.mp3') === false && strpos($recordingUrl, '.wav') === false) {
            $recordingUrlMp3 = $recordingUrl . '.mp3';
        }
        
        try {
            // Update the calls table with recording info - find by CallSid in description or twilio_call_sid
            $safeSid = $db->quote($callSid);
            $safeUrl = $db->quote($recordingUrlMp3);
            $safeRecSid = $db->quote($recordingSid);
            
            // Try to update by twilio_call_sid first, then by description
            $updateSql = "UPDATE calls SET 
                recording_url = '$safeUrl',
                recording_sid = '$safeRecSid',
                twilio_call_sid = '$safeSid',
                date_modified = NOW()
                WHERE (twilio_call_sid = '$safeSid' OR description LIKE '%Call SID: $safeSid%') AND deleted = 0";
            $db->query($updateSql);
            $GLOBALS['log']->info("Recording Webhook - Updated call record with recording URL for CallSID: $callSid");
            
            // Also update lead_journey entries with recording URL (both column and JSON data)
            $journeyUpdateSql = "UPDATE lead_journey SET
                recording_url = '$safeUrl',
                touchpoint_data = JSON_SET(COALESCE(touchpoint_data, '{}'), '$.recording_url', '$safeUrl'),
                date_modified = NOW()
                WHERE touchpoint_data LIKE '%$safeSid%' AND deleted = 0";
            $db->query($journeyUpdateSql);
            $GLOBALS['log']->info("Recording Webhook - Updated lead_journey with recording URL");
            
        } catch (Exception $e) {
            $GLOBALS['log']->error("Recording update failed: " . $e->getMessage());
        }
        
        // Log to audit table
        try {
            $id = generateGuid();
            $sql = "INSERT INTO twilio_audit_log (id, action, data, date_created)
                    VALUES ('$id', 'recording_completed', '" . $db->quote(json_encode([
                        'call_sid' => $callSid,
                        'recording_sid' => $recordingSid,
                        'recording_url' => $recordingUrlMp3,
                        'duration' => intval($duration)
                    ])) . "', NOW())";
            $db->query($sql);
        } catch (Exception $e) {
            $GLOBALS['log']->error("Recording audit log failed: " . $e->getMessage());
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'recording_sid' => $recordingSid]);
}

function handleStatus() {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    $callSid = $_REQUEST['CallSid'] ?? '';
    $callStatus = $_REQUEST['CallStatus'] ?? '';
    $dialCallStatus = $_REQUEST['DialCallStatus'] ?? '';
    $duration = $_REQUEST['CallDuration'] ?? '0';
    $from = $_REQUEST['From'] ?? '';
    $to = $_REQUEST['To'] ?? '';
    $direction = $_REQUEST['Direction'] ?? '';

    // Check for explicit type parameter (added by our TwiML for outbound calls)
    $callType = $_GET['type'] ?? '';
    $customerPhone = $_GET['customer'] ?? '';

    // If we have explicit outbound type from our TwiML, use it
    if ($callType === 'outbound') {
        $direction = 'outbound-api';
        // Use the customer phone from URL if available (more reliable than Twilio's To for child legs)
        if (!empty($customerPhone)) {
            $to = $customerPhone;
        }
    }

    $GLOBALS['log']->info("Status Webhook - SID: $callSid, CallStatus: $callStatus, DialCallStatus: $dialCallStatus, Duration: $duration, From: $from, To: $to, Direction: $direction, Type: $callType, Customer: $customerPhone");

    // Log the call to SuiteCRM Calls module and LeadJourney
    if (!empty($callSid) && in_array($callStatus, ['initiated', 'ringing', 'in-progress', 'completed', 'busy', 'no-answer', 'failed', 'canceled'])) {
        logCallToCRM($callSid, $callStatus, $duration, $from, $to, $direction);
    }

    // If this is a Dial action callback (has DialCallStatus), return TwiML
    if (!empty($dialCallStatus)) {
        header('Content-Type: application/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        // Call completed normally, just hang up
        echo '<Hangup/>';
        echo '</Response>';
    } else {
        // Regular status callback, return JSON
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'call_status' => $callStatus]);
    }
}

/**
 * Log call to SuiteCRM Calls module and LeadJourney table
 */
function logCallToCRM($callSid, $status, $duration, $from, $to, $direction) {
    $db = $GLOBALS['db'];
    
    // Determine direction - if not provided, check if 'from' starts with 'client:' (browser call = outbound)
    if (empty($direction)) {
        $direction = (strpos($from, 'client:') === 0) ? 'outbound-api' : 'inbound';
    }
    $isOutbound = (stripos($direction, 'outbound') !== false);
    
    // For outbound calls, 'to' is the customer, 'from' might be client:xxx or our Twilio number
    // For inbound calls, 'from' is the customer
    $customerPhone = $isOutbound ? $to : $from;
    $customerPhone = preg_replace('/^client:/', '', $customerPhone); // Remove client: prefix
    
    // Look up lead/contact by phone
    $leadInfo = lookupCallerByPhone($customerPhone);
    
    $GLOBALS['log']->info("LogCallToCRM - SID: $callSid, Status: $status, Duration: $duration, Direction: $direction, CustomerPhone: $customerPhone, LeadFound: " . ($leadInfo ? 'yes' : 'no'));
    
    // Check if call record already exists for this CallSid
    $existingCallId = null;
    $safeSid = $db->quote($callSid);
    $sql = "SELECT id FROM calls WHERE description LIKE '%Call SID: $safeSid%' AND deleted = 0 LIMIT 1";
    $result = $db->query($sql);
    if ($row = $db->fetchByAssoc($result)) {
        $existingCallId = $row['id'];
    }
    
    // Map Twilio status to SuiteCRM status
    $crmStatus = 'Planned';
    switch ($status) {
        case 'completed':
            $crmStatus = 'Held';
            break;
        case 'in-progress':
            $crmStatus = 'Held'; // Call is active
            break;
        case 'busy':
        case 'no-answer':
        case 'failed':
        case 'canceled':
            $crmStatus = 'Not Held';
            break;
        case 'initiated':
        case 'ringing':
        case 'queued':
            $crmStatus = 'Planned';
            break;
    }
    
    // Calculate duration
    $durationInt = intval($duration);
    $durationHours = floor($durationInt / 3600);
    $durationMinutes = floor(($durationInt % 3600) / 60);
    
    if ($existingCallId) {
        // Update existing call record
        $updateSql = "UPDATE calls SET 
            status = '" . $db->quote($crmStatus) . "',
            duration_hours = " . intval($durationHours) . ",
            duration_minutes = " . intval($durationMinutes) . ",
            date_modified = NOW(),
            description = CONCAT(description, '\nStatus Update: $status, Duration: " . gmdate('H:i:s', $durationInt) . "')
            WHERE id = '" . $db->quote($existingCallId) . "'";
        $db->query($updateSql);
        $GLOBALS['log']->info("Updated existing call record: $existingCallId");
    } else {
        // Create new call record only for meaningful statuses (not just 'initiated')
        if (in_array($status, ['completed', 'in-progress', 'busy', 'no-answer', 'failed', 'canceled', 'ringing'])) {
            $callId = generateGuid();
            $callName = $isOutbound ? 'Outbound Call to ' . $customerPhone : 'Inbound Call from ' . $customerPhone;
            if ($leadInfo && !empty($leadInfo['name'])) {
                $callName = $isOutbound ? 'Outbound Call to ' . $leadInfo['name'] : 'Inbound Call from ' . $leadInfo['name'];
            }
            
            $parentType = $leadInfo ? $leadInfo['module'] : '';
            $parentId = $leadInfo ? $leadInfo['record_id'] : '';
            $assignedUserId = ($leadInfo && !empty($leadInfo['assigned_user_id'])) ? $leadInfo['assigned_user_id'] : '1';
            
            $description = ($isOutbound ? "Outbound" : "Inbound") . " call\n";
            $description .= "From: $from\n";
            $description .= "To: $to\n";
            $description .= "Call SID: $callSid\n";
            $description .= "Status: $status\n";
            $description .= "Duration: " . gmdate('H:i:s', $durationInt);
            
            $sql = "INSERT INTO calls (id, name, date_entered, date_modified, modified_user_id, created_by, 
                    description, deleted, status, direction, date_start, duration_hours, duration_minutes,
                    parent_type, parent_id, assigned_user_id, twilio_call_sid)
                    VALUES (
                        '" . $db->quote($callId) . "',
                        '" . $db->quote($callName) . "',
                        NOW(), NOW(), '1', '1',
                        '" . $db->quote($description) . "',
                        0,
                        '" . $db->quote($crmStatus) . "',
                        '" . ($isOutbound ? 'Outbound' : 'Inbound') . "',
                        NOW(),
                        " . intval($durationHours) . ",
                        " . intval($durationMinutes) . ",
                        '" . $db->quote($parentType) . "',
                        '" . $db->quote($parentId) . "',
                        '" . $db->quote($assignedUserId) . "',
                        '" . $db->quote($callSid) . "'
                    )";
            
            try {
                $db->query($sql);
                $GLOBALS['log']->info("Created new call record: $callId for lead/contact: $parentId");
            } catch (Exception $e) {
                $GLOBALS['log']->error("Failed to create call record: " . $e->getMessage());
            }
            
            // Also log to lead_journey table for timeline
            if ($leadInfo && !empty($leadInfo['record_id'])) {
                $journeyId = generateGuid();
                $touchpointData = json_encode([
                    'call_sid' => $callSid,
                    'from' => $from,
                    'to' => $to,
                    'direction' => $isOutbound ? 'outbound' : 'inbound',
                    'status' => $status,
                    'duration' => $durationInt
                ]);
                
                $journeySql = "INSERT INTO lead_journey (id, name, date_entered, date_modified, modified_user_id, created_by,
                        description, deleted, parent_type, parent_id, touchpoint_type, touchpoint_date,
                        touchpoint_data, source, assigned_user_id)
                        VALUES (
                            '" . $db->quote($journeyId) . "',
                            '" . $db->quote($callName) . "',
                            NOW(), NOW(), '1', '1',
                            '" . $db->quote($description) . "',
                            0,
                            '" . $db->quote($parentType) . "',
                            '" . $db->quote($parentId) . "',
                            '" . ($isOutbound ? 'outbound_call' : 'inbound_call') . "',
                            NOW(),
                            '" . $db->quote($touchpointData) . "',
                            'Twilio',
                            '" . $db->quote($assignedUserId) . "'
                        )";
                
                try {
                    $db->query($journeySql);
                    $GLOBALS['log']->info("Logged call to lead_journey for: " . $leadInfo['record_id']);
                } catch (Exception $e) {
                    $GLOBALS['log']->error("Failed to log to lead_journey: " . $e->getMessage());
                }
            }
        }
    }
}

function handleSms() {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    $from = $_REQUEST['From'] ?? '';
    $to = $_REQUEST['To'] ?? '';
    $body = $_REQUEST['Body'] ?? '';
    $messageSid = $_REQUEST['MessageSid'] ?? '';

    $GLOBALS['log']->info("SMS Webhook - From: $from, To: $to, Body: " . substr($body, 0, 50));

    // Find matching lead/contact by phone number
    $callerInfo = lookupCallerByPhone($from);

    $db = $GLOBALS['db'];
    $cleanFrom = preg_replace('/[^0-9]/', '', $from);

    // Find assigned users for this Twilio number
    $assignedUsers = getUsersForPhoneNumber($to);
    $assignedUserId = !empty($assignedUsers) ? $assignedUsers[0] : '1';

    if ($callerInfo && $callerInfo['module'] === 'Leads') {
        // Log to lead_journey table for the matching lead
        $journeyId = generateGuid();
        $callerName = $callerInfo['name'] ?? 'Unknown';

        // Create thread_id based on phone number for conversation grouping
        $threadId = 'sms_' . $cleanFrom;

        $touchpointData = json_encode([
            'from' => $from,
            'to' => $to,
            'body' => $body,
            'message_sid' => $messageSid,
            'direction' => 'inbound'
        ]);

        $sql = "INSERT INTO lead_journey (id, name, date_entered, date_modified, modified_user_id, created_by,
                description, deleted, parent_type, parent_id, touchpoint_type, touchpoint_date,
                touchpoint_data, source, assigned_user_id, thread_id)
                VALUES (
                    '" . $db->quote($journeyId) . "',
                    'SMS from " . $db->quote($callerName) . "',
                    NOW(), NOW(),
                    '" . $db->quote($assignedUserId) . "',
                    '" . $db->quote($assignedUserId) . "',
                    '" . $db->quote($body) . "',
                    0,
                    'Leads',
                    '" . $db->quote($callerInfo['record_id']) . "',
                    'inbound_sms',
                    NOW(),
                    '" . $db->quote($touchpointData) . "',
                    'Twilio',
                    '" . $db->quote($assignedUserId) . "',
                    '" . $db->quote($threadId) . "'
                )";

        try {
            $db->query($sql);
            $GLOBALS['log']->info("Incoming SMS logged to lead_journey for lead: " . $callerInfo['record_id']);
        } catch (Exception $e) {
            $GLOBALS['log']->error("Failed to log SMS to lead_journey: " . $e->getMessage());
        }
    } else {
        $GLOBALS['log']->info("Incoming SMS from unknown number: $from - no matching lead found");
    }

    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
}

/**
 * Send outgoing SMS
 */
function handleSendSms() {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    $to = $_REQUEST['to'] ?? $_REQUEST['To'] ?? '';
    $body = $_REQUEST['body'] ?? $_REQUEST['Body'] ?? '';
    $leadId = $_REQUEST['lead_id'] ?? '';

    if (empty($to) || empty($body)) {
        echo json_encode(['success' => false, 'error' => 'Phone number and message are required']);
        return;
    }

    $config = getTwilioConfig();
    if (empty($config['account_sid']) || empty($config['auth_token']) || empty($config['phone_number'])) {
        echo json_encode(['success' => false, 'error' => 'Twilio not configured']);
        return;
    }

    $cleanTo = cleanPhone($to);
    $fromNumber = $config['phone_number'];

    // Send SMS via Twilio API
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Messages.json";

    $postData = [
        'To' => $cleanTo,
        'From' => $fromNumber,
        'Body' => $body
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $config['account_sid'] . ':' . $config['auth_token']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        $GLOBALS['log']->info("SMS sent successfully to $cleanTo, SID: " . ($result['sid'] ?? 'unknown'));

        // Log the outgoing SMS to lead_journey table
        global $current_user;
        $db = $GLOBALS['db'];

        // Determine lead ID - use provided leadId or lookup by phone
        $parentId = $leadId;
        $recipientName = formatPhoneForDisplay($cleanTo);

        if (empty($parentId)) {
            $recipientInfo = lookupCallerByPhone($cleanTo);
            if ($recipientInfo && $recipientInfo['module'] === 'Leads') {
                $parentId = $recipientInfo['record_id'];
                $recipientName = $recipientInfo['name'];
            }
        }

        if (!empty($parentId)) {
            $journeyId = generateGuid();
            $cleanToDigits = preg_replace('/[^0-9]/', '', $cleanTo);
            $threadId = 'sms_' . $cleanToDigits;

            $touchpointData = json_encode([
                'from' => $fromNumber,
                'to' => $cleanTo,
                'body' => $body,
                'message_sid' => $result['sid'] ?? '',
                'direction' => 'outbound'
            ]);

            $sql = "INSERT INTO lead_journey (id, name, date_entered, date_modified, modified_user_id, created_by,
                    description, deleted, parent_type, parent_id, touchpoint_type, touchpoint_date,
                    touchpoint_data, source, assigned_user_id, thread_id)
                    VALUES (
                        '" . $db->quote($journeyId) . "',
                        'SMS to " . $db->quote($recipientName) . "',
                        NOW(), NOW(),
                        '" . $db->quote($current_user->id ?? '1') . "',
                        '" . $db->quote($current_user->id ?? '1') . "',
                        '" . $db->quote($body) . "',
                        0,
                        'Leads',
                        '" . $db->quote($parentId) . "',
                        'outbound_sms',
                        NOW(),
                        '" . $db->quote($touchpointData) . "',
                        'Twilio',
                        '" . $db->quote($current_user->id ?? '1') . "',
                        '" . $db->quote($threadId) . "'
                    )";

            try {
                $db->query($sql);
                $GLOBALS['log']->info("Outgoing SMS logged to lead_journey for lead: $parentId");
            } catch (Exception $e) {
                $GLOBALS['log']->error("Failed to log outgoing SMS to lead_journey: " . $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'message_sid' => $result['sid'] ?? '',
            'status' => $result['status'] ?? 'sent'
        ]);
    } else {
        $error = $result['message'] ?? 'Failed to send SMS';
        $GLOBALS['log']->error("SMS send failed: $error");
        echo json_encode(['success' => false, 'error' => $error]);
    }
}

/**
 * Format phone number for display
 */
function formatPhoneForDisplay($phone) {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }
    return $phone;
}

/**
 * Handle incoming calls - route to browser clients
 * This is called when someone calls a Twilio phone number
 * It dials all users assigned to that number via browser (Twilio Client)
 */
function handleIncomingCall() {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
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
    $recordingUrl = $baseUrl . '?action=recording';
    $statusUrl = $baseUrl . '?action=status';
    $recordingEnabled = $GLOBALS['sugar_config']['twilio_enable_recordings'] ?? true;

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';

    if (!empty($assignedUsers)) {
        // Dial all assigned browser clients simultaneously
        // First one to answer gets the call
        echo '<Dial timeout="30" action="' . h($voicemailUrl) . '" method="POST"';
        if ($recordingEnabled) {
            echo ' record="record-from-answer-dual" recordingStatusCallback="' . h($recordingUrl) . '" recordingStatusCallbackEvent="completed"';
        }
        echo '>';

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
            echo '<Dial timeout="20" action="' . h($voicemailUrl) . '" method="POST"';
            if ($recordingEnabled) {
                echo ' record="record-from-answer-dual" recordingStatusCallback="' . h($recordingUrl) . '" recordingStatusCallbackEvent="completed"';
            }
            echo '>';
            echo '<Number statusCallback="' . h($statusUrl) . '" statusCallbackEvent="initiated ringing answered completed">';
            echo h(cleanPhone($fallbackPhone));
            echo '</Number>';
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
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
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
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
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

    // Generate identity from current user
    // SuiteCRM uses $current_user global, not $_SESSION
    global $current_user;
    $userId = null;

    // Try multiple sources for user ID
    if (!empty($current_user->id)) {
        $userId = $current_user->id;
    } elseif (isset($_SESSION['authenticated_user_id'])) {
        $userId = $_SESSION['authenticated_user_id'];
    } elseif (isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
    }

    if (empty($userId)) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated. Please log in.']);
        return;
    }

    $identity = 'agent_' . $userId;
    $GLOBALS['log']->info("Twilio Token - Generated identity: $identity for user: $userId");

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
 * Handle all voice calls - both incoming and outgoing
 * This is the unified TwiML App Voice URL handler
 *
 * Detection logic:
 * - Incoming calls: From is a phone number (external caller), To is the Twilio number
 * - Outgoing calls: From is a client identity (client:agent_xxx), To is the destination number
 */
function handleVoiceCall() {
    // Clear any output buffers to ensure clean TwiML
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/xml');

    // Log all incoming parameters for debugging
    $GLOBALS['log']->info("Voice Call - All REQUEST params: " . json_encode($_REQUEST));
    $GLOBALS['log']->info("Voice Call - All POST params: " . json_encode($_POST));
    $GLOBALS['log']->info("Voice Call - All GET params: " . json_encode($_GET));

    // Extract parameters - check multiple cases/formats
    $to = $_REQUEST['To'] ?? $_REQUEST['to'] ?? $_POST['To'] ?? $_POST['to'] ?? '';
    $from = $_REQUEST['From'] ?? $_REQUEST['from'] ?? $_POST['From'] ?? $_POST['from'] ?? '';
    $direction = $_REQUEST['Direction'] ?? $_REQUEST['direction'] ?? '';
    $callSid = $_REQUEST['CallSid'] ?? $_REQUEST['callSid'] ?? '';

    // Also check for PhoneNumber parameter (alternative name)
    if (empty($to)) {
        $to = $_REQUEST['PhoneNumber'] ?? $_REQUEST['phoneNumber'] ?? $_REQUEST['phone'] ?? '';
    }

    $GLOBALS['log']->info("Voice Call - Direction: '$direction', To: '$to', From: '$from', CallSid: '$callSid'");

    // Determine if this is an incoming or outgoing call
    // IMPORTANT: Direction=inbound from Twilio means "inbound to TwiML App" NOT "incoming to user"
    // - Browser outgoing call: From=client:xxx, Direction=inbound (to TwiML App)
    // - External incoming call: From=+1xxx (phone number), Direction=inbound

    $isIncoming = false;
    $detectionMethod = 'unknown';

    // FIRST: Check if From is a browser client - this is ALWAYS an outgoing call
    if (strpos($from, 'client:') === 0) {
        // From browser client - this is an OUTGOING call (browser -> phone)
        $isIncoming = false;
        $detectionMethod = 'From starts with client: (browser outgoing)';
    } elseif (strpos($from, 'agent_') !== false || strpos($from, 'user_') !== false) {
        // Alternative format for browser client identity
        $isIncoming = false;
        $detectionMethod = 'From contains agent_/user_ (browser outgoing)';
    } elseif (!empty($from) && isPhoneNumber($from)) {
        // From is a regular phone number - this is an INCOMING call (phone -> browser)
        $isIncoming = true;
        $detectionMethod = 'From is phone number (external incoming)';
    } elseif (!empty($to) && isOurTwilioNumber($to)) {
        // To is one of our Twilio numbers - this is incoming
        $isIncoming = true;
        $detectionMethod = 'To is our Twilio number';
    } else {
        // Default to outgoing if we can't determine
        $isIncoming = false;
        $detectionMethod = 'default (assumed outgoing)';
    }

    $GLOBALS['log']->info("Voice Call - Detected as " . ($isIncoming ? "INCOMING" : "OUTGOING") . " via: $detectionMethod");

    if ($isIncoming) {
        // Handle incoming call - route to browser clients
        handleIncomingVoiceCall($to, $from, $callSid);
    } else {
        // Handle outgoing call from browser
        handleOutgoingVoiceCall($to, $from);
    }
}

/**
 * Check if a string looks like a phone number (not a client identity)
 */
function isPhoneNumber($str) {
    // Client identities contain 'client:', 'agent_', or 'user_'
    if (strpos($str, 'client:') !== false || strpos($str, 'agent_') !== false || strpos($str, 'user_') !== false) {
        return false;
    }
    // Phone numbers should have at least 7 digits
    $digits = preg_replace('/[^0-9]/', '', $str);
    return strlen($digits) >= 7;
}

/**
 * Check if a phone number is one of our configured Twilio numbers
 */
function isOurTwilioNumber($phoneNumber) {
    $db = $GLOBALS['db'];
    $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
    $last10 = substr($cleanPhone, -10);

    if (strlen($last10) < 10) {
        return false;
    }

    // Check in twilio_integration table
    $sql = "SELECT COUNT(*) as cnt FROM twilio_integration
            WHERE deleted = 0
            AND REPLACE(REPLACE(REPLACE(phone_number, '-', ''), ' ', ''), '+', '') LIKE '%" . $db->quote($last10) . "%'";

    $result = $db->query($sql);
    $row = $db->fetchByAssoc($result);

    if ($row && intval($row['cnt']) > 0) {
        return true;
    }

    // Also check environment/config Twilio number
    $config = getTwilioConfig();
    if (!empty($config['phone_number'])) {
        $configPhone = preg_replace('/[^0-9]/', '', $config['phone_number']);
        if (substr($configPhone, -10) === $last10) {
            return true;
        }
    }

    return false;
}

/**
 * Handle incoming voice call - route to browser clients
 */
function handleIncomingVoiceCall($calledNumber, $callerNumber, $callSid) {
    $GLOBALS['log']->info("Incoming Voice Call - To: $calledNumber, From: $callerNumber, CallSid: $callSid");

    // Clean the called number for lookup
    $cleanCalledNumber = cleanPhone($calledNumber);

    // Find all users assigned to this phone number in twilio_integration table
    $assignedUsers = getUsersForPhoneNumber($cleanCalledNumber);

    $GLOBALS['log']->info("Incoming Voice Call - Found " . count($assignedUsers) . " users for number $cleanCalledNumber");

    $baseUrl = getWebhookBaseUrl();
    $voicemailUrl = $baseUrl . '?action=twiml&dial_action=voicemail&from=' . urlencode($callerNumber);
    $statusUrl = $baseUrl . '?action=status';
    $recordingUrl = $baseUrl . '?action=recording';
    $recordingEnabled = $GLOBALS['sugar_config']['twilio_enable_recordings'] ?? true;

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';

    if (!empty($assignedUsers)) {
        // Dial all assigned browser clients simultaneously
        // First one to answer gets the call
        echo '<Dial timeout="30" action="' . h($voicemailUrl) . '" method="POST"';
        if ($recordingEnabled) {
            echo ' record="record-from-answer-dual" recordingStatusCallback="' . h($recordingUrl) . '" recordingStatusCallbackEvent="completed"';
        }
        echo '>';

        foreach ($assignedUsers as $userId) {
            // Client identity must match what's used in token generation
            $clientIdentity = 'agent_' . $userId;
            echo '<Client>' . h($clientIdentity) . '</Client>';
            $GLOBALS['log']->info("Incoming Voice Call - Dialing client: $clientIdentity");
        }

        echo '</Dial>';
    } else {
        // No users assigned to this number, try fallback phone or go to voicemail
        $fallbackPhone = $GLOBALS['sugar_config']['twilio_fallback_phone'] ?? '';

        if ($fallbackPhone) {
            $GLOBALS['log']->info("Incoming Voice Call - No users found, trying fallback: $fallbackPhone");
            echo '<Say voice="Polly.Joanna">Please hold while we connect your call.</Say>';
            echo '<Dial timeout="20" action="' . h($voicemailUrl) . '" method="POST"';
            if ($recordingEnabled) {
                echo ' record="record-from-answer-dual" recordingStatusCallback="' . h($recordingUrl) . '" recordingStatusCallbackEvent="completed"';
            }
            echo '>';
            echo '<Number statusCallback="' . h($statusUrl) . '" statusCallbackEvent="initiated ringing answered completed">' . h(cleanPhone($fallbackPhone)) . '</Number>';
            echo '</Dial>';
        } else {
            $GLOBALS['log']->info("Incoming Voice Call - No users or fallback, going to voicemail");
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
 * Handle outgoing voice call from browser client
 */
function handleOutgoingVoiceCall($to, $from) {
    $GLOBALS['log']->info("Outgoing Voice Call - Raw To: $to, From: $from");

    // Extract user ID from the From parameter (client:agent_{userId})
    $userId = null;
    if (preg_match('/agent_([a-f0-9\-]+)/i', $from, $matches)) {
        $userId = $matches[1];
        $GLOBALS['log']->info("Outgoing Voice Call - Extracted user ID: $userId");
    }

    // Get CallerId from request or use config
    $callerId = $_REQUEST['CallerId'] ?? '';

    // Get Twilio config for this user's caller ID
    $config = getTwilioConfig($userId);
    if (empty($callerId)) {
        $callerId = $config['phone_number'] ?? '';
    }

    $GLOBALS['log']->info("Outgoing Voice Call - Using caller ID: $callerId from config");

    $baseUrl = getWebhookBaseUrl();
    $statusUrl = $baseUrl . '?action=status';
    $recordingUrl = $baseUrl . '?action=recording';
    $recordingEnabled = $GLOBALS['sugar_config']['twilio_enable_recordings'] ?? true;

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';

    // Check if To has any digits before cleaning
    $toDigits = preg_replace('/[^0-9]/', '', $to);
    if (strlen($toDigits) >= 7) {
        // Clean and format the destination number
        $cleanTo = cleanPhone($to);
        $GLOBALS['log']->info("Outgoing Voice Call - Clean To: $cleanTo, CallerId: $callerId, Recording: " . ($recordingEnabled ? 'enabled' : 'disabled'));

        // Dial the destination number with recording enabled
        // The browser (Twilio Client) is already connected, this connects to the recipient
        echo '<Dial callerId="' . h($callerId) . '" timeout="30" action="' . h($statusUrl) . '" method="POST"';
        if ($recordingEnabled) {
            echo ' record="record-from-answer-dual" recordingStatusCallback="' . h($recordingUrl) . '" recordingStatusCallbackEvent="completed"';
        }
        echo '>';
        // Add statusCallback to <Number> to get call status updates for outbound calls
        echo '<Number statusCallback="' . h($statusUrl) . '" statusCallbackEvent="initiated ringing answered completed">';
        echo h($cleanTo);
        echo '</Number>';
        echo '</Dial>';
    } else {
        $GLOBALS['log']->error("Outgoing Voice Call - No valid destination number. Raw To: '$to', Digits: '$toDigits'");
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
    // Add type=outbound to help identify this as an outbound call in status callbacks
    $statusUrl = $baseUrl . '?action=status&type=outbound&customer=' . urlencode($to);
    $dialActionUrl = $baseUrl . '?action=twiml&dial_action=dial_status';
    $recordingEnabled = $GLOBALS['sugar_config']['twilio_enable_recordings'] ?? false;
    $recordingUrl = $baseUrl . '?action=recording&type=outbound&customer=' . urlencode($to);

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    if ($to) {
        // Agent hears this message, then gets connected to the recipient
        echo '<Say voice="Polly.Joanna">Connecting you to ' . h(formatPhoneForSpeech($to)) . '. Please hold.</Say>';
        echo '<Dial callerId="' . h($callerId) . '" timeout="30" action="' . h($dialActionUrl) . '" method="POST"';
        if ($recordingEnabled) {
            echo ' record="record-from-answer-dual" recordingStatusCallback="' . h($recordingUrl) . '"';
        }
        echo '>';
        // Add statusCallback to <Number> to get call status updates
        echo '<Number statusCallback="' . h($statusUrl) . '" statusCallbackEvent="initiated ringing answered completed">';
        echo h($to);
        echo '</Number>';
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

function getTwilioConfig($userId = null) {
    global $sugar_config, $current_user;

    // Determine which user to get config for
    if (empty($userId) && !empty($current_user->id)) {
        $userId = $current_user->id;
    }

    // Try to get config from twilio_integration table for this user
    if (!empty($userId)) {
        $db = $GLOBALS['db'];
        $sql = "SELECT account_sid, auth_token, phone_number
                FROM twilio_integration
                WHERE deleted = 0
                AND assigned_user_id = '" . $db->quote($userId) . "'
                ORDER BY date_modified DESC
                LIMIT 1";

        $result = $db->query($sql);
        $row = $db->fetchByAssoc($result);

        if ($row && !empty($row['account_sid']) && !empty($row['phone_number'])) {
            $GLOBALS['log']->info("Twilio Config - Using user config for user $userId: " . $row['phone_number']);
            return [
                'account_sid' => $row['account_sid'],
                'auth_token' => $row['auth_token'],
                'phone_number' => $row['phone_number'],
            ];
        }
    }

    // Fall back to environment variables / sugar_config
    $GLOBALS['log']->info("Twilio Config - Using default config from env/config");
    return [
        'account_sid' => getenv('TWILIO_ACCOUNT_SID') ?: ($sugar_config['twilio_account_sid'] ?? ''),
        'auth_token' => getenv('TWILIO_AUTH_TOKEN') ?: ($sugar_config['twilio_auth_token'] ?? ''),
        'phone_number' => getenv('TWILIO_PHONE_NUMBER') ?: ($sugar_config['twilio_phone_number'] ?? ''),
    ];
}

/**
 * Get Twilio config for a specific phone number (for incoming calls/SMS)
 */
function getTwilioConfigByPhone($phoneNumber) {
    $db = $GLOBALS['db'];
    $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
    $last10 = substr($cleanPhone, -10);

    $sql = "SELECT account_sid, auth_token, phone_number
            FROM twilio_integration
            WHERE deleted = 0
            AND REPLACE(REPLACE(REPLACE(phone_number, '-', ''), ' ', ''), '+', '') LIKE '%" . $db->quote($last10) . "%'
            ORDER BY date_modified DESC
            LIMIT 1";

    $result = $db->query($sql);
    $row = $db->fetchByAssoc($result);

    if ($row && !empty($row['account_sid'])) {
        return [
            'account_sid' => $row['account_sid'],
            'auth_token' => $row['auth_token'],
            'phone_number' => $row['phone_number'],
        ];
    }

    // Fall back to default config
    return getTwilioConfig();
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
