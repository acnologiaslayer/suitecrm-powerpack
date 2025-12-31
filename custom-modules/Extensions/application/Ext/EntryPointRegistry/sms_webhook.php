<?php
/**
 * SMS Webhook Entry Point Registration
 * Allows Twilio to POST to: index.php?entryPoint=sms_webhook
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

$entry_point_registry['sms_webhook'] = array(
    'file' => 'modules/TwilioIntegration/sms_entry_point.php',
    'auth' => false  // No authentication required for webhooks
);
