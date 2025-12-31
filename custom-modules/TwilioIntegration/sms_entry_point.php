<?php
/**
 * SMS Webhook Entry Point
 * Handles inbound SMS from Twilio and status callbacks
 *
 * Twilio Webhook URL: https://domain.com/legacy/index.php?entryPoint=sms_webhook
 *
 * This entry point delegates to the handleSms() function in twilio_webhook.php
 * to ensure consistent behavior and avoid code duplication.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

// Clear any output buffers to ensure clean response
while (ob_get_level()) {
    ob_end_clean();
}

$GLOBALS['log']->info("SMS Entry Point - Request received: " . json_encode($_REQUEST));

// Get request parameters
$from = $_REQUEST['From'] ?? '';
$to = $_REQUEST['To'] ?? '';
$body = $_REQUEST['Body'] ?? '';
$messageSid = $_REQUEST['MessageSid'] ?? '';
$messageStatus = $_REQUEST['MessageStatus'] ?? '';
$numMedia = isset($_REQUEST['NumMedia']) ? intval($_REQUEST['NumMedia']) : 0;

// Handle status callbacks (delivery status, etc.)
if (!empty($messageStatus)) {
    $GLOBALS['log']->info("SMS Entry Point - Status callback: $messageStatus for SID: $messageSid");
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    exit;
}

// Handle inbound SMS
$GLOBALS['log']->info("SMS Entry Point - Inbound SMS from: $from, to: $to, body: " . substr($body, 0, 50));

// Get database instance
$db = DBManagerFactory::getInstance();

// Clean phone number for lookup
$cleanFrom = preg_replace('/[^0-9]/', '', $from);
$last10 = substr($cleanFrom, -10);

// Find lead by phone number (check all phone fields)
$leadInfo = null;
if (strlen($last10) >= 7) {
    $sql = "SELECT id, first_name, last_name, assigned_user_id, funnel_type_c
            FROM leads
            WHERE deleted = 0
            AND (
                REPLACE(REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10) . "%'
            )
            ORDER BY date_modified DESC
            LIMIT 1";

    $result = $db->query($sql);
    if ($result) {
        $row = $db->fetchByAssoc($result);
        if ($row) {
            $leadInfo = [
                'id' => $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'assigned_user_id' => $row['assigned_user_id'],
                'funnel_type' => $row['funnel_type_c'] ?? ''
            ];
            $GLOBALS['log']->info("SMS Entry Point - Found lead: " . $leadInfo['id'] . " - " . $leadInfo['name']);
        }
    }
}

// Find assigned user for this Twilio number
$cleanTo = preg_replace('/[^0-9]/', '', $to);
$toLast10 = substr($cleanTo, -10);

$assignedUserId = '1'; // Default to admin
$sql = "SELECT assigned_user_id FROM twilio_integration
        WHERE deleted = 0
        AND REPLACE(REPLACE(REPLACE(phone_number, '-', ''), ' ', ''), '+', '') LIKE '%" . $db->quote($toLast10) . "%'
        LIMIT 1";
$result = $db->query($sql);
if ($result) {
    $twilioRow = $db->fetchByAssoc($result);
    if ($twilioRow && !empty($twilioRow['assigned_user_id'])) {
        $assignedUserId = $twilioRow['assigned_user_id'];
        $GLOBALS['log']->info("SMS Entry Point - Found assigned user: $assignedUserId for phone: $to");
    }
}

// Use lead's assigned user if we found a lead and no twilio user
if ($leadInfo && $assignedUserId === '1' && !empty($leadInfo['assigned_user_id'])) {
    $assignedUserId = $leadInfo['assigned_user_id'];
}

// Log to lead_journey if we found a lead
if ($leadInfo) {
    // Generate GUID for the journey entry
    $journeyId = create_guid();
    $callerName = $leadInfo['name'] ?: 'Unknown';
    $threadId = 'sms_' . $cleanFrom;

    // Collect media URLs if any
    $mediaUrls = [];
    for ($i = 0; $i < $numMedia; $i++) {
        $mediaUrl = $_REQUEST["MediaUrl$i"] ?? '';
        if (!empty($mediaUrl)) {
            $mediaUrls[] = $mediaUrl;
        }
    }

    $touchpointData = json_encode([
        'from' => $from,
        'to' => $to,
        'body' => $body,
        'message_sid' => $messageSid,
        'direction' => 'inbound',
        'media_urls' => $mediaUrls
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
                '" . $db->quote($leadInfo['id']) . "',
                'SMS_Inbound',
                NOW(),
                '" . $db->quote($touchpointData) . "',
                'Twilio',
                '" . $db->quote($assignedUserId) . "',
                '" . $db->quote($threadId) . "'
            )";

    try {
        $db->query($sql);
        $GLOBALS['log']->info("SMS Entry Point - Logged inbound SMS to lead_journey for lead: " . $leadInfo['id']);
    } catch (Exception $e) {
        $GLOBALS['log']->error("SMS Entry Point - Failed to log SMS: " . $e->getMessage());
    }

    // Also send a WebSocket notification to the assigned user
    try {
        $notificationPayload = json_encode([
            'event' => 'sms_inbound',
            'user_id' => $assignedUserId,
            'lead_id' => $leadInfo['id'],
            'lead_name' => $leadInfo['name'],
            'from' => $from,
            'body' => $body,
            'message_sid' => $messageSid,
            'timestamp' => date('c')
        ]);

        // Queue notification for WebSocket delivery
        $notifId = create_guid();
        $sql = "INSERT INTO notification_queue (id, user_id, notification_type, payload, status, created_at)
                VALUES (
                    '" . $db->quote($notifId) . "',
                    '" . $db->quote($assignedUserId) . "',
                    'sms_inbound',
                    '" . $db->quote($notificationPayload) . "',
                    'pending',
                    NOW()
                )";
        $db->query($sql);
        $GLOBALS['log']->info("SMS Entry Point - Queued notification for user: $assignedUserId");
    } catch (Exception $e) {
        $GLOBALS['log']->error("SMS Entry Point - Failed to queue notification: " . $e->getMessage());
    }
} else {
    $GLOBALS['log']->info("SMS Entry Point - No matching lead found for phone: $from");
}

// Return TwiML response (empty = no auto-reply)
header('Content-Type: application/xml');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response></Response>';
exit;
