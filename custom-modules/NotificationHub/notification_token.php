<?php
/**
 * Notification Webhook Entry Point
 * Direct entry point for notification token generation - bypasses SuiteCRM auth redirect
 *
 * Webhook URLs:
 *   Token: https://yourdomain.com/legacy/notification_webhook.php?action=token
 */

// START OUTPUT BUFFERING IMMEDIATELY
ob_start();

// Prevent CLI execution
if (php_sapi_name() === 'cli') {
    die('CLI not supported');
}

// Change to SuiteCRM legacy root
$legacyRoot = dirname(__FILE__);
if (!file_exists($legacyRoot . '/config.php')) {
    $legacyRoot = dirname(__FILE__, 3); // Go up 3 levels: NotificationHub -> modules -> legacy
}
if (!file_exists($legacyRoot . '/config.php')) {
    $legacyRoot = dirname(__FILE__, 2);
}
if (!file_exists($legacyRoot . '/config.php')) {
    while (ob_get_level()) { ob_end_clean(); }
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Configuration error']);
    exit;
}

chdir($legacyRoot);
define('sugarEntry', true);

// Use SuiteCRM's entryPoint for proper bootstrap
require_once('include/entryPoint.php');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear any output from entryPoint
while (ob_get_level()) {
    ob_end_clean();
}

// Set JSON headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Try to load current user from session
global $current_user;
if (empty($current_user) || empty($current_user->id)) {
    if (!empty($_SESSION['authenticated_user_id'])) {
        $current_user = BeanFactory::getBean('Users', $_SESSION['authenticated_user_id']);
    } elseif (!empty($_SESSION['user_id'])) {
        $current_user = BeanFactory::getBean('Users', $_SESSION['user_id']);
    }
}

// Get action parameter
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'token';

// Route to handler
switch ($action) {
    case 'token':
        handleGetToken();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
}

/**
 * Generate JWT token for WebSocket authentication
 */
function handleGetToken() {
    global $current_user;

    // Check if user is logged in
    if (empty($current_user) || empty($current_user->id)) {
        echo json_encode([
            'success' => false,
            'error' => 'Not authenticated. Please log in.'
        ]);
        exit;
    }

    try {
        // Load security class
        require_once('modules/Webhooks/NotificationSecurity.php');

        // Generate JWT token
        $token = NotificationSecurity::createJwtToken($current_user->id, 3600);

        echo json_encode([
            'success' => true,
            'token' => $token,
            'expires_in' => 3600
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Token generation failed: ' . $e->getMessage()
        ]);
    }
    exit;
}
