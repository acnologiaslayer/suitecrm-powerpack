<?php
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

class TwilioIntegration extends Basic {
    public $new_schema = true;
    public $module_dir = 'TwilioIntegration';
    public $object_name = 'TwilioIntegration';
    public $table_name = 'twilio_integration';
    public $importable = false;
    
    public $id;
    public $name;
    public $date_entered;
    public $date_modified;
    public $modified_user_id;
    public $modified_by_name;
    public $created_by;
    public $created_by_name;
    public $description;
    public $deleted;
    public $created_by_link;
    public $modified_user_link;
    public $assigned_user_id;
    public $assigned_user_name;
    public $assigned_user_link;
    
    // Twilio specific fields
    public $account_sid;
    public $auth_token;
    public $phone_number;
    public $enable_click_to_call;
    public $enable_auto_logging;
    public $enable_recordings;
    public $recording_storage_path;
    public $webhook_url;

    public function __construct() {
        parent::__construct();
    }

    public function bean_implements($interface){
        switch($interface){
            case 'ACL': return true;
        }
        return false;
    }
    
    /**
     * Make a call using Twilio
     */
    public function makeCall($to, $from, $recordingEnabled = true) {
        require_once('modules/TwilioIntegration/TwilioClient.php');
        
        $client = new TwilioClient();
        return $client->initiateCall($to, $from, $recordingEnabled);
    }
    
    /**
     * Log call to SuiteCRM
     */
    public function logCall($callSid, $from, $to, $duration, $status, $recordingUrl = null) {
        global $current_user;
        
        $call = BeanFactory::newBean('Calls');
        $call->name = "Call with " . $to;
        $call->status = 'Held';
        $call->direction = 'Outbound';
        $call->duration_hours = floor($duration / 3600);
        $call->duration_minutes = floor(($duration % 3600) / 60);
        $call->parent_type = 'Contacts';
        $call->assigned_user_id = $current_user->id;
        $call->description = "Twilio Call SID: " . $callSid;
        
        if ($recordingUrl) {
            $call->description .= "\nRecording URL: " . $recordingUrl;
        }
        
        $call->save();
        
        return $call->id;
    }
    
    /**
     * Send SMS using Twilio
     */
    public function sendSMS($to, $message, $from = null) {
        require_once('modules/TwilioIntegration/TwilioClient.php');
        
        $client = new TwilioClient();
        return $client->sendSMS($to, $message, $from);
    }
    
    /**
     * Log SMS to SuiteCRM
     */
    public function logSMS($messageSid, $from, $to, $message, $status, $direction = 'Outbound') {
        global $current_user;
        
        // Create a note to log the SMS
        $note = BeanFactory::newBean('Notes');
        $note->name = "SMS " . ($direction === 'Outbound' ? 'to' : 'from') . " " . $to;
        $note->description = $message;
        $note->assigned_user_id = $current_user->id;
        $note->parent_type = 'Contacts';
        
        // Add Twilio metadata
        $note->description .= "\n\n---\nTwilio Message SID: " . $messageSid;
        $note->description .= "\nStatus: " . $status;
        $note->description .= "\nDirection: " . $direction;
        $note->description .= "\nFrom: " . $from;
        $note->description .= "\nTo: " . $to;
        
        $note->save();
        
        return $note->id;
    }
    
    /**
     * Get configuration settings
     */
    public static function getConfig() {
        global $sugar_config;
        
        return array(
            'account_sid' => getenv('TWILIO_ACCOUNT_SID') ?: ($sugar_config['twilio_account_sid'] ?? ''),
            'auth_token' => getenv('TWILIO_AUTH_TOKEN') ?: ($sugar_config['twilio_auth_token'] ?? ''),
            'phone_number' => getenv('TWILIO_PHONE_NUMBER') ?: ($sugar_config['twilio_phone_number'] ?? ''),
            'enable_click_to_call' => $sugar_config['twilio_enable_click_to_call'] ?? true,
            'enable_auto_logging' => $sugar_config['twilio_enable_auto_logging'] ?? true,
            'enable_recordings' => $sugar_config['twilio_enable_recordings'] ?? true,
        );
    }
}
