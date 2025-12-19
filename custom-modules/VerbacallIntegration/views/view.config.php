<?php
/**
 * VerbacallIntegration - Configuration View
 *
 * Admin panel for configuring Verbacall integration settings.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');
require_once('modules/VerbacallIntegration/VerbacallClient.php');

class VerbacallIntegrationViewConfig extends SugarView
{
    public function display()
    {
        global $current_user, $sugar_config;

        // Check admin access
        if (!is_admin($current_user)) {
            sugar_die('Access Denied');
        }

        $message = '';
        $messageType = '';

        // Handle save
        if (!empty($_POST['save_config'])) {
            $this->saveConfig();
            $message = 'Configuration saved successfully!';
            $messageType = 'success';
        }

        // Handle test connection
        if (!empty($_POST['test_connection'])) {
            try {
                $client = new VerbacallClient();
                $plans = $client->getPlans();
                $planCount = count($plans);
                $message = "Connection successful! Found {$planCount} plans.";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Connection failed: ' . $e->getMessage();
                $messageType = 'error';
            }
        }

        // Get current config
        $apiUrl = getenv('VERBACALL_API_URL') ?: ($sugar_config['verbacall_api_url'] ?? 'https://app.verbacall.com');
        $defaultDiscount = $sugar_config['verbacall_default_discount'] ?? 10;
        $expiryDays = $sugar_config['verbacall_expiry_days'] ?? 7;

        $this->displayConfigUI($apiUrl, $defaultDiscount, $expiryDays, $message, $messageType);
    }

    private function saveConfig()
    {
        global $sugar_config;

        $apiUrl = trim($_POST['api_url'] ?? '');
        $defaultDiscount = floatval($_POST['default_discount'] ?? 10);
        $expiryDays = intval($_POST['expiry_days'] ?? 7);

        // Validate
        if (empty($apiUrl)) {
            $apiUrl = 'https://app.verbacall.com';
        }

        if ($defaultDiscount < 0 || $defaultDiscount > 100) {
            $defaultDiscount = 10;
        }

        if ($expiryDays < 1 || $expiryDays > 30) {
            $expiryDays = 7;
        }

        // Update config
        $sugar_config['verbacall_api_url'] = $apiUrl;
        $sugar_config['verbacall_default_discount'] = $defaultDiscount;
        $sugar_config['verbacall_expiry_days'] = $expiryDays;

        // Write to config_override.php
        $configOverride = "<?php\n";
        $configOverride .= "// Verbacall Integration Configuration\n";
        $configOverride .= "\$sugar_config['verbacall_api_url'] = " . var_export($apiUrl, true) . ";\n";
        $configOverride .= "\$sugar_config['verbacall_default_discount'] = " . var_export($defaultDiscount, true) . ";\n";
        $configOverride .= "\$sugar_config['verbacall_expiry_days'] = " . var_export($expiryDays, true) . ";\n";

        // Read existing config_override.php and merge
        $configFile = 'config_override.php';
        if (file_exists($configFile)) {
            $existingConfig = file_get_contents($configFile);

            // Remove existing Verbacall config lines
            $existingConfig = preg_replace('/\/\/ Verbacall Integration Configuration\n/', '', $existingConfig);
            $existingConfig = preg_replace('/\$sugar_config\[\'verbacall_[^\']+\'\]\s*=\s*[^;]+;\n?/', '', $existingConfig);

            // Append new config
            $existingConfig = rtrim($existingConfig) . "\n\n// Verbacall Integration Configuration\n";
            $existingConfig .= "\$sugar_config['verbacall_api_url'] = " . var_export($apiUrl, true) . ";\n";
            $existingConfig .= "\$sugar_config['verbacall_default_discount'] = " . var_export($defaultDiscount, true) . ";\n";
            $existingConfig .= "\$sugar_config['verbacall_expiry_days'] = " . var_export($expiryDays, true) . ";\n";

            file_put_contents($configFile, $existingConfig);
        }

        $GLOBALS['log']->info("VerbacallIntegration: Configuration saved");
    }

    private function displayConfigUI($apiUrl, $defaultDiscount, $expiryDays, $message, $messageType)
    {
        $escapedApiUrl = htmlspecialchars($apiUrl);

        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Verbacall Integration Configuration</title>
    <style>
        .verbacall-config {
            max-width: 700px;
            margin: 20px auto;
            padding: 30px;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .verbacall-config h2 {
            color: #fff;
            margin-bottom: 30px;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .verbacall-config h2::before {
            content: "";
            font-size: 28px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            color: rgba(255,255,255,0.8);
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            background: rgba(0,0,0,0.3);
            color: #fff;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #4ecca3;
        }
        .form-group .help-text {
            color: rgba(255,255,255,0.5);
            font-size: 12px;
            margin-top: 6px;
        }
        .button-row {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }
        .btn {
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .btn-primary {
            background: linear-gradient(135deg, #4ecca3, #38a3a5);
            color: #1a1a2e;
        }
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .message.success {
            background: rgba(78,204,163,0.2);
            color: #4ecca3;
            border: 1px solid rgba(78,204,163,0.3);
        }
        .message.error {
            background: rgba(220,53,69,0.2);
            color: #ff6b6b;
            border: 1px solid rgba(220,53,69,0.3);
        }
        .env-note {
            background: rgba(255,193,7,0.1);
            border: 1px solid rgba(255,193,7,0.3);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #ffc107;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="verbacall-config">
        <h2>Verbacall Integration</h2>
HTML;

        if ($message) {
            echo "<div class=\"message {$messageType}\">{$message}</div>";
        }

        if (getenv('VERBACALL_API_URL')) {
            echo '<div class="env-note">Note: API URL is set via environment variable (VERBACALL_API_URL) and will override the value below.</div>';
        }

        echo <<<HTML
        <form method="post">
            <div class="form-group">
                <label>Verbacall API URL</label>
                <input type="url" name="api_url" value="{$escapedApiUrl}" placeholder="https://app.verbacall.com">
                <div class="help-text">Base URL for Verbacall API. Default: https://app.verbacall.com</div>
            </div>

            <div class="form-group">
                <label>Default Discount Percentage</label>
                <input type="number" name="default_discount" value="{$defaultDiscount}" min="0" max="100" step="1">
                <div class="help-text">Default discount percentage for payment links (0-100)</div>
            </div>

            <div class="form-group">
                <label>Offer Expiry Days</label>
                <input type="number" name="expiry_days" value="{$expiryDays}" min="1" max="30">
                <div class="help-text">Number of days before discount offers expire (1-30)</div>
            </div>

            <div class="button-row">
                <button type="submit" name="save_config" value="1" class="btn btn-primary">Save Configuration</button>
                <button type="submit" name="test_connection" value="1" class="btn btn-secondary">Test Connection</button>
            </div>
        </form>
    </div>
</body>
</html>
HTML;
    }
}
