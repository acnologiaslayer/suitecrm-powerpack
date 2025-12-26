<?php
/**
 * Twilio SMS Webhook Handler
 * Handles inbound SMS and delivery status updates
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('modules/TwilioIntegration/TwilioIntegration.php');
require_once('modules/TwilioIntegration/TwilioSecurity.php');
require_once('modules/LeadJourney/LeadJourneyLogger.php');

class TwilioIntegrationViewSms_webhook extends SugarView
{
    public function display()
    {
        // Validate webhook signature for security
        TwilioSecurity::validateOrDie('sms_webhook');

        $action = isset($_REQUEST['sms_action']) ? $_REQUEST['sms_action'] : 'inbound';
        
        switch ($action) {
            case 'inbound':
                $this->handleInboundSMS();
                break;
            case 'status':
                $this->handleStatusCallback();
                break;
            default:
                $this->handleInboundSMS();
        }
        
        die();
    }
    
    /**
     * Handle inbound SMS message
     */
    private function handleInboundSMS()
    {
        $from = isset($_REQUEST['From']) ? $_REQUEST['From'] : '';
        $to = isset($_REQUEST['To']) ? $_REQUEST['To'] : '';
        $body = isset($_REQUEST['Body']) ? $_REQUEST['Body'] : '';
        $messageSid = isset($_REQUEST['MessageSid']) ? $_REQUEST['MessageSid'] : '';
        $numMedia = isset($_REQUEST['NumMedia']) ? intval($_REQUEST['NumMedia']) : 0;
        
        $GLOBALS['log']->info("Inbound SMS - From: $from, To: $to, SID: $messageSid, Body: " . substr($body, 0, 50));
        
        // Find associated lead/contact
        $leadInfo = $this->findLeadByPhone($from);
        
        // Log the SMS
        $this->logInboundSMS($from, $to, $body, $messageSid, $numMedia, $leadInfo);
        
        // Check for auto-reply keywords
        $autoReply = $this->getAutoReply($body, $leadInfo);
        
        // Send TwiML response
        header('Content-Type: application/xml');
        
        $twiml = '<?xml version="1.0" encoding="UTF-8"?>';
        $twiml .= '<Response>';
        
        if (!empty($autoReply)) {
            $twiml .= '<Message>' . htmlspecialchars($autoReply) . '</Message>';
        }
        
        $twiml .= '</Response>';
        
        echo $twiml;
    }
    
    /**
     * Handle SMS delivery status callback
     */
    private function handleStatusCallback()
    {
        $messageSid = isset($_REQUEST['MessageSid']) ? $_REQUEST['MessageSid'] : '';
        $messageStatus = isset($_REQUEST['MessageStatus']) ? $_REQUEST['MessageStatus'] : '';
        $to = isset($_REQUEST['To']) ? $_REQUEST['To'] : '';
        $errorCode = isset($_REQUEST['ErrorCode']) ? $_REQUEST['ErrorCode'] : '';
        $errorMessage = isset($_REQUEST['ErrorMessage']) ? $_REQUEST['ErrorMessage'] : '';
        
        $GLOBALS['log']->info("SMS Status Callback - SID: $messageSid, Status: $messageStatus");
        
        // Update the note with delivery status
        $this->updateSMSStatus($messageSid, $messageStatus, $errorCode, $errorMessage);
        
        // Return 200 OK
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'processed' => true]);
    }
    
    /**
     * Log inbound SMS as a Note in CRM
     */
    private function logInboundSMS($from, $to, $body, $messageSid, $numMedia, $leadInfo)
    {
        // Create Note record
        require_once('modules/Notes/Note.php');
        $note = BeanFactory::newBean('Notes');
        
        $callerName = $leadInfo ? $leadInfo['name'] : $from;
        
        $note->name = "📥 SMS from $callerName";
        
        $description = "Inbound SMS Message\n";
        $description .= "==================\n\n";
        $description .= "From: $from\n";
        $description .= "To: $to\n";
        $description .= "Time: " . date('Y-m-d H:i:s') . "\n";
        $description .= "Message SID: $messageSid\n\n";
        $description .= "Message:\n$body";
        
        if ($numMedia > 0) {
            $description .= "\n\n[Contains $numMedia media attachment(s)]";
            // Collect media URLs
            for ($i = 0; $i < $numMedia; $i++) {
                $mediaUrl = isset($_REQUEST["MediaUrl$i"]) ? $_REQUEST["MediaUrl$i"] : '';
                $mediaType = isset($_REQUEST["MediaContentType$i"]) ? $_REQUEST["MediaContentType$i"] : '';
                if (!empty($mediaUrl)) {
                    $description .= "\nMedia $i: $mediaUrl ($mediaType)";
                }
            }
        }
        
        $note->description = $description;
        
        if ($leadInfo) {
            $note->parent_type = $leadInfo['type'];
            $note->parent_id = $leadInfo['id'];
            $note->assigned_user_id = $leadInfo['assigned_user_id'];
        }
        
        $noteId = $note->save();
        
        // Create task for follow-up if no auto-reply was sent
        $this->createSMSFollowUpTask($from, $body, $leadInfo);
        
        // Log to audit
        $this->logAudit('inbound_sms', array(
            'note_id' => $noteId,
            'message_sid' => $messageSid,
            'from' => $from,
            'to' => $to,
            'body_length' => strlen($body),
            'lead_id' => $leadInfo ? $leadInfo['id'] : null,
            'lead_type' => $leadInfo ? $leadInfo['type'] : null
        ));

        // Log to LeadJourney for unified timeline
        if ($leadInfo) {
            $mediaUrls = [];
            for ($i = 0; $i < $numMedia; $i++) {
                $mediaUrl = isset($_REQUEST["MediaUrl$i"]) ? $_REQUEST["MediaUrl$i"] : '';
                if (!empty($mediaUrl)) {
                    $mediaUrls[] = $mediaUrl;
                }
            }

            LeadJourneyLogger::logSMS([
                'message_sid' => $messageSid,
                'from' => $from,
                'to' => $to,
                'body' => $body,
                'direction' => 'inbound',
                'media_urls' => $mediaUrls,
                'parent_type' => $leadInfo['type'],
                'parent_id' => $leadInfo['id'],
                'assigned_user_id' => $leadInfo['assigned_user_id']
            ]);
        }

        $GLOBALS['log']->info("Logged inbound SMS - Note ID: $noteId, SID: $messageSid");
        
        // If no lead found, optionally create a new lead
        if (!$leadInfo && !empty($GLOBALS['sugar_config']['twilio_auto_create_lead'])) {
            $this->createLeadFromSMS($from, $body);
        }
    }
    
    /**
     * Create follow-up task for SMS
     */
    private function createSMSFollowUpTask($from, $body, $leadInfo)
    {
        require_once('modules/Tasks/Task.php');
        $task = BeanFactory::newBean('Tasks');
        
        $callerName = $leadInfo ? $leadInfo['name'] : $from;
        
        $task->name = "Reply to SMS from $callerName";
        $task->status = 'Not Started';
        $task->priority = 'Medium';
        $task->date_due = gmdate('Y-m-d H:i:s', strtotime('+2 hours'));
        
        $taskDesc = "New SMS received from $from\n";
        $taskDesc .= "Time: " . date('Y-m-d H:i:s') . "\n\n";
        $taskDesc .= "Message:\n$body\n\n";
        $taskDesc .= "Please respond to this message.";
        
        $task->description = $taskDesc;
        
        if ($leadInfo) {
            $task->parent_type = $leadInfo['type'];
            $task->parent_id = $leadInfo['id'];
            $task->assigned_user_id = $leadInfo['assigned_user_id'];
        }
        
        $task->save();
        
        $GLOBALS['log']->info("Created SMS follow-up task for $from");
    }
    
    /**
     * Update SMS status in CRM
     */
    private function updateSMSStatus($messageSid, $status, $errorCode, $errorMessage)
    {
        $db = DBManagerFactory::getInstance();
        $messageSidSafe = $db->quote($messageSid);
        
        // Find the note with this message SID
        $sql = "SELECT id FROM notes WHERE description LIKE '%$messageSidSafe%' AND deleted = 0 ORDER BY date_entered DESC LIMIT 1";
        $result = $db->query($sql);
        
        if ($row = $db->fetchByAssoc($result)) {
            $note = BeanFactory::getBean('Notes', $row['id']);
            if ($note) {
                // Add status update to description
                $statusUpdate = "\n\n--- Delivery Status Update ---\n";
                $statusUpdate .= "Status: $status\n";
                $statusUpdate .= "Time: " . date('Y-m-d H:i:s') . "\n";
                
                if (!empty($errorCode)) {
                    $statusUpdate .= "Error Code: $errorCode\n";
                    $statusUpdate .= "Error Message: $errorMessage\n";
                }
                
                // Update note name with status indicator
                if ($status === 'delivered') {
                    $note->name = str_replace('📤', '✅', $note->name);
                } elseif ($status === 'failed' || $status === 'undelivered') {
                    $note->name = str_replace('📤', '❌', $note->name);
                }
                
                $note->description .= $statusUpdate;
                $note->save();
                
                // Log to audit
                $this->logAudit('sms_status_update', array(
                    'note_id' => $row['id'],
                    'message_sid' => $messageSid,
                    'status' => $status,
                    'error_code' => $errorCode
                ));
                
                $GLOBALS['log']->info("Updated SMS status - Note ID: {$row['id']}, Status: $status");
            }
        }
    }
    
    /**
     * Get auto-reply message based on keywords
     */
    private function getAutoReply($body, $leadInfo)
    {
        $bodyLower = strtolower(trim($body));
        
        // Check for STOP/unsubscribe
        if (in_array($bodyLower, ['stop', 'unsubscribe', 'cancel', 'end', 'quit'])) {
            // Mark lead as unsubscribed if found
            if ($leadInfo) {
                $this->markAsUnsubscribed($leadInfo);
            }
            return "You have been unsubscribed from our SMS messages. Reply START to re-subscribe.";
        }
        
        // Check for START/re-subscribe
        if (in_array($bodyLower, ['start', 'subscribe', 'yes'])) {
            if ($leadInfo) {
                $this->markAsSubscribed($leadInfo);
            }
            return "You have been re-subscribed to our SMS messages. Reply STOP to unsubscribe.";
        }
        
        // Check for HELP
        if (in_array($bodyLower, ['help', 'info'])) {
            return "Boomers Hub Senior Living Advisory. Call us at " . ($GLOBALS['sugar_config']['twilio_phone_number'] ?? 'our office') . " or visit boomershub.com. Reply STOP to unsubscribe.";
        }
        
        // No auto-reply for regular messages
        return '';
    }
    
    /**
     * Mark lead as unsubscribed from SMS
     */
    private function markAsUnsubscribed($leadInfo)
    {
        if ($leadInfo['type'] === 'Leads') {
            $bean = BeanFactory::getBean('Leads', $leadInfo['id']);
        } else {
            $bean = BeanFactory::getBean('Contacts', $leadInfo['id']);
        }
        
        if ($bean) {
            // Set do_not_call or similar field if available
            if (isset($bean->do_not_call)) {
                $bean->do_not_call = 1;
            }
            // Add to description
            $bean->description = ($bean->description ? $bean->description . "\n\n" : '') . 
                "[" . date('Y-m-d H:i:s') . "] Unsubscribed from SMS via STOP keyword";
            $bean->save();
            
            $GLOBALS['log']->info("Marked {$leadInfo['type']} {$leadInfo['id']} as unsubscribed from SMS");
        }
    }
    
    /**
     * Mark lead as subscribed to SMS
     */
    private function markAsSubscribed($leadInfo)
    {
        if ($leadInfo['type'] === 'Leads') {
            $bean = BeanFactory::getBean('Leads', $leadInfo['id']);
        } else {
            $bean = BeanFactory::getBean('Contacts', $leadInfo['id']);
        }
        
        if ($bean) {
            if (isset($bean->do_not_call)) {
                $bean->do_not_call = 0;
            }
            $bean->description = ($bean->description ? $bean->description . "\n\n" : '') . 
                "[" . date('Y-m-d H:i:s') . "] Re-subscribed to SMS via START keyword";
            $bean->save();
            
            $GLOBALS['log']->info("Marked {$leadInfo['type']} {$leadInfo['id']} as subscribed to SMS");
        }
    }
    
    /**
     * Create a new lead from incoming SMS
     */
    private function createLeadFromSMS($from, $body)
    {
        require_once('modules/Leads/Lead.php');
        $lead = BeanFactory::newBean('Leads');
        
        $lead->first_name = 'SMS';
        $lead->last_name = 'Lead ' . substr($from, -4);
        $lead->phone_mobile = $from;
        $lead->lead_source = 'SMS';
        $lead->status = 'New';
        $lead->description = "Lead auto-created from inbound SMS\n";
        $lead->description .= "Phone: $from\n";
        $lead->description .= "First Message: $body\n";
        $lead->description .= "Created: " . date('Y-m-d H:i:s');
        
        $leadId = $lead->save();
        
        $GLOBALS['log']->info("Auto-created lead $leadId from SMS from $from");
        
        return $leadId;
    }
    
    /**
     * Find lead or contact by phone
     */
    private function findLeadByPhone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) > 10) {
            $phone = substr($phone, -10);
        }
        
        $db = DBManagerFactory::getInstance();
        
        // Search Leads
        $sql = "SELECT id, first_name, last_name, assigned_user_id FROM leads 
                WHERE deleted = 0 
                AND (REPLACE(REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%')
                LIMIT 1";
        
        $result = $db->query($sql);
        if ($row = $db->fetchByAssoc($result)) {
            return array(
                'id' => $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'type' => 'Leads',
                'assigned_user_id' => $row['assigned_user_id']
            );
        }
        
        // Search Contacts
        $sql = "SELECT id, first_name, last_name, assigned_user_id FROM contacts 
                WHERE deleted = 0 
                AND (REPLACE(REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%')
                LIMIT 1";
        
        $result = $db->query($sql);
        if ($row = $db->fetchByAssoc($result)) {
            return array(
                'id' => $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'type' => 'Contacts',
                'assigned_user_id' => $row['assigned_user_id']
            );
        }
        
        return null;
    }
    
    /**
     * Log audit record
     */
    private function logAudit($action, $data)
    {
        $db = DBManagerFactory::getInstance();
        
        try {
            $id = create_guid();
            $actionSafe = $db->quote($action);
            $dataSafe = $db->quote(json_encode($data));
            $userId = isset($GLOBALS['current_user']) ? $GLOBALS['current_user']->id : '';
            $userIdSafe = $db->quote($userId);
            $dateCreated = gmdate('Y-m-d H:i:s');
            
            $sql = "INSERT INTO twilio_audit_log (id, action, data, user_id, date_created) 
                    VALUES ('$id', '$actionSafe', '$dataSafe', '$userIdSafe', '$dateCreated')";
            $db->query($sql, false);
        } catch (Exception $e) {
            $GLOBALS['log']->info("Twilio Audit [$action]: " . json_encode($data));
        }
    }
}
