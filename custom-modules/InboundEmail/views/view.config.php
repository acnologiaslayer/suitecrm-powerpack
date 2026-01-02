<?php
/**
 * InboundEmail Configuration View with OAuth Support
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class InboundEmailViewConfig extends SugarView
{
    public function display()
    {
        global $current_user, $mod_strings;

        if (!$current_user->isAdmin()) {
            echo '<div class="alert alert-danger">Access denied. Administrator privileges required.</div>';
            return;
        }

        $imapAvailable = function_exists('imap_open');
        $message = '';
        $messageType = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = $this->handleFormSubmission();
            $message = $result['message'];
            $messageType = $result['type'];
        }

        $configs = $this->getConfigurations();
        $oauthConnections = $this->getOAuthConnections();

        $editId = $_GET['edit_id'] ?? '';
        $editRecord = null;
        if ($editId) {
            $editRecord = BeanFactory::getBean('InboundEmail', $editId);
        }

        echo $this->renderConfigPage($configs, $editRecord, $imapAvailable, $message, $messageType, $oauthConnections);
    }

    private function handleFormSubmission()
    {
        $action = $_POST['form_action'] ?? '';

        switch ($action) {
            case 'save':
                return $this->saveConfiguration();
            case 'delete':
                return $this->deleteConfiguration();
            case 'test':
                return $this->testConfiguration();
            default:
                return ['message' => '', 'type' => ''];
        }
    }

    private function saveConfiguration()
    {
        $id = $_POST['record_id'] ?? '';

        if ($id) {
            $config = BeanFactory::getBean('InboundEmail', $id);
        } else {
            $config = BeanFactory::newBean('InboundEmail');
        }

        if (!$config) {
            return ['message' => 'Failed to create/load record', 'type' => 'danger'];
        }

        $config->name = $_POST['name'] ?? '';
        $config->server_url = $_POST['server'] ?? '';
        $config->port = intval($_POST['port'] ?? 993);
        $config->protocol = $_POST['protocol'] ?? 'imap';
        $config->email_user = $_POST['username'] ?? '';
        $config->is_ssl = isset($_POST['ssl']) ? 1 : 0;
        $config->mailbox = $_POST['folder'] ?? 'INBOX';
        $config->status = $_POST['status'] ?? 'Active';

        // OAuth settings
        $config->auth_type = $_POST['auth_type'] ?? 'basic';
        $config->external_oauth_connection_id = $_POST['external_oauth_connection_id'] ?? '';

        if ($config->auth_type === 'basic') {
            $password = $_POST['password'] ?? '';
            if (!empty($password)) {
                $config->email_password = blowfishEncode(blowfishGetKey('encrypt_field'), $password);
            }
        } else {
            // Clear password for OAuth
            $config->email_password = '';
        }

        $config->save();

        $GLOBALS['log']->info("InboundEmail: Saved - ID: " . $config->id . ", Auth: " . $config->auth_type);

        return ['message' => 'Email account saved successfully', 'type' => 'success'];
    }

    private function deleteConfiguration()
    {
        $id = $_POST['record_id'] ?? '';

        if (!$id) {
            return ['message' => 'No record ID specified', 'type' => 'danger'];
        }

        $config = BeanFactory::getBean('InboundEmail', $id);
        if ($config) {
            $config->mark_deleted($id);
            return ['message' => 'Email account deleted', 'type' => 'success'];
        }

        return ['message' => 'Record not found', 'type' => 'danger'];
    }

    private function testConfiguration()
    {
        require_once('modules/InboundEmail/InboundEmailClient.php');

        $config = BeanFactory::newBean('InboundEmail');
        $config->server_url = $_POST['server'] ?? '';
        $config->port = intval($_POST['port'] ?? 993);
        $config->protocol = $_POST['protocol'] ?? 'imap';
        $config->email_user = $_POST['username'] ?? '';
        $config->is_ssl = isset($_POST['ssl']) ? 1 : 0;
        $config->mailbox = $_POST['folder'] ?? 'INBOX';
        $config->auth_type = $_POST['auth_type'] ?? 'basic';
        $config->external_oauth_connection_id = $_POST['external_oauth_connection_id'] ?? '';

        if ($config->auth_type === 'basic') {
            $password = $_POST['password'] ?? '';
            if (empty($password) && !empty($_POST['record_id'])) {
                $existing = BeanFactory::getBean('InboundEmail', $_POST['record_id']);
                if ($existing) {
                    $config->email_password = $existing->email_password;
                }
            } else {
                $config->email_password = blowfishEncode(blowfishGetKey('encrypt_field'), $password);
            }
        }

        $client = new InboundEmailClient($config);
        $result = $client->testConnection();

        if ($result['success']) {
            $details = $result['details'];
            $msg = "Connection successful! Found {$details['total_messages']} emails, {$details['unseen_messages']} unread.";
            return ['message' => $msg, 'type' => 'success'];
        }

        return ['message' => 'Connection failed: ' . $result['message'], 'type' => 'danger'];
    }

    private function getConfigurations()
    {
        $db = DBManagerFactory::getInstance();
        $configs = [];

        $sql = "SELECT id, name, server_url, status, auth_type FROM inbound_email WHERE deleted = 0 ORDER BY name";
        $result = $db->query($sql);

        while ($row = $db->fetchByAssoc($result)) {
            $configs[] = $row;
        }

        return $configs;
    }

    private function getOAuthConnections()
    {
        $db = DBManagerFactory::getInstance();
        $connections = [];

        $sql = "SELECT c.id, c.name, p.name as provider_name
                FROM external_oauth_connections c
                LEFT JOIN external_oauth_providers p ON c.external_oauth_provider_id = p.id
                WHERE c.deleted = 0 AND c.access_token IS NOT NULL AND c.access_token != ''
                ORDER BY c.name";
        $result = $db->query($sql);

        while ($row = $db->fetchByAssoc($result)) {
            $connections[] = $row;
        }

        return $connections;
    }

    private function renderConfigPage($configs, $editRecord, $imapAvailable, $message, $messageType, $oauthConnections)
    {
        ob_start();
        ?>
        <style>
        .inbound-email-config { max-width: 1200px; margin: 20px auto; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, sans-serif; }
        .config-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #0070d2; }
        .config-header h2 { margin: 0; color: #333; }
        .alert { padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
        .config-grid { display: grid; grid-template-columns: 300px 1fr; gap: 30px; }
        .accounts-list { background: #f8f9fa; padding: 20px; border-radius: 8px; }
        .accounts-list h3 { margin-top: 0; color: #333; }
        .account-item { background: white; padding: 12px; border-radius: 4px; margin-bottom: 10px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s; }
        .account-item:hover { border-color: #0070d2; }
        .account-item.active { border-color: #0070d2; background: #e6f2ff; }
        .account-name { font-weight: 600; color: #333; }
        .account-server { font-size: 12px; color: #666; }
        .account-status { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-top: 5px; }
        .status-Active { background: #d4edda; color: #155724; }
        .status-Inactive { background: #f8d7da; color: #721c24; }
        .oauth-badge { display: inline-block; background: #0070d2; color: white; padding: 1px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px; }
        .config-form { background: white; padding: 25px; border-radius: 8px; border: 1px solid #e1e5eb; }
        .form-section { margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        .form-section:last-of-type { border-bottom: none; }
        .form-section h4 { margin: 0 0 15px 0; color: #333; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; color: #333; }
        .form-group input[type="text"], .form-group input[type="password"], .form-group input[type="number"], .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #0070d2; box-shadow: 0 0 0 3px rgba(0,112,210,0.1); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .help-text { font-size: 12px; color: #666; margin-top: 4px; }
        .form-check { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .form-check input { width: auto; }
        .btn-group { display: flex; gap: 10px; margin-top: 20px; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; }
        .btn-primary { background: #0070d2; color: white; }
        .btn-primary:hover { background: #005fb3; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .add-account-btn { width: 100%; margin-top: 15px; }
        .oauth-section { display: none; }
        .oauth-section.visible { display: block; }
        .basic-section.hidden { display: none; }
        </style>

        <div class="inbound-email-config">
            <div class="config-header">
                <h2>Inbound Email Configuration</h2>
            </div>

            <?php if (!$imapAvailable): ?>
            <div class="alert alert-warning"><strong>Warning:</strong> PHP IMAP extension not installed.</div>
            <?php endif; ?>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <div class="config-grid">
                <div class="accounts-list">
                    <h3>Email Accounts</h3>
                    <?php foreach ($configs as $config): ?>
                    <div class="account-item <?php echo ($editRecord && $editRecord->id === $config['id']) ? 'active' : ''; ?>"
                         onclick="window.location='?module=InboundEmail&action=config&edit_id=<?php echo $config['id']; ?>'">
                        <div class="account-name">
                            <?php echo htmlspecialchars($config['name']); ?>
                            <?php if ($config['auth_type'] === 'oauth'): ?>
                            <span class="oauth-badge">OAuth</span>
                            <?php endif; ?>
                        </div>
                        <div class="account-server"><?php echo htmlspecialchars($config['server_url']); ?></div>
                        <span class="account-status status-<?php echo $config['status']; ?>">
                            <?php echo ucfirst($config['status']); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($configs)): ?>
                    <p style="color: #666; font-style: italic;">No email accounts configured.</p>
                    <?php endif; ?>
                    <button class="btn btn-primary add-account-btn" onclick="window.location='?module=InboundEmail&action=config'">
                        + Add Email Account
                    </button>
                </div>

                <?php
                $id = $editRecord ? $editRecord->id : '';
                $name = $editRecord ? htmlspecialchars($editRecord->name) : '';
                $server = $editRecord ? htmlspecialchars($editRecord->server_url) : '';
                $port = $editRecord ? $editRecord->port : '993';
                $username = $editRecord ? htmlspecialchars($editRecord->email_user) : '';
                $ssl = (!$editRecord || $editRecord->is_ssl) ? 'checked' : '';
                $folder = $editRecord ? htmlspecialchars($editRecord->mailbox) : 'INBOX';
                $status = $editRecord ? $editRecord->status : 'Active';
                $authType = $editRecord ? $editRecord->auth_type : 'basic';
                $oauthId = $editRecord ? $editRecord->external_oauth_connection_id : '';
                ?>

                <div class="config-form">
                    <form method="POST" id="configForm">
                        <input type="hidden" name="form_action" id="form_action" value="save">
                        <input type="hidden" name="record_id" value="<?php echo $id; ?>">

                        <div class="form-section">
                            <h4><?php echo $editRecord ? 'Edit Email Account' : 'Add Email Account'; ?></h4>
                            <div class="form-group">
                                <label>Account Name *</label>
                                <input type="text" name="name" required value="<?php echo $name; ?>" placeholder="e.g., Support Inbox">
                            </div>
                            <div class="form-group">
                                <label>Authentication Method *</label>
                                <select name="auth_type" id="auth_type" onchange="toggleAuthFields()">
                                    <option value="basic"<?php echo ($authType !== 'oauth') ? ' selected' : ''; ?>>Basic (Username/Password)</option>
                                    <option value="oauth"<?php echo ($authType === 'oauth') ? ' selected' : ''; ?>>OAuth 2.0 (Microsoft 365)</option>
                                </select>
                                <div class="help-text">Use OAuth for Microsoft 365 accounts</div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h4>Server Settings</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>IMAP Server *</label>
                                    <input type="text" name="server" required id="server_field" value="<?php echo $server; ?>" placeholder="outlook.office365.com">
                                    <div class="help-text">outlook.office365.com for O365</div>
                                </div>
                                <div class="form-group">
                                    <label>Port</label>
                                    <input type="number" name="port" value="<?php echo $port; ?>">
                                    <div class="help-text">993 for IMAPS</div>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Protocol</label>
                                    <select name="protocol"><option value="imap">IMAP</option></select>
                                </div>
                                <div class="form-group">
                                    <label>Folder</label>
                                    <input type="text" name="folder" value="<?php echo $folder; ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-check">
                                    <input type="checkbox" name="ssl" <?php echo $ssl; ?>> Use SSL/TLS (required for OAuth)
                                </label>
                            </div>
                        </div>

                        <!-- OAuth Section -->
                        <div class="form-section oauth-section" id="oauth_section">
                            <h4>OAuth Connection</h4>
                            <div class="form-group">
                                <label>Select OAuth Connection *</label>
                                <select name="external_oauth_connection_id" id="oauth_connection">
                                    <option value="">-- Select authorized connection --</option>
                                    <?php foreach ($oauthConnections as $conn): ?>
                                    <option value="<?php echo $conn['id']; ?>"<?php echo ($oauthId === $conn['id']) ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars($conn['name']); ?> (<?php echo htmlspecialchars($conn['provider_name']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($oauthConnections)): ?>
                                <div class="help-text"><strong style="color:#dc3545">No OAuth connections found.</strong> <a href="index.php?module=ExternalOAuthConnection&action=EditView&type=personal" target="_blank">Create one first</a></div>
                                <?php else: ?>
                                <div class="help-text">Select an authorized OAuth connection. <a href="index.php?module=ExternalOAuthConnection&action=EditView&type=personal" target="_blank">Create new</a></div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="text" name="oauth_username" id="oauth_username" value="<?php echo $username; ?>" placeholder="user@company.com">
                                <div class="help-text">Email address for the OAuth account</div>
                            </div>
                        </div>

                        <!-- Basic Auth Section -->
                        <div class="form-section basic-section" id="basic_section">
                            <h4>Credentials</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Username *</label>
                                    <input type="text" name="username" id="basic_username" value="<?php echo $username; ?>" placeholder="email@example.com">
                                </div>
                                <div class="form-group">
                                    <label>Password<?php echo $editRecord ? '' : ' *'; ?></label>
                                    <input type="password" name="password" placeholder="<?php echo $editRecord ? '(unchanged)' : 'Enter password'; ?>">
                                    <?php if ($editRecord): ?>
                                    <div class="help-text">Leave blank to keep existing password</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h4>Settings</h4>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="Active"<?php echo ($status === 'Active') ? ' selected' : ''; ?>>Active</option>
                                    <option value="Inactive"<?php echo ($status === 'Inactive') ? ' selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary"><?php echo $editRecord ? 'Update Account' : 'Save Account'; ?></button>
                            <button type="button" class="btn btn-success" onclick="testConnection()">Test Connection</button>
                            <?php if ($editRecord): ?>
                            <button type="button" class="btn btn-danger" onclick="deleteAccount()">Delete</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-secondary" onclick="window.location='?module=InboundEmail&action=config'">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        function toggleAuthFields() {
            var authType = document.getElementById("auth_type").value;
            var oauthSection = document.getElementById("oauth_section");
            var basicSection = document.getElementById("basic_section");
            if (authType === "oauth") {
                oauthSection.classList.add("visible");
                basicSection.classList.add("hidden");
                var server = document.getElementById("server_field");
                if (!server.value) server.value = "outlook.office365.com";
            } else {
                oauthSection.classList.remove("visible");
                basicSection.classList.remove("hidden");
            }
        }
        function testConnection() {
            document.getElementById("form_action").value = "test";
            document.getElementById("configForm").submit();
        }
        function deleteAccount() {
            if (confirm("Are you sure you want to delete this email account?")) {
                document.getElementById("form_action").value = "delete";
                document.getElementById("configForm").submit();
            }
        }
        document.addEventListener("DOMContentLoaded", function() { toggleAuthFields(); });
        </script>
        <?php
        return ob_get_clean();
    }
}
