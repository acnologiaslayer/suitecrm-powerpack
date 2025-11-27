<?php
/**
 * Twilio SMS Webhook Handler
 * Handles incoming SMS and SMS status updates from Twilio
 */
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class TwilioIntegrationViewSms_webhook extends SugarView {
    
    public function display() {
        // Disable view rendering
        $this->options['show_header'] = false;
        $this->options['show_footer'] = false;
        $this->options['show_title'] = false;
        $this->options['show_subpanels'] = false;
        
        // Get Twilio request data
        $messageSid = $_POST['MessageSid'] ?? $_POST['SmsSid'] ?? $_GET['MessageSid'] ?? '';
        $messageStatus = $_POST['MessageStatus'] ?? $_POST['SmsStatus'] ?? $_GET['MessageStatus'] ?? '';
        $from = $_POST['From'] ?? $_GET['From'] ?? '';
        $to = $_POST['To'] ?? $_GET['To'] ?? '';
        $body = $_POST['Body'] ?? $_GET['Body'] ?? '';
        $numMedia = $_POST['NumMedia'] ?? $_GET['NumMedia'] ?? 0;
        
        // Log the webhook for debugging
        $GLOBALS['log']->info("Twilio SMS Webhook: MessageSid=$messageSid, Status=$messageStatus, From=$from, To=$to");
        
        // Determine if this is an incoming message or a status update
        if (!empty($body)) {
            // This is an incoming SMS
            $this->handleIncomingSMS($messageSid, $from, $to, $body, $numMedia);
        } else if (!empty($messageStatus)) {
            // This is a status callback for an outgoing message
            $this->handleStatusUpdate($messageSid, $messageStatus, $from, $to);
        }
        
        // Return TwiML response (empty for SMS webhooks, or auto-reply)
        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
        exit;
    }
    
    /**
     * Handle incoming SMS message
     */
    private function handleIncomingSMS($messageSid, $from, $to, $body, $numMedia) {
        global $db;
        
        $GLOBALS['log']->info("Twilio: Incoming SMS from $from: $body");
        
        // Find the contact/lead by phone number
        $contact = $this->findContactByPhone($from);
        
        // Create a note to log the SMS
        $note = BeanFactory::newBean('Notes');
        $note->name = "SMS from " . ($contact ? $contact['name'] : $from);
        $note->description = $body;
        $note->description .= "\n\n---";
        $note->description .= "\nTwilio Message SID: $messageSid";
        $note->description .= "\nFrom: $from";
        $note->description .= "\nTo: $to";
        $note->description .= "\nDirection: Inbound";
        $note->description .= "\nReceived: " . date('Y-m-d H:i:s');
        
        if ($numMedia > 0) {
            $note->description .= "\nMedia attachments: $numMedia";
            
            // Log media URLs if present
            for ($i = 0; $i < $numMedia; $i++) {
                $mediaUrl = $_POST["MediaUrl$i"] ?? '';
                $mediaType = $_POST["MediaContentType$i"] ?? '';
                if ($mediaUrl) {
                    $note->description .= "\nMedia $i: $mediaUrl ($mediaType)";
                }
            }
        }
        
        // Link to contact if found
        if ($contact) {
            $note->parent_type = $contact['module'];
            $note->parent_id = $contact['id'];
            
            if (!empty($contact['assigned_user_id'])) {
                $note->assigned_user_id = $contact['assigned_user_id'];
            }
        }
        
        $note->save();
        
        $GLOBALS['log']->info("Twilio: Logged incoming SMS as note {$note->id}");
        
        // Optionally create a task for follow-up
        $this->createFollowUpTask($contact, $from, $body);
    }
    
    /**
     * Handle SMS status update (for outgoing messages)
     */
    private function handleStatusUpdate($messageSid, $status, $from, $to) {
        $GLOBALS['log']->info("Twilio SMS Status: $messageSid is now $status");
        
        // Could update existing note/record with delivery status
        // For now, just log it
        
        if ($status === 'failed' || $status === 'undelivered') {
            $GLOBALS['log']->warn("Twilio SMS Failed: $messageSid to $to - Status: $status");
            
            // Could create a notification or task about failed message
        }
    }
    
    /**
     * Create a follow-up task for incoming SMS
     */
    private function createFollowUpTask($contact, $from, $body) {
        $config = TwilioIntegration::getConfig();
        
        // Only create task if auto-logging is enabled
        if (empty($config['enable_auto_logging'])) {
            return;
        }
        
        $task = BeanFactory::newBean('Tasks');
        $task->name = "Follow up on SMS from " . ($contact ? $contact['name'] : $from);
        $task->status = 'Not Started';
        $task->priority = 'Medium';
        $task->date_due = date('Y-m-d', strtotime('+1 day'));
        $task->description = "Received SMS:\n\n" . $body;
        $task->description .= "\n\nFrom: $from";
        
        if ($contact) {
            $task->parent_type = $contact['module'];
            $task->parent_id = $contact['id'];
            
            if (!empty($contact['assigned_user_id'])) {
                $task->assigned_user_id = $contact['assigned_user_id'];
            }
        }
        
        $task->save();
        
        $GLOBALS['log']->info("Twilio: Created follow-up task {$task->id} for SMS from $from");
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
