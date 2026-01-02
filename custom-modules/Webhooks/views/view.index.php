<?php
/**
 * Webhooks Index View - API Keys Management
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class WebhooksViewIndex extends SugarView
{
    public function display()
    {
        global $db, $current_user, $mod_strings, $app_strings;

        // Check admin access
        if (!is_admin($current_user)) {
            sugar_die('Access Denied');
        }

        $ss = new Sugar_Smarty();

        // Get API keys
        $apiKeys = [];
        $query = "SELECT * FROM notification_api_keys ORDER BY created_at DESC";
        $result = $db->query($query);
        while ($row = $db->fetchByAssoc($result)) {
            $apiKeys[] = $row;
        }

        echo '<div class="moduleTitle"><h2>Webhook API Keys</h2></div>';
        echo '<div class="clear"></div>';

        echo '<p>Manage API keys for webhook notifications. External systems use these keys to send notifications to SuiteCRM.</p>';
        echo '<p>See <a href="index.php?module=Webhooks&action=docs">API Documentation</a> for integration details.</p>';

        echo '<h3>Active API Keys</h3>';
        echo '<table class="list view table-responsive" border="0" cellspacing="0" cellpadding="0">';
        echo '<thead><tr><th>Key Name</th><th>API Key (first 8 chars)</th><th>Permissions</th><th>Created</th><th>Last Used</th></tr></thead>';
        echo '<tbody>';

        if (empty($apiKeys)) {
            echo '<tr><td colspan="5">No API keys configured. Add keys via database.</td></tr>';
        } else {
            foreach ($apiKeys as $key) {
                $keyPreview = substr($key['api_key'], 0, 8) . '...';
                $permissions = $key['permissions'] ?? 'all';
                $created = $key['created_at'] ?? 'N/A';
                $lastUsed = $key['last_used_at'] ?? 'Never';
                echo "<tr>";
                echo "<td>" . htmlspecialchars($key['key_name'] ?? 'Unnamed') . "</td>";
                echo "<td><code>{$keyPreview}</code></td>";
                echo "<td>{$permissions}</td>";
                echo "<td>{$created}</td>";
                echo "<td>{$lastUsed}</td>";
                echo "</tr>";
            }
        }

        echo '</tbody></table>';

        echo '<br><h3>Rate Limiting</h3>';
        $rateLimitQuery = "SELECT COUNT(*) as count FROM notification_rate_limit WHERE window_start > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $rateLimitResult = $db->query($rateLimitQuery);
        $rateLimitRow = $db->fetchByAssoc($rateLimitResult);
        echo '<p>Active rate limit entries (last hour): ' . ($rateLimitRow['count'] ?? 0) . '</p>';
    }
}
