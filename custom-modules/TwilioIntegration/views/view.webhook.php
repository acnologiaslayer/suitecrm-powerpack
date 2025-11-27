<?php
/**
 * Twilio Voice Webhook Handler
 * Handles incoming calls and call status updates from Twilio
 */
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class TwilioIntegrationViewWebhook extends SugarView {
    
    public function display() {
        // Disable view rendering
        $this->options['show_header'] = false;
        $this->options['show_footer'] = false;
        $this->options['show_title'] = false;
        $this->options['show_subpanels'] = false;
        
        // Get Twilio request data
        $callSid = $_POST['CallSid'] ?? $_GET['CallSid'] ?? '';
        $callStatus = $_POST['CallStatus'] ?? $_GET['CallStatus'] ?? '';
        $from = $_POST['From'] ?? $_GET['From'] ?? '';
        $to = $_POST['To'] ?? $_GET['To'] ?? '';
        $direction = $_POST['Direction'] ?? $_GET['Direction'] ?? '';
        $duration = $_POST['CallDuration'] ?? $_GET['CallDuration'] ?? 0;
        $recordingUrl = $_POST['RecordingUrl'] ?? $_GET['RecordingUrl'] ?? '';
        $recordingSid = $_POST['RecordingSid'] ?? $_GET['RecordingSid'] ?? '';
        
        // Log the webhook for debugging
        $GLOBALS['log']->info("Twilio Webhook: CallSid=$callSid, Status=$callStatus, From=$from, To=$to");
        
        // Validate the request is from Twilio (optional but recommended)
        if (!$this->validateTwilioRequest()) {
            $GLOBALS['log']->warn("Twilio Webhook: Invalid request signature");
            // Still process in development, but log warning
        }
        
        // Handle different call statuses
        switch ($callStatus) {
            case 'initiated':
            case 'ringing':
                // Call is starting - could update UI in real-time if needed
                $this->logWebhookEvent('call_initiated', $callSid, $from, $to, $callStatus);
                break;
                
            case 'in-progress':
            case 'answered':
                // Call is active
                $this->logWebhookEvent('call_answered', $callSid, $from, $to, $callStatus);
                break;
                
            case 'completed':
                // Call ended - log to SuiteCRM
                $this->logCompletedCall($callSid, $from, $to, $duration, $recordingUrl, $recordingSid, $direction);
                break;
                
            case 'busy':
            case 'no-answer':
            case 'canceled':
            case 'failed':
                // Call didn't connect
                $this->logFailedCall($callSid, $from, $to, $callStatus, $direction);
                break;
        }
        
        // Return empty 200 response to Twilio
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
        exit;
    }
    
    /**
     * Validate that request is actually from Twilio
     */
    private function validateTwilioRequest() {
        $config = TwilioIntegration::getConfig();
        $authToken = $config['auth_token'];
        
        if (empty($authToken)) {
            return true; // Skip validation if no token configured
        }
        
        // Get Twilio signature from header
        $signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
        if (empty($signature)) {
            return false;
        }
        
        // Build the URL that was called
        global $sugar_config;
        $url = $sugar_config['site_url'] . '/legacy/index.php?' . $_SERVER['QUERY_STRING'];
        
        // Sort POST params and create string
        $params = $_POST;
        ksort($params);
        foreach ($params as $key => $value) {
            $url .= $key . $value;
        }
        
        // Calculate expected signature
        $expectedSignature = base64_encode(hash_hmac('sha1', $url, $authToken, true));
        
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Log webhook event
     */
    private function logWebhookEvent($event, $callSid, $from, $to, $status) {
        $GLOBALS['log']->info("Twilio Event: $event - CallSid=$callSid, From=$from, To=$to, Status=$status");
    }
    
    /**
     * Log completed call to SuiteCRM
     */
    private function logCompletedCall($callSid, $from, $to, $duration, $recordingUrl, $recordingSid, $direction) {
        global $current_user, $db;
        
        // Determine if this is inbound or outbound
        $config = TwilioIntegration::getConfig();
        $twilioNumber = $config['phone_number'];
        
        $isInbound = ($to === $twilioNumber || $direction === 'inbound');
        $contactPhone = $isInbound ? $from : $to;
        
        // Try to find the contact/lead by phone number
        $contact = $this->findContactByPhone($contactPhone);
        
        // Create call record
        $call = BeanFactory::newBean('Calls');
        $call->name = ($isInbound ? "Incoming call from " : "Call to ") . $contactPhone;
        $call->status = 'Held';
        $call->direction = $isInbound ? 'Inbound' : 'Outbound';
        $call->date_start = gmdate('Y-m-d H:i:s');
        $call->duration_hours = floor($duration / 3600);
        $call->duration_minutes = floor(($duration % 3600) / 60);
        
        // Link to contact if found
        if ($contact) {
            $call->parent_type = $contact['module'];
            $call->parent_id = $contact['id'];
            $call->name = ($isInbound ? "Incoming call from " : "Call to ") . $contact['name'];
        }
        
        // Build description
        $call->description = "Twilio Call\n";
        $call->description .= "Call SID: $callSid\n";
        $call->description .= "From: $from\n";
        $call->description .= "To: $to\n";
        $call->description .= "Duration: " . gmdate('H:i:s', $duration) . "\n";
        
        if ($recordingUrl) {
            $call->description .= "Recording: $recordingUrl\n";
            $call->description .= "Recording SID: $recordingSid\n";
        }
        
        // Assign to a user if contact has an assigned user
        if ($contact && !empty($contact['assigned_user_id'])) {
            $call->assigned_user_id = $contact['assigned_user_id'];
        }
        
        $call->save();
        
        $GLOBALS['log']->info("Twilio: Logged call {$call->id} for $contactPhone (Duration: {$duration}s)");
    }
    
    /**
     * Log failed/missed call
     */
    private function logFailedCall($callSid, $from, $to, $status, $direction) {
        global $db;
        
        $config = TwilioIntegration::getConfig();
        $twilioNumber = $config['phone_number'];
        
        $isInbound = ($to === $twilioNumber || $direction === 'inbound');
        $contactPhone = $isInbound ? $from : $to;
        
        $contact = $this->findContactByPhone($contactPhone);
        
        $call = BeanFactory::newBean('Calls');
        $call->name = ($isInbound ? "Missed call from " : "Failed call to ") . $contactPhone;
        $call->status = 'Not Held';
        $call->direction = $isInbound ? 'Inbound' : 'Outbound';
        $call->date_start = gmdate('Y-m-d H:i:s');
        
        if ($contact) {
            $call->parent_type = $contact['module'];
            $call->parent_id = $contact['id'];
            $call->name = ($isInbound ? "Missed call from " : "Failed call to ") . $contact['name'];
            
            if (!empty($contact['assigned_user_id'])) {
                $call->assigned_user_id = $contact['assigned_user_id'];
            }
        }
        
        $call->description = "Twilio Call - $status\n";
        $call->description .= "Call SID: $callSid\n";
        $call->description .= "From: $from\n";
        $call->description .= "To: $to\n";
        $call->description .= "Status: $status\n";
        
        $call->save();
        
        $GLOBALS['log']->info("Twilio: Logged failed call {$call->id} for $contactPhone (Status: $status)");
    }
    
    /**
     * Find contact or lead by phone number
     */
    private function findContactByPhone($phone) {
        global $db;
        
        // Clean phone number for comparison
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $lastDigits = substr($cleanPhone, -10); // Last 10 digits
        
        // Search in Contacts
        $sql = "SELECT id, first_name, last_name, assigned_user_id 
                FROM contacts 
                WHERE deleted = 0 
                AND (
                    REPLACE(REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$lastDigits'
                    OR REPLACE(REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$lastDigits'
                    OR REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$lastDigits'
                )
                LIMIT 1";
        
        $result = $db->query($sql);
        if ($row = $db->fetchByAssoc($result)) {
            return array(
                'module' => 'Contacts',
                'id' => $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'assigned_user_id' => $row['assigned_user_id']
            );
        }
        
        // Search in Leads
        $sql = "SELECT id, first_name, last_name, assigned_user_id 
                FROM leads 
                WHERE deleted = 0 
                AND (
                    REPLACE(REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$lastDigits'
                    OR REPLACE(REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$lastDigits'
                    OR REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$lastDigits'
                )
                LIMIT 1";
        
        $result = $db->query($sql);
        if ($row = $db->fetchByAssoc($result)) {
            return array(
                'module' => 'Leads',
                'id' => $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'assigned_user_id' => $row['assigned_user_id']
            );
        }
        
        return null;
    }
}
