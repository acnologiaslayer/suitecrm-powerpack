<?php
/**
 * NotificationHub Settings View
 *
 * Admin interface for managing API keys and testing notifications.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class NotificationHubViewSettings extends SugarView
{
    public function display()
    {
        global $current_user, $app_strings, $mod_strings;

        // Check admin access
        if (!$current_user->is_admin) {
            echo '<div class="alert alert-danger">Admin access required.</div>';
            return;
        }

        // Get existing API keys
        require_once('modules/NotificationHub/NotificationHub.php');
        $apiKeys = NotificationHub::getAllApiKeys();

        // Get webhook URL
        $webhookUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                    . '/legacy/notification_webhook.php';

        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Notification Hub - Settings</title>
            <style>
                .nh-container { padding: 20px; max-width: 1200px; margin: 0 auto; }
                .nh-section { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
                .nh-section h2 { margin-top: 0; color: #333; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
                .nh-section h3 { color: #555; margin-top: 20px; }
                .nh-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                .nh-table th, .nh-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
                .nh-table th { background: #f5f5f5; font-weight: 600; }
                .nh-table tr:hover { background: #f9f9f9; }
                .nh-btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; margin-right: 5px; }
                .nh-btn-primary { background: #3498db; color: white; }
                .nh-btn-success { background: #27ae60; color: white; }
                .nh-btn-danger { background: #e74c3c; color: white; }
                .nh-btn-warning { background: #f39c12; color: white; }
                .nh-btn:hover { opacity: 0.9; }
                .nh-input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; width: 100%; max-width: 400px; }
                .nh-form-group { margin-bottom: 15px; }
                .nh-form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #555; }
                .nh-code { background: #f5f5f5; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 13px; overflow-x: auto; }
                .nh-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
                .nh-badge-success { background: #d4edda; color: #155724; }
                .nh-badge-danger { background: #f8d7da; color: #721c24; }
                .nh-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
                .nh-modal-content { background: white; max-width: 500px; margin: 100px auto; padding: 20px; border-radius: 8px; }
                .nh-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
                .nh-modal-close { font-size: 24px; cursor: pointer; }
                .nh-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
                .nh-tab { padding: 10px 20px; cursor: pointer; border-radius: 4px 4px 0 0; }
                .nh-tab.active { background: #3498db; color: white; }
                .nh-tab-content { display: none; }
                .nh-tab-content.active { display: block; }
                .nh-alert { padding: 15px; border-radius: 4px; margin-bottom: 15px; }
                .nh-alert-info { background: #d1ecf1; color: #0c5460; }
                .nh-alert-success { background: #d4edda; color: #155724; }
            </style>
        </head>
        <body>
        <div class="nh-container">
            <h1>Notification Hub</h1>

            <div class="nh-tabs">
                <div class="nh-tab active" onclick="showTab('api-keys')">API Keys</div>
                <div class="nh-tab" onclick="showTab('test')">Test Notifications</div>
                <div class="nh-tab" onclick="showTab('docs')">Documentation</div>
            </div>

            <!-- API Keys Tab -->
            <div id="tab-api-keys" class="nh-tab-content active">
                <div class="nh-section">
                    <h2>API Keys</h2>
                    <p>API keys are used to authenticate external systems when pushing notifications to SuiteCRM.</p>

                    <button class="nh-btn nh-btn-primary" onclick="showCreateKeyModal()">
                        + Create New API Key
                    </button>

                    <table class="nh-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Created</th>
                                <th>Last Used</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="api-keys-table">
                            <?php foreach ($apiKeys as $key): ?>
                            <tr id="key-row-<?php echo htmlspecialchars($key['id']); ?>">
                                <td><strong><?php echo htmlspecialchars($key['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($key['description'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($key['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($key['last_used_at'] ?? 'Never'); ?></td>
                                <td>
                                    <?php if ($key['is_active']): ?>
                                        <span class="nh-badge nh-badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="nh-badge nh-badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="nh-btn nh-btn-primary" onclick="viewKey('<?php echo $key['id']; ?>')">View</button>
                                    <button class="nh-btn nh-btn-warning" onclick="toggleKey('<?php echo $key['id']; ?>', <?php echo $key['is_active'] ? 'false' : 'true'; ?>)">
                                        <?php echo $key['is_active'] ? 'Disable' : 'Enable'; ?>
                                    </button>
                                    <button class="nh-btn nh-btn-danger" onclick="deleteKey('<?php echo $key['id']; ?>')">Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($apiKeys)): ?>
                            <tr><td colspan="6" style="text-align: center; color: #999;">No API keys created yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Test Notifications Tab -->
            <div id="tab-test" class="nh-tab-content">
                <div class="nh-section">
                    <h2>Test Notifications</h2>
                    <p>Send a test notification to verify the system is working correctly.</p>

                    <div class="nh-form-group">
                        <label>Title</label>
                        <input type="text" id="test-title" class="nh-input" value="Test Notification" />
                    </div>

                    <div class="nh-form-group">
                        <label>Message</label>
                        <input type="text" id="test-message" class="nh-input" value="This is a test notification from SuiteCRM PowerPack." />
                    </div>

                    <div class="nh-form-group">
                        <label>Type</label>
                        <select id="test-type" class="nh-input">
                            <option value="info">Info</option>
                            <option value="success">Success</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                        </select>
                    </div>

                    <button class="nh-btn nh-btn-success" onclick="sendTestNotification()">
                        Send Test Notification
                    </button>

                    <div id="test-result" style="margin-top: 20px;"></div>
                </div>
            </div>

            <!-- Documentation Tab -->
            <div id="tab-docs" class="nh-tab-content">
                <div class="nh-section">
                    <h2>Webhook Endpoint</h2>
                    <div class="nh-code"><?php echo htmlspecialchars($webhookUrl); ?></div>

                    <h3>Authentication</h3>
                    <p>Include your API key in the <code>X-API-Key</code> header:</p>
                    <div class="nh-code">X-API-Key: your-api-key-here</div>

                    <h3>Create Notification</h3>
                    <p><strong>POST</strong> to the webhook URL with JSON body:</p>
                    <div class="nh-code"><pre>{
  "title": "New Lead Assigned",
  "message": "Lead John Doe has been assigned to you",
  "type": "info",           // info, success, warning, error
  "priority": "normal",     // low, normal, high, urgent
  "target_users": ["user-uuid-1", "user-uuid-2"],
  "target_roles": ["Sales Manager"],  // Optional: notify by role
  "target_module": "Leads",           // Optional: for click navigation
  "target_record": "lead-uuid"        // Optional: for click navigation
}</pre></div>

                    <h3>Example cURL</h3>
                    <div class="nh-code"><pre>curl -X POST <?php echo htmlspecialchars($webhookUrl); ?> \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title": "Test", "message": "Hello!", "target_users": ["<?php echo $current_user->id; ?>"]}'</pre></div>

                    <h3>Response</h3>
                    <div class="nh-code"><pre>{
  "success": true,
  "data": {
    "alert_ids": ["uuid-1"],
    "queue_ids": ["uuid-2"],
    "user_count": 1
  }
}</pre></div>
                </div>
            </div>
        </div>

        <!-- Create Key Modal -->
        <div id="create-key-modal" class="nh-modal">
            <div class="nh-modal-content">
                <div class="nh-modal-header">
                    <h3>Create New API Key</h3>
                    <span class="nh-modal-close" onclick="closeModal()">&times;</span>
                </div>
                <div class="nh-form-group">
                    <label>Name *</label>
                    <input type="text" id="new-key-name" class="nh-input" placeholder="e.g., Zapier Integration" />
                </div>
                <div class="nh-form-group">
                    <label>Description</label>
                    <input type="text" id="new-key-description" class="nh-input" placeholder="Optional description" />
                </div>
                <button class="nh-btn nh-btn-success" onclick="createKey()">Create Key</button>
            </div>
        </div>

        <!-- View Key Modal -->
        <div id="view-key-modal" class="nh-modal">
            <div class="nh-modal-content">
                <div class="nh-modal-header">
                    <h3>API Key Details</h3>
                    <span class="nh-modal-close" onclick="closeViewModal()">&times;</span>
                </div>
                <div class="nh-alert nh-alert-info">
                    <strong>Important:</strong> This is the only time you can see the full API key.
                    Store it securely!
                </div>
                <div class="nh-form-group">
                    <label>Name</label>
                    <div id="view-key-name"></div>
                </div>
                <div class="nh-form-group">
                    <label>API Key</label>
                    <div class="nh-code" id="view-key-value" style="word-break: break-all;"></div>
                </div>
                <button class="nh-btn nh-btn-primary" onclick="copyKey()">Copy to Clipboard</button>
            </div>
        </div>

        <script>
        function showTab(tab) {
            document.querySelectorAll('.nh-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.nh-tab-content').forEach(c => c.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById('tab-' + tab).classList.add('active');
        }

        function showCreateKeyModal() {
            document.getElementById('create-key-modal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('create-key-modal').style.display = 'none';
        }

        function closeViewModal() {
            document.getElementById('view-key-modal').style.display = 'none';
        }

        async function createKey() {
            const name = document.getElementById('new-key-name').value.trim();
            const description = document.getElementById('new-key-description').value.trim();

            if (!name) {
                alert('Name is required');
                return;
            }

            const formData = new FormData();
            formData.append('name', name);
            formData.append('description', description);

            const response = await fetch('index.php?module=NotificationHub&action=createKey', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                closeModal();
                document.getElementById('view-key-name').textContent = result.data.name;
                document.getElementById('view-key-value').textContent = result.data.api_key;
                document.getElementById('view-key-modal').style.display = 'block';
                location.reload();
            } else {
                alert('Error: ' + result.error);
            }
        }

        async function viewKey(id) {
            const response = await fetch('index.php?module=NotificationHub&action=getKey&id=' + id);
            const result = await response.json();

            if (result.success) {
                document.getElementById('view-key-name').textContent = result.data.name;
                document.getElementById('view-key-value').textContent = result.data.api_key;
                document.getElementById('view-key-modal').style.display = 'block';
            } else {
                alert('Error: ' + result.error);
            }
        }

        async function toggleKey(id, active) {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('active', active);

            const response = await fetch('index.php?module=NotificationHub&action=toggleKey', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.success) {
                location.reload();
            } else {
                alert('Error toggling key');
            }
        }

        async function deleteKey(id) {
            if (!confirm('Are you sure you want to delete this API key? This action cannot be undone.')) {
                return;
            }

            const formData = new FormData();
            formData.append('id', id);

            const response = await fetch('index.php?module=NotificationHub&action=deleteKey', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.success) {
                location.reload();
            } else {
                alert('Error deleting key');
            }
        }

        function copyKey() {
            const key = document.getElementById('view-key-value').textContent;
            navigator.clipboard.writeText(key).then(() => {
                alert('API key copied to clipboard!');
            });
        }

        async function sendTestNotification() {
            const title = document.getElementById('test-title').value;
            const message = document.getElementById('test-message').value;
            const type = document.getElementById('test-type').value;

            const formData = new FormData();
            formData.append('title', title);
            formData.append('message', message);
            formData.append('type', type);

            const response = await fetch('index.php?module=NotificationHub&action=sendTest', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            const resultDiv = document.getElementById('test-result');

            if (result.success) {
                resultDiv.innerHTML = '<div class="nh-alert nh-alert-success">Notification sent successfully! Check your notifications.</div>';
            } else {
                resultDiv.innerHTML = '<div class="nh-alert" style="background: #f8d7da; color: #721c24;">Error: ' + (result.error || 'Unknown error') + '</div>';
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('nh-modal')) {
                event.target.style.display = 'none';
            }
        }
        </script>
        </body>
        </html>
        <?php
    }
}
