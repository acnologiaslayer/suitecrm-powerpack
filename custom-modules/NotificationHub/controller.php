<?php
/**
 * NotificationHub Controller
 *
 * Handles actions for the notification admin module.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/Controller/SugarController.php');

class NotificationHubController extends SugarController
{
    /**
     * Default action - redirect to settings
     */
    public function action_index()
    {
        $this->action_settings();
    }

    /**
     * Settings/API Key management action
     */
    public function action_settings()
    {
        $this->view = 'settings';
    }

    /**
     * Test notification action
     */
    public function action_test()
    {
        $this->view = 'test';
    }

    /**
     * Get JWT token for WebSocket authentication
     */
    public function action_getToken()
    {
        global $current_user;

        // Prevent view from loading - this is a pure JSON API endpoint
        $this->view = 'ajax';

        // Clean any previous output
        if (ob_get_level()) {
            ob_clean();
        }

        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');

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

    /**
     * Create API key action (AJAX)
     */
    public function action_createKey()
    {
        global $current_user;

        header('Content-Type: application/json');

        // Check admin access
        if (!$current_user->is_admin) {
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            return;
        }

        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Name is required']);
            return;
        }

        require_once('modules/NotificationHub/NotificationHub.php');

        $hub = new NotificationHub();
        $result = $hub->createApiKey($name, $description);

        echo json_encode([
            'success' => true,
            'data' => $result
        ]);
    }

    /**
     * Toggle API key status (AJAX)
     */
    public function action_toggleKey()
    {
        global $current_user;

        header('Content-Type: application/json');

        if (!$current_user->is_admin) {
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            return;
        }

        $id = isset($_POST['id']) ? $_POST['id'] : '';
        $active = isset($_POST['active']) ? $_POST['active'] === 'true' : false;

        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'ID is required']);
            return;
        }

        require_once('modules/NotificationHub/NotificationHub.php');

        $result = NotificationHub::toggleApiKey($id, $active);

        echo json_encode(['success' => $result]);
    }

    /**
     * Delete API key (AJAX)
     */
    public function action_deleteKey()
    {
        global $current_user;

        header('Content-Type: application/json');

        if (!$current_user->is_admin) {
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            return;
        }

        $id = isset($_POST['id']) ? $_POST['id'] : '';

        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'ID is required']);
            return;
        }

        require_once('modules/NotificationHub/NotificationHub.php');

        $result = NotificationHub::deleteApiKey($id);

        echo json_encode(['success' => $result]);
    }

    /**
     * Send test notification (AJAX)
     */
    public function action_sendTest()
    {
        global $current_user;

        header('Content-Type: application/json');

        if (!$current_user->is_admin) {
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            return;
        }

        $title = isset($_POST['title']) ? trim($_POST['title']) : 'Test Notification';
        $message = isset($_POST['message']) ? trim($_POST['message']) : 'This is a test notification from SuiteCRM.';
        $type = isset($_POST['type']) ? $_POST['type'] : 'info';
        $targetUser = isset($_POST['target_user']) ? $_POST['target_user'] : $current_user->id;

        require_once('modules/Webhooks/NotificationService.php');

        $service = new NotificationService();
        $result = $service->createNotification([
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'priority' => 'normal',
            'target_users' => [$targetUser]
        ]);

        echo json_encode($result);
    }

    /**
     * Get API key details (AJAX)
     */
    public function action_getKey()
    {
        global $current_user;

        header('Content-Type: application/json');

        if (!$current_user->is_admin) {
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            return;
        }

        $id = isset($_GET['id']) ? $_GET['id'] : '';

        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'ID is required']);
            return;
        }

        require_once('modules/NotificationHub/NotificationHub.php');

        $key = NotificationHub::getApiKeyById($id);

        if ($key) {
            echo json_encode(['success' => true, 'data' => $key]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Key not found']);
        }
    }
}
