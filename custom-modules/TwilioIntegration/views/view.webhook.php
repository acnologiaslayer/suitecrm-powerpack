<?php
/**
 * Twilio Voice Webhook Handler
 * Handles inbound calls and call status updates
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('modules/TwilioIntegration/TwilioIntegration.php');
require_once('modules/TwilioIntegration/TwilioSecurity.php');
require_once('modules/LeadJourney/LeadJourneyLogger.php');

class TwilioIntegrationViewWebhook extends SugarView
{
    public function display()
    {
        // Validate webhook signature for security
        TwilioSecurity::validateOrDie('voice_webhook');

        // Determine the type of webhook
        $action = isset($_REQUEST['webhook_action']) ? $_REQUEST['webhook_action'] : 'voice';
        
        switch ($action) {
            case 'voice':
                $this->handleVoiceWebhook();
                break;
            case 'status':
                $this->handleStatusWebhook();
                break;
            case 'fallback':
                $this->handleFallbackWebhook();
                break;
            default:
                $this->handleVoiceWebhook();
        }
        
        die();
    }
    
    /**
     * Handle incoming voice call webhook
     * This is the primary webhook URL configured in Twilio console
     */
    private function handleVoiceWebhook()
    {
        $from = isset($_REQUEST['From']) ? $_REQUEST['From'] : '';
        $to = isset($_REQUEST['To']) ? $_REQUEST['To'] : '';
        $callSid = isset($_REQUEST['CallSid']) ? $_REQUEST['CallSid'] : '';
        $callStatus = isset($_REQUEST['CallStatus']) ? $_REQUEST['CallStatus'] : '';
        
        $GLOBALS['log']->info("Twilio Voice Webhook - From: $from, To: $to, SID: $callSid, Status: $callStatus");
        
        // Log the inbound call
        $this->logInboundCall($from, $to, $callSid, $callStatus);
        
        // Return TwiML to handle the call
        header('Content-Type: application/xml');
        
        $config = TwilioIntegration::getConfig();
        $siteUrl = $GLOBALS['sugar_config']['site_url'] ?? '';
        
        // Find lead/contact info for personalization
        $leadInfo = $this->findLeadByPhone($from);
        
        // Get BDM phone
        $bdmPhone = $this->getBDMPhone($leadInfo);
        
        $twiml = '<?xml version="1.0" encoding="UTF-8"?>';
        $twiml .= '<Response>';
        
        // Greeting
        if ($leadInfo && !empty($leadInfo['name'])) {
            $firstName = explode(' ', $leadInfo['name'])[0];
            $twiml .= '<Say voice="Polly.Joanna">Hello ' . htmlspecialchars($firstName) . ', thank you for calling Boomers Hub.</Say>';
        } else {
            $twiml .= '<Say voice="Polly.Joanna">Thank you for calling Boomers Hub.</Say>';
        }
        
        if (!empty($bdmPhone)) {
            // Route to BDM with voicemail fallback
            $voicemailAction = $siteUrl . '/legacy/twilio_webhook.php?action=twiml&dial_action=voicemail&from=' . urlencode($from);
            
            $twiml .= '<Say voice="Polly.Joanna">Please hold while I connect you to your representative.</Say>';
            $twiml .= '<Dial timeout="25" action="' . htmlspecialchars($voicemailAction) . '" method="POST">';
            $twiml .= '<Number>' . htmlspecialchars($bdmPhone) . '</Number>';
            $twiml .= '</Dial>';
        } else {
            // No BDM - go to general voicemail
            $recordingAction = $siteUrl . '/legacy/twilio_webhook.php?action=twiml&dial_action=recording&from=' . urlencode($from);
            
            $twiml .= '<Say voice="Polly.Joanna">We are currently unavailable. Please leave a message after the tone.</Say>';
            $twiml .= '<Record maxLength="120" playBeep="true" action="' . htmlspecialchars($recordingAction) . '" transcribe="true" />';
            $twiml .= '<Say voice="Polly.Joanna">I did not receive a recording. Goodbye.</Say>';
            $twiml .= '<Hangup/>';
        }
        
        $twiml .= '</Response>';
        
        echo $twiml;
    }
    
    /**
     * Handle call status webhook (status callback URL)
     */
    private function handleStatusWebhook()
    {
        $callSid = isset($_REQUEST['CallSid']) ? $_REQUEST['CallSid'] : '';
        $callStatus = isset($_REQUEST['CallStatus']) ? $_REQUEST['CallStatus'] : '';
        $callDuration = isset($_REQUEST['CallDuration']) ? $_REQUEST['CallDuration'] : '0';
        $from = isset($_REQUEST['From']) ? $_REQUEST['From'] : '';
        $to = isset($_REQUEST['To']) ? $_REQUEST['To'] : '';
        $direction = isset($_REQUEST['Direction']) ? $_REQUEST['Direction'] : '';
        
        $GLOBALS['log']->info("Twilio Status Webhook - SID: $callSid, Status: $callStatus, Duration: $callDuration");
        
        // Update call record in CRM
        $this->updateCallStatus($callSid, $callStatus, $callDuration, $from, $to, $direction);
        
        // Return 200 OK
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'processed' => true]);
    }
    
    /**
     * Handle fallback webhook (when primary fails)
     */
    private function handleFallbackWebhook()
    {
        $errorCode = isset($_REQUEST['ErrorCode']) ? $_REQUEST['ErrorCode'] : '';
        $errorMessage = isset($_REQUEST['ErrorMessage']) ? $_REQUEST['ErrorMessage'] : '';
        
        $GLOBALS['log']->error("Twilio Fallback Triggered - Code: $errorCode, Message: $errorMessage");
        
        // Return simple TwiML
        header('Content-Type: application/xml');
        
        $twiml = '<?xml version="1.0" encoding="UTF-8"?>';
        $twiml .= '<Response>';
        $twiml .= '<Say voice="Polly.Joanna">We are experiencing technical difficulties. Please try again later or leave a message after the tone.</Say>';
        $twiml .= '<Record maxLength="60" playBeep="true" />';
        $twiml .= '<Say voice="Polly.Joanna">Goodbye.</Say>';
        $twiml .= '<Hangup/>';
        $twiml .= '</Response>';
        
        echo $twiml;
    }
    
    /**
     * Log inbound call in CRM
     */
    private function logInboundCall($from, $to, $callSid, $status)
    {
        $allLeads = $this->findAllLeadsByPhone($from);
        $leadInfo = !empty($allLeads) ? $allLeads[0] : null;

        // Create Call record (link to first match for legacy Call module)
        require_once('modules/Calls/Call.php');
        $call = BeanFactory::newBean('Calls');

        $call->name = 'Inbound Call from ' . $from;
        $call->status = 'Planned'; // Will be updated by status webhook
        $call->direction = 'Inbound';
        $call->date_start = gmdate('Y-m-d H:i:s');
        $call->duration_hours = 0;
        $call->duration_minutes = 0;
        $call->description = "Inbound call from $from to $to\nCall SID: $callSid\nInitial Status: $status";

        if ($leadInfo) {
            $call->parent_type = $leadInfo['type'];
            $call->parent_id = $leadInfo['id'];
            $call->assigned_user_id = $leadInfo['assigned_user_id'];
            $call->name = 'Inbound Call from ' . $leadInfo['name'];
        }

        $callId = $call->save();

        // Log to audit
        $this->logAudit('inbound_call', array(
            'call_id' => $callId,
            'call_sid' => $callSid,
            'from' => $from,
            'to' => $to,
            'lead_id' => $leadInfo ? $leadInfo['id'] : null,
            'lead_type' => $leadInfo ? $leadInfo['type'] : null,
            'total_matches' => count($allLeads)
        ));

        // Log to LeadJourney for ALL matching leads/contacts
        foreach ($allLeads as $match) {
            LeadJourneyLogger::logCall([
                'call_sid' => $callSid,
                'from' => $from,
                'to' => $to,
                'caller_name' => $match['name'],  // Inbound: lead is calling us
                'recipient_name' => '',
                'direction' => 'inbound',
                'status' => $status,
                'duration' => 0, // Will be updated by status webhook
                'parent_type' => $match['type'],
                'parent_id' => $match['id'],
                'assigned_user_id' => $match['assigned_user_id']
            ]);
        }

        $GLOBALS['log']->info("Logged inbound call - CRM ID: $callId, SID: $callSid, matched " . count($allLeads) . " records");
    }
    
    /**
     * Update call status in CRM
     */
    private function updateCallStatus($callSid, $status, $duration, $from, $to, $direction)
    {
        $db = DBManagerFactory::getInstance();
        $callSidSafe = $db->quote($callSid);
        
        // Find the call record
        $sql = "SELECT id FROM calls WHERE description LIKE '%$callSidSafe%' AND deleted = 0 ORDER BY date_entered DESC LIMIT 1";
        $result = $db->query($sql);
        
        if ($row = $db->fetchByAssoc($result)) {
            $call = BeanFactory::getBean('Calls', $row['id']);
            if ($call) {
                // Map Twilio status to SuiteCRM status
                switch ($status) {
                    case 'completed':
                        $call->status = 'Held';
                        break;
                    case 'busy':
                    case 'no-answer':
                    case 'failed':
                    case 'canceled':
                        $call->status = 'Not Held';
                        // Create follow-up task for missed calls
                        if ($direction === 'inbound') {
                            $this->createFollowUpTask($from, $status, $call->parent_type, $call->parent_id, $call->assigned_user_id);
                        }
                        break;
                    default:
                        $call->status = 'Planned';
                }
                
                // Update duration
                $durationInt = intval($duration);
                $call->duration_hours = floor($durationInt / 3600);
                $call->duration_minutes = floor(($durationInt % 3600) / 60);
                
                $call->description .= "\n\nFinal Status: $status\nDuration: " . gmdate('H:i:s', $durationInt);
                $call->save();
                
                // Log to audit
                $this->logAudit('call_status_update', array(
                    'call_id' => $row['id'],
                    'call_sid' => $callSid,
                    'status' => $status,
                    'duration' => $duration
                ));

                // Update or create LeadJourney entries for ALL matching leads/contacts
                $externalNumber = ($direction === 'inbound') ? $from : $to;
                $allMatches = $this->findAllLeadsByPhone($externalNumber);

                foreach ($allMatches as $match) {
                    // Check if entry already exists for this specific lead
                    $existingId = LeadJourneyLogger::findByCallSidAndParent($callSid, $match['type'], $match['id']);
                    if (!$existingId) {
                        // Create journey entry for this lead
                        LeadJourneyLogger::logCall([
                            'call_sid' => $callSid,
                            'from' => $from,
                            'to' => $to,
                            'caller_name' => ($direction === 'inbound') ? $match['name'] : '',
                            'recipient_name' => ($direction !== 'inbound') ? $match['name'] : '',
                            'direction' => ($direction === 'inbound') ? 'inbound' : 'outbound',
                            'status' => $status,
                            'duration' => intval($duration),
                            'parent_type' => $match['type'],
                            'parent_id' => $match['id'],
                            'assigned_user_id' => $match['assigned_user_id']
                        ]);
                    }
                }

                $GLOBALS['log']->info("Updated call status - ID: {$row['id']}, Status: $status, Duration: $duration, matched " . count($allMatches) . " records");
            }
        } else {
            // Call record not found - might be outbound initiated differently
            $GLOBALS['log']->warn("Call record not found for SID: $callSid");
        }
    }
    
    /**
     * Create follow-up task for missed calls
     */
    private function createFollowUpTask($from, $status, $parentType, $parentId, $assignedUserId)
    {
        require_once('modules/Tasks/Task.php');
        $task = BeanFactory::newBean('Tasks');
        
        $leadInfo = $this->findLeadByPhone($from);
        $callerName = $leadInfo ? $leadInfo['name'] : $from;
        
        $task->name = "Return missed call - $callerName";
        $task->status = 'Not Started';
        $task->priority = 'High';
        $task->date_due = gmdate('Y-m-d H:i:s', strtotime('+4 hours'));
        $task->description = "Missed inbound call from $from\nStatus: $status\nTime: " . date('Y-m-d H:i:s') . "\n\nPlease call back as soon as possible.";
        
        if (!empty($parentType) && !empty($parentId)) {
            $task->parent_type = $parentType;
            $task->parent_id = $parentId;
        }
        
        if (!empty($assignedUserId)) {
            $task->assigned_user_id = $assignedUserId;
        }
        
        $task->save();
        
        $GLOBALS['log']->info("Created follow-up task for missed call from $from");
    }
    
    /**
     * Find lead or contact by phone (returns first match for routing)
     */
    private function findLeadByPhone($phone)
    {
        $matches = $this->findAllLeadsByPhone($phone);
        return !empty($matches) ? $matches[0] : null;
    }

    /**
     * Find ALL leads and contacts by phone (for logging to all matching records)
     */
    private function findAllLeadsByPhone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) > 10) {
            $phone = substr($phone, -10);
        }

        $db = DBManagerFactory::getInstance();
        $matches = [];

        // Search Leads (no LIMIT - get all matches)
        $sql = "SELECT id, first_name, last_name, assigned_user_id FROM leads
                WHERE deleted = 0
                AND (REPLACE(REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%')";

        $result = $db->query($sql);
        while ($row = $db->fetchByAssoc($result)) {
            $matches[] = array(
                'id' => $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'type' => 'Leads',
                'assigned_user_id' => $row['assigned_user_id']
            );
        }

        // Search Contacts (no LIMIT - get all matches)
        $sql = "SELECT id, first_name, last_name, assigned_user_id FROM contacts
                WHERE deleted = 0
                AND (REPLACE(REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%'
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '(', ''), ')', '') LIKE '%$phone%')";

        $result = $db->query($sql);
        while ($row = $db->fetchByAssoc($result)) {
            $matches[] = array(
                'id' => $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'type' => 'Contacts',
                'assigned_user_id' => $row['assigned_user_id']
            );
        }

        return $matches;
    }
    
    /**
     * Get BDM phone number
     */
    private function getBDMPhone($leadInfo)
    {
        if (!$leadInfo || empty($leadInfo['assigned_user_id'])) {
            return $GLOBALS['sugar_config']['twilio_fallback_phone'] ?? '';
        }
        
        $db = DBManagerFactory::getInstance();
        $userId = $db->quote($leadInfo['assigned_user_id']);
        
        $sql = "SELECT phone_mobile, phone_work FROM users WHERE id = '$userId' AND deleted = 0";
        $result = $db->query($sql);
        
        if ($row = $db->fetchByAssoc($result)) {
            $phone = !empty($row['phone_mobile']) ? $row['phone_mobile'] : $row['phone_work'];
            if (!empty($phone)) {
                $digits = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($digits) === 10) {
                    $digits = '1' . $digits;
                }
                return '+' . $digits;
            }
        }
        
        return '';
    }
    
    /**
     * Log audit record
     */
    private function logAudit($action, $data)
    {
        $db = DBManagerFactory::getInstance();
        
        // Check if audit table exists
        $tableName = 'twilio_audit_log';
        
        $id = create_guid();
        $actionSafe = $db->quote($action);
        $dataSafe = $db->quote(json_encode($data));
        $userId = isset($GLOBALS['current_user']) ? $GLOBALS['current_user']->id : '';
        $userIdSafe = $db->quote($userId);
        $dateCreated = gmdate('Y-m-d H:i:s');
        
        // Try to insert, fail silently if table doesn't exist
        try {
            $sql = "INSERT INTO $tableName (id, action, data, user_id, date_created) 
                    VALUES ('$id', '$actionSafe', '$dataSafe', '$userIdSafe', '$dateCreated')";
            $db->query($sql, false); // false = don't die on error
        } catch (Exception $e) {
            // Table might not exist yet - log to file instead
            $GLOBALS['log']->info("Twilio Audit [$action]: " . json_encode($data));
        }
    }
}
