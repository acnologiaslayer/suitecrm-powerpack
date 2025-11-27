<?php
/**
 * Twilio TwiML Generator
 * Returns TwiML instructions for how to handle calls
 */
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class TwilioIntegrationViewTwiml extends SugarView {
    
    public function display() {
        // Disable view rendering
        $this->options['show_header'] = false;
        $this->options['show_footer'] = false;
        $this->options['show_title'] = false;
        $this->options['show_subpanels'] = false;
        
        $type = $_GET['type'] ?? 'dial';
        $to = $_GET['to'] ?? $_POST['To'] ?? '';
        
        header('Content-Type: text/xml');
        
        switch ($type) {
            case 'dial':
                // For outbound calls - connect to the destination
                echo $this->generateDialTwiML($to);
                break;
                
            case 'gather':
                // For IVR/menu systems
                echo $this->generateGatherTwiML();
                break;
                
            case 'voicemail':
                // For voicemail
                echo $this->generateVoicemailTwiML();
                break;
                
            default:
                // Default: just dial the number
                echo $this->generateDialTwiML($to);
        }
        
        exit;
    }
    
    /**
     * Generate TwiML for dialing a number
     */
    private function generateDialTwiML($to) {
        $config = TwilioIntegration::getConfig();
        $callerId = $config['phone_number'];
        
        $twiml = '<?xml version="1.0" encoding="UTF-8"?>';
        $twiml .= '<Response>';
        
        if ($to) {
            $twiml .= '<Dial callerId="' . htmlspecialchars($callerId) . '" record="record-from-answer-dual">';
            $twiml .= '<Number>' . htmlspecialchars($to) . '</Number>';
            $twiml .= '</Dial>';
        } else {
            $twiml .= '<Say>No destination number provided.</Say>';
        }
        
        $twiml .= '</Response>';
        
        return $twiml;
    }
    
    /**
     * Generate TwiML for IVR menu
     */
    private function generateGatherTwiML() {
        global $sugar_config;
        $siteUrl = $sugar_config['site_url'];
        
        $twiml = '<?xml version="1.0" encoding="UTF-8"?>';
        $twiml .= '<Response>';
        $twiml .= '<Gather numDigits="1" action="' . $siteUrl . '/legacy/index.php?module=TwilioIntegration&action=twiml&type=route">';
        $twiml .= '<Say>Thank you for calling. Press 1 for sales, press 2 for support.</Say>';
        $twiml .= '</Gather>';
        $twiml .= '<Say>We did not receive any input. Goodbye.</Say>';
        $twiml .= '</Response>';
        
        return $twiml;
    }
    
    /**
     * Generate TwiML for voicemail
     */
    private function generateVoicemailTwiML() {
        global $sugar_config;
        $siteUrl = $sugar_config['site_url'];
        
        $twiml = '<?xml version="1.0" encoding="UTF-8"?>';
        $twiml .= '<Response>';
        $twiml .= '<Say>Please leave a message after the beep.</Say>';
        $twiml .= '<Record maxLength="120" action="' . $siteUrl . '/legacy/index.php?module=TwilioIntegration&action=webhook" />';
        $twiml .= '<Say>I did not receive a recording. Goodbye.</Say>';
        $twiml .= '</Response>';
        
        return $twiml;
    }
}
