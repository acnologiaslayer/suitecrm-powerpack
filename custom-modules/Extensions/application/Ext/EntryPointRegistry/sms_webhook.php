<?php
/**
 * SMS Webhook Entry Point Registration
 * Allows Twilio to POST to: index.php?entryPoint=sms_webhook
 *
 * This file is copied to:
 * /bitnami/suitecrm/public/legacy/custom/Extension/application/Ext/EntryPointRegistry/sms_webhook.php
 * and compiled to:
 * /bitnami/suitecrm/public/legacy/custom/application/Ext/EntryPointRegistry/entry_point_registry.ext.php
 */

// Note: Do NOT include sugarEntry check here - this file is included by SuiteCRM's extension loader
// The check is in the entry point file itself

$entry_point_registry['sms_webhook'] = array(
    'file' => 'modules/TwilioIntegration/sms_entry_point.php',
    'auth' => false  // No authentication required for Twilio webhooks
);
