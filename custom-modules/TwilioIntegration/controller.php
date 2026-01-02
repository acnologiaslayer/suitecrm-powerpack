<?php
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/Controller/SugarController.php');

class TwilioIntegrationController extends SugarController {
    
    public function __construct() {
        parent::__construct();
        // Register custom actions
        $this->action_remap['makecall'] = 'makecall';
        $this->action_remap['sendsms'] = 'sendsms';
        $this->action_remap['webhook'] = 'webhook';
        $this->action_remap['sms_webhook'] = 'sms_webhook';
        $this->action_remap['twiml'] = 'twiml';
        $this->action_remap['config'] = 'config';
        $this->action_remap['metrics'] = 'metrics';
        $this->action_remap['recording_webhook'] = 'recording_webhook';
        $this->action_remap['dashboard'] = 'dashboard';
        $this->action_remap['bulksms'] = 'bulksms';
        $this->action_remap['recording'] = 'recording';
    }

    /**
     * Serve recording audio file or proxy from Twilio
     */
    public function action_recording() {
        global $sugar_config;

        // Get recording identifier
        $file = isset($_REQUEST['file']) ? $_REQUEST['file'] : '';
        $sid = isset($_REQUEST['sid']) ? $_REQUEST['sid'] : '';

        if (!empty($file)) {
            // Serve local file
            $filepath = 'upload/twilio_recordings/' . basename($file);
            if (file_exists($filepath)) {
                header('Content-Type: audio/mpeg');
                header('Content-Length: ' . filesize($filepath));
                header('Content-Disposition: inline; filename="' . basename($file) . '"');
                readfile($filepath);
                exit;
            }
        }

        if (!empty($sid)) {
            // Proxy from Twilio API
            $accountSid = $sugar_config['twilio_account_sid'] ?? '';
            $authToken = $sugar_config['twilio_auth_token'] ?? '';

            if (!empty($accountSid) && !empty($authToken)) {
                $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Recordings/{$sid}.mp3";

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, "{$accountSid}:{$authToken}");
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                $audio = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                curl_close($ch);

                if ($httpCode === 200) {
                    header('Content-Type: ' . $contentType);
                    header('Content-Length: ' . strlen($audio));
                    echo $audio;
                    exit;
                }
            }
        }

        // Not found
        header('HTTP/1.0 404 Not Found');
        echo 'Recording not found';
        exit;
    }

    public function action_makecall() {
        $this->view = 'makecall';
    }
    
    public function action_sendsms() {
        $this->view = 'sendsms';
    }
    
    public function action_webhook() {
        $this->view = 'webhook';
    }
    
    public function action_sms_webhook() {
        $this->view = 'sms_webhook';
    }
    
    public function action_twiml() {
        $this->view = 'twiml';
    }
    
    public function action_config() {
        $this->view = 'config';
    }
    
    public function action_metrics() {
        $this->view = 'metrics';
    }

    public function action_recording_webhook() {
        $this->view = 'recording_webhook';
    }

    public function action_dashboard() {
        $this->view = 'dashboard';
    }

    public function action_bulksms() {
        $this->view = 'bulksms';
    }
}
