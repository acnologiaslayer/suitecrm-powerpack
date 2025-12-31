<?php
/**
 * SMS Webhook Entry Point - Place in /bitnami/suitecrm/public/legacy/
 *
 * Twilio Messaging Webhook URL:
 * https://customer-relations.boomershub.com/legacy/sms_webhook.php
 *
 * Method: HTTP POST
 */

// Disable all error output - we need clean XML response
error_reporting(0);
ini_set('display_errors', 0);

// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Log to file for debugging
$logFile = '/tmp/sms_webhook.log';
$timestamp = date('Y-m-d H:i:s');

// Get request data
$from = isset($_REQUEST['From']) ? $_REQUEST['From'] : '';
$to = isset($_REQUEST['To']) ? $_REQUEST['To'] : '';
$body = isset($_REQUEST['Body']) ? $_REQUEST['Body'] : '';
$messageSid = isset($_REQUEST['MessageSid']) ? $_REQUEST['MessageSid'] : '';
$messageStatus = isset($_REQUEST['MessageStatus']) ? $_REQUEST['MessageStatus'] : '';
$numMedia = isset($_REQUEST['NumMedia']) ? intval($_REQUEST['NumMedia']) : 0;

// Log the request
file_put_contents($logFile, "[$timestamp] SMS Webhook Request:\n", FILE_APPEND);
file_put_contents($logFile, "  From: $from\n", FILE_APPEND);
file_put_contents($logFile, "  To: $to\n", FILE_APPEND);
file_put_contents($logFile, "  Body: " . substr($body, 0, 100) . "\n", FILE_APPEND);
file_put_contents($logFile, "  MessageSid: $messageSid\n", FILE_APPEND);
file_put_contents($logFile, "  Status: $messageStatus\n", FILE_APPEND);
file_put_contents($logFile, "  NumMedia: $numMedia\n", FILE_APPEND);
file_put_contents($logFile, "  REQUEST: " . json_encode($_REQUEST) . "\n\n", FILE_APPEND);

// Handle status callbacks (delivery reports)
if (!empty($messageStatus)) {
    file_put_contents($logFile, "[$timestamp] Status callback - returning empty response\n\n", FILE_APPEND);
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response></Response>';
    exit;
}

// Bootstrap SuiteCRM - we're in the legacy root
define('sugarEntry', true);

try {
    require_once('include/entryPoint.php');
    file_put_contents($logFile, "[$timestamp] SuiteCRM loaded successfully\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents($logFile, "[$timestamp] ERROR loading SuiteCRM: " . $e->getMessage() . "\n\n", FILE_APPEND);
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response></Response>';
    exit;
}

// Now process the inbound SMS
try {
    $db = DBManagerFactory::getInstance();

    // Clean phone numbers
    $cleanFrom = preg_replace('/[^0-9]/', '', $from);
    $last10From = substr($cleanFrom, -10);

    $cleanTo = preg_replace('/[^0-9]/', '', $to);
    $last10To = substr($cleanTo, -10);

    file_put_contents($logFile, "[$timestamp] Looking up lead with phone: $last10From\n", FILE_APPEND);

    // Find lead by phone number
    $leadId = null;
    $leadName = 'Unknown';
    $assignedUserId = '1';

    if (strlen($last10From) >= 7) {
        $sql = "SELECT id, first_name, last_name, assigned_user_id
                FROM leads
                WHERE deleted = 0
                AND (
                    REPLACE(REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10From) . "%'
                    OR REPLACE(REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10From) . "%'
                    OR REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%" . $db->quote($last10From) . "%'
                )
                ORDER BY date_modified DESC
                LIMIT 1";

        $result = $db->query($sql);
        if ($result) {
            $row = $db->fetchByAssoc($result);
            if ($row) {
                $leadId = $row['id'];
                $leadName = trim($row['first_name'] . ' ' . $row['last_name']);
                if (!empty($row['assigned_user_id'])) {
                    $assignedUserId = $row['assigned_user_id'];
                }
                file_put_contents($logFile, "[$timestamp] Found lead: $leadId - $leadName\n", FILE_APPEND);
            }
        }
    }

    // Find user assigned to this Twilio number
    if (strlen($last10To) >= 7) {
        $sql = "SELECT assigned_user_id FROM twilio_integration
                WHERE deleted = 0
                AND REPLACE(REPLACE(REPLACE(phone_number, '-', ''), ' ', ''), '+', '') LIKE '%" . $db->quote($last10To) . "%'
                LIMIT 1";
        $result = $db->query($sql);
        if ($result) {
            $row = $db->fetchByAssoc($result);
            if ($row && !empty($row['assigned_user_id'])) {
                $assignedUserId = $row['assigned_user_id'];
                file_put_contents($logFile, "[$timestamp] Found Twilio user: $assignedUserId\n", FILE_APPEND);
            }
        }
    }

    // Log to lead_journey if we found a lead
    if ($leadId) {
        $journeyId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

        $threadId = 'sms_' . $cleanFrom;

        // Collect media URLs
        $mediaUrls = [];
        for ($i = 0; $i < $numMedia; $i++) {
            if (isset($_REQUEST["MediaUrl$i"]) && !empty($_REQUEST["MediaUrl$i"])) {
                $mediaUrls[] = $_REQUEST["MediaUrl$i"];
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

        $sql = "INSERT INTO lead_journey (
                    id, name, date_entered, date_modified, modified_user_id, created_by,
                    description, deleted, parent_type, parent_id, touchpoint_type, touchpoint_date,
                    touchpoint_data, source, assigned_user_id, thread_id
                ) VALUES (
                    '" . $db->quote($journeyId) . "',
                    'SMS from " . $db->quote($leadName) . "',
                    NOW(), NOW(),
                    '" . $db->quote($assignedUserId) . "',
                    '" . $db->quote($assignedUserId) . "',
                    '" . $db->quote($body) . "',
                    0,
                    'Leads',
                    '" . $db->quote($leadId) . "',
                    'SMS_Inbound',
                    NOW(),
                    '" . $db->quote($touchpointData) . "',
                    'Twilio',
                    '" . $db->quote($assignedUserId) . "',
                    '" . $db->quote($threadId) . "'
                )";

        $db->query($sql);
        file_put_contents($logFile, "[$timestamp] Logged SMS to lead_journey for lead: $leadId\n", FILE_APPEND);
    } else {
        file_put_contents($logFile, "[$timestamp] No matching lead found for phone: $from\n", FILE_APPEND);
    }

} catch (Exception $e) {
    file_put_contents($logFile, "[$timestamp] ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
}

file_put_contents($logFile, "[$timestamp] Returning TwiML response\n\n", FILE_APPEND);

// Return TwiML MessagingResponse (empty = no auto-reply)
header('Content-Type: text/xml');
header('Cache-Control: no-cache');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response>';
// Uncomment below to send an auto-reply:
// echo '<Message>Thanks for your message! We will get back to you soon.</Message>';
echo '</Response>';
exit;
