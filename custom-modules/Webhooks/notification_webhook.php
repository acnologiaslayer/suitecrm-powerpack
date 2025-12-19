<?php
/**
 * Notification Webhook Entry Point
 * POST /legacy/notification_webhook.php
 *
 * External systems can push notifications to SuiteCRM users via this endpoint.
 *
 * Actions:
 *   - create: Create a new notification for specified users
 *   - batch:  Create multiple notifications
 *   - status: Check API status
 *
 * Authentication Methods:
 *   - API Key: X-API-Key header
 *   - HMAC:    X-Signature + X-Timestamp headers
 *
 * Example:
 *   curl -X POST https://yoursite.com/legacy/notification_webhook.php \
 *     -H "X-API-Key: your-api-key" \
 *     -H "Content-Type: application/json" \
 *     -d '{"title": "New Lead", "message": "Lead assigned to you", "target_users": ["user-id"]}'
 */

// Prevent CLI execution
if (php_sapi_name() === 'cli') {
    die('CLI not supported');
}

// Set JSON response headers early
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Bootstrap SuiteCRM
$legacyRoot = dirname(__FILE__);
if (!file_exists($legacyRoot . '/config.php')) {
    // Fallback: try parent directories if deployed to modules/Webhooks/
    $legacyRoot = dirname(__FILE__) . '/../..';
}
if (!file_exists($legacyRoot . '/config.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration error', 'message' => 'SuiteCRM not properly configured']);
    exit;
}

chdir($legacyRoot);
define('sugarEntry', true);

// Load SuiteCRM entrypoint
require_once('include/entryPoint.php');

// Load notification components
require_once('modules/Webhooks/NotificationSecurity.php');
require_once('modules/Webhooks/NotificationService.php');

// Initialize components
$security = new NotificationSecurity();
$service = new NotificationService();

// Log the request
$clientIp = $security->getClientIp();
$GLOBALS['log']->info("Notification Webhook - Request from $clientIp, Method: " . $_SERVER['REQUEST_METHOD']);

// Only allow POST requests (except for status check)
$action = $_REQUEST['action'] ?? 'create';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action !== 'status') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed', 'message' => 'Only POST requests are accepted']);
    exit;
}

// Status check doesn't require authentication
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'ok',
        'version' => '1.0.0',
        'timestamp' => date('c'),
        'endpoints' => [
            'create' => 'POST /legacy/notification_webhook.php',
            'batch' => 'POST /legacy/notification_webhook.php?action=batch',
            'status' => 'GET /legacy/notification_webhook.php?action=status'
        ]
    ]);
    exit;
}

// Rate limiting check
if (!$security->checkRateLimit($clientIp)) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Rate limit exceeded',
        'message' => 'Too many requests. Please try again later.',
        'retry_after' => 60
    ]);
    exit;
}

// Authenticate request
$authMethod = $security->detectAuthMethod();
if (!$security->validateAuth($authMethod)) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Unauthorized',
        'message' => 'Invalid or missing authentication credentials',
        'hint' => 'Use X-API-Key header or X-Signature + X-Timestamp headers'
    ]);
    exit;
}

// Route to handler
try {
    switch ($action) {
        case 'create':
            handleCreate($service);
            break;
        case 'batch':
            handleBatch($service);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action', 'message' => "Unknown action: $action"]);
    }
} catch (Exception $e) {
    $GLOBALS['log']->fatal("Notification Webhook error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error', 'message' => 'An unexpected error occurred']);
}

exit;

// =============================================================================
// Handler Functions
// =============================================================================

/**
 * Handle single notification creation
 */
function handleCreate($service)
{
    $payload = getJsonPayload();

    if (empty($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad request', 'message' => 'Invalid JSON payload']);
        return;
    }

    // Validate required fields
    if (empty($payload['title'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Validation error', 'message' => 'title is required']);
        return;
    }

    if (empty($payload['target_users']) && empty($payload['target_roles'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Validation error', 'message' => 'target_users or target_roles is required']);
        return;
    }

    $result = $service->createNotification([
        'title'         => $payload['title'] ?? 'Notification',
        'message'       => $payload['message'] ?? '',
        'type'          => $payload['type'] ?? 'info',
        'priority'      => $payload['priority'] ?? 'normal',
        'target_users'  => $payload['target_users'] ?? [],
        'target_roles'  => $payload['target_roles'] ?? [],
        'url_redirect'  => $payload['url_redirect'] ?? '',
        'target_module' => $payload['target_module'] ?? '',
        'target_record' => $payload['target_record'] ?? '',
        'metadata'      => $payload['metadata'] ?? [],
    ]);

    if ($result['success']) {
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'data' => [
                'alert_ids' => $result['alert_ids'],
                'queue_ids' => $result['queue_ids'],
                'user_count' => $result['user_count']
            ]
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Unknown error',
            'errors' => $result['errors'] ?? []
        ]);
    }
}

/**
 * Handle batch notification creation
 */
function handleBatch($service)
{
    $payload = getJsonPayload();

    if (empty($payload) || empty($payload['notifications']) || !is_array($payload['notifications'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad request', 'message' => 'notifications array is required']);
        return;
    }

    // Limit batch size
    $maxBatchSize = 50;
    if (count($payload['notifications']) > $maxBatchSize) {
        http_response_code(400);
        echo json_encode(['error' => 'Batch too large', 'message' => "Maximum batch size is $maxBatchSize"]);
        return;
    }

    $results = [];
    $successCount = 0;
    $errorCount = 0;

    foreach ($payload['notifications'] as $index => $notification) {
        // Validate each notification
        if (empty($notification['title'])) {
            $results[] = ['index' => $index, 'success' => false, 'error' => 'title is required'];
            $errorCount++;
            continue;
        }

        if (empty($notification['target_users']) && empty($notification['target_roles'])) {
            $results[] = ['index' => $index, 'success' => false, 'error' => 'target_users or target_roles is required'];
            $errorCount++;
            continue;
        }

        $result = $service->createNotification([
            'title'         => $notification['title'] ?? 'Notification',
            'message'       => $notification['message'] ?? '',
            'type'          => $notification['type'] ?? 'info',
            'priority'      => $notification['priority'] ?? 'normal',
            'target_users'  => $notification['target_users'] ?? [],
            'target_roles'  => $notification['target_roles'] ?? [],
            'url_redirect'  => $notification['url_redirect'] ?? '',
            'target_module' => $notification['target_module'] ?? '',
            'target_record' => $notification['target_record'] ?? '',
            'metadata'      => $notification['metadata'] ?? [],
        ]);

        if ($result['success']) {
            $results[] = [
                'index' => $index,
                'success' => true,
                'alert_ids' => $result['alert_ids'],
                'user_count' => $result['user_count']
            ];
            $successCount++;
        } else {
            $results[] = [
                'index' => $index,
                'success' => false,
                'error' => $result['error'] ?? 'Unknown error'
            ];
            $errorCount++;
        }
    }

    $statusCode = $errorCount > 0 ? ($successCount > 0 ? 207 : 400) : 201;
    http_response_code($statusCode);

    echo json_encode([
        'success' => $successCount > 0,
        'summary' => [
            'total' => count($payload['notifications']),
            'succeeded' => $successCount,
            'failed' => $errorCount
        ],
        'results' => $results
    ]);
}

/**
 * Get and parse JSON payload from request body
 */
function getJsonPayload(): ?array
{
    $body = file_get_contents('php://input');

    if (empty($body)) {
        return null;
    }

    $payload = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $payload;
}
