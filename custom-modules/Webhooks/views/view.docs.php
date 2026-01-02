<?php
/**
 * Webhooks API Documentation View
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class WebhooksViewDocs extends SugarView
{
    public function display()
    {
        global $current_user;

        // Check admin access
        if (!is_admin($current_user)) {
            sugar_die('Access Denied');
        }

        echo '<div class="moduleTitle"><h2>Webhook API Documentation</h2></div>';
        echo '<div class="clear"></div>';

        echo '<h3>Endpoint</h3>';
        echo '<pre>POST /legacy/modules/Webhooks/notification_webhook.php</pre>';

        echo '<h3>Authentication</h3>';
        echo '<p>Include your API key in the request header:</p>';
        echo '<pre>X-API-Key: your-api-key-here</pre>';

        echo '<h3>Request Body</h3>';
        echo '<pre>{
    "type": "call_completed",
    "priority": "normal",
    "title": "Notification Title",
    "message": "Notification message content",
    "data": {
        "lead_id": "uuid-here",
        "call_duration": 120
    },
    "target_users": ["user_id_1", "user_id_2"]
}</pre>';

        echo '<h3>Notification Types</h3>';
        echo '<ul>';
        echo '<li><code>call_completed</code> - Call finished</li>';
        echo '<li><code>call_missed</code> - Missed call</li>';
        echo '<li><code>sms_received</code> - Incoming SMS</li>';
        echo '<li><code>lead_updated</code> - Lead record changed</li>';
        echo '<li><code>task_reminder</code> - Task due reminder</li>';
        echo '<li><code>system</code> - System notification</li>';
        echo '</ul>';

        echo '<h3>Priority Levels</h3>';
        echo '<ul>';
        echo '<li><code>low</code> - Informational</li>';
        echo '<li><code>normal</code> - Standard (default)</li>';
        echo '<li><code>high</code> - Important</li>';
        echo '<li><code>urgent</code> - Critical, immediate attention</li>';
        echo '</ul>';

        echo '<h3>Response</h3>';
        echo '<pre>{
    "success": true,
    "notification_id": "uuid-here",
    "delivered_to": 3
}</pre>';

        echo '<h3>Rate Limits</h3>';
        echo '<p>Default: 100 requests per minute per API key.</p>';

        echo '<p><a href="index.php?module=Webhooks&action=index">Back to API Keys</a></p>';
    }
}
