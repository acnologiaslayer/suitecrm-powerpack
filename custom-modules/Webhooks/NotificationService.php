<?php
/**
 * Notification Service - Alert Creation and WebSocket Queue
 *
 * Handles creating SuiteCRM alerts and queuing them for WebSocket delivery.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

class NotificationService
{
    /**
     * Create notification for specified users/roles
     *
     * @param array $params Notification parameters
     * @return array Result with alert IDs and queue IDs
     */
    public function createNotification(array $params): array
    {
        $targetUsers = $this->resolveTargetUsers(
            $params['target_users'] ?? [],
            $params['target_roles'] ?? []
        );

        if (empty($targetUsers)) {
            return ['success' => false, 'error' => 'No valid target users specified'];
        }

        $alertIds = [];
        $queueIds = [];
        $errors = [];

        foreach ($targetUsers as $userId) {
            try {
                // Create SuiteCRM Alert (visible in bell icon)
                $alertId = $this->createAlert($userId, $params);
                $alertIds[] = $alertId;

                // Queue for WebSocket delivery (real-time)
                $queueId = $this->queueForWebSocket($alertId, $userId, $params);
                $queueIds[] = $queueId;
            } catch (Exception $e) {
                $errors[] = "User $userId: " . $e->getMessage();
                $GLOBALS['log']->error("NotificationService error for user $userId: " . $e->getMessage());
            }
        }

        return [
            'success' => count($alertIds) > 0,
            'alert_ids' => $alertIds,
            'queue_ids' => $queueIds,
            'user_count' => count($alertIds),
            'errors' => $errors
        ];
    }

    /**
     * Create a SuiteCRM Alert record
     *
     * @param string $userId Target user ID
     * @param array $params Alert parameters
     * @return string Alert ID
     */
    private function createAlert(string $userId, array $params): string
    {
        $alert = BeanFactory::newBean('Alerts');

        $alert->id = create_guid();
        $alert->new_with_id = true;
        $alert->name = $this->sanitizeString($params['title'] ?? 'Notification');
        $alert->description = $this->sanitizeString($params['message'] ?? '');
        $alert->assigned_user_id = $userId;
        $alert->type = $this->validateAlertType($params['type'] ?? 'info');
        $alert->is_read = 0;

        // Set redirect URL
        if (!empty($params['target_module']) && !empty($params['target_record'])) {
            $module = $this->sanitizeString($params['target_module']);
            $record = $this->sanitizeString($params['target_record']);
            $alert->url_redirect = "index.php?module=$module&action=DetailView&record=$record";
            $alert->target_module = $module;
        } elseif (!empty($params['url_redirect'])) {
            $alert->url_redirect = $this->sanitizeUrl($params['url_redirect']);
        }

        $alert->save();

        $GLOBALS['log']->info("NotificationService: Created alert {$alert->id} for user $userId");

        return $alert->id;
    }

    /**
     * Queue notification for WebSocket delivery
     *
     * @param string $alertId Alert ID
     * @param string $userId Target user ID
     * @param array $params Notification parameters
     * @return string Queue entry ID
     */
    private function queueForWebSocket(string $alertId, string $userId, array $params): string
    {
        global $db;

        $id = create_guid();

        $payload = json_encode([
            'alert_id' => $alertId,
            'title' => $params['title'] ?? 'Notification',
            'message' => $params['message'] ?? '',
            'type' => $this->validateAlertType($params['type'] ?? 'info'),
            'priority' => $this->validatePriority($params['priority'] ?? 'normal'),
            'url_redirect' => $params['url_redirect'] ?? '',
            'target_module' => $params['target_module'] ?? '',
            'target_record' => $params['target_record'] ?? '',
            'metadata' => $params['metadata'] ?? []
        ], JSON_UNESCAPED_SLASHES);

        $sql = "INSERT INTO notification_queue
                (id, alert_id, user_id, payload, status, created_at)
                VALUES (
                    '" . $db->quote($id) . "',
                    '" . $db->quote($alertId) . "',
                    '" . $db->quote($userId) . "',
                    '" . $db->quote($payload) . "',
                    'pending',
                    NOW()
                )";

        $db->query($sql);

        return $id;
    }

    /**
     * Resolve target users from user IDs and role names
     *
     * @param array $userIds Direct user IDs
     * @param array $roleNames Role names to resolve
     * @return array Unique user IDs
     */
    private function resolveTargetUsers(array $userIds, array $roleNames): array
    {
        $resolvedUsers = [];

        // Direct user IDs
        foreach ($userIds as $userId) {
            if ($this->userExists($userId)) {
                $resolvedUsers[$userId] = true;
            }
        }

        // Users by role
        foreach ($roleNames as $roleName) {
            $usersInRole = $this->getUsersByRole($roleName);
            foreach ($usersInRole as $userId) {
                $resolvedUsers[$userId] = true;
            }
        }

        return array_keys($resolvedUsers);
    }

    /**
     * Check if a user exists and is active
     *
     * @param string $userId User ID to check
     * @return bool True if user exists
     */
    private function userExists(string $userId): bool
    {
        global $db;

        $sql = "SELECT id FROM users
                WHERE id = '" . $db->quote($userId) . "'
                AND deleted = 0 AND status = 'Active'";
        $result = $db->query($sql);

        return $db->fetchByAssoc($result) !== false;
    }

    /**
     * Get all active users in a given role
     *
     * @param string $roleName Role name
     * @return array User IDs
     */
    private function getUsersByRole(string $roleName): array
    {
        global $db;

        $sql = "SELECT u.id
                FROM users u
                JOIN acl_roles_users aru ON u.id = aru.user_id AND aru.deleted = 0
                JOIN acl_roles ar ON aru.role_id = ar.id AND ar.deleted = 0
                WHERE ar.name = '" . $db->quote($roleName) . "'
                AND u.deleted = 0 AND u.status = 'Active'";

        $result = $db->query($sql);
        $users = [];

        while ($row = $db->fetchByAssoc($result)) {
            $users[] = $row['id'];
        }

        return $users;
    }

    /**
     * Get pending notifications for a user (for WebSocket initial sync)
     *
     * @param string $userId User ID
     * @param int $limit Maximum notifications to return
     * @return array Pending notifications
     */
    public function getPendingNotifications(string $userId, int $limit = 50): array
    {
        global $db;

        $sql = "SELECT id, payload, created_at
                FROM notification_queue
                WHERE user_id = '" . $db->quote($userId) . "'
                AND status = 'pending'
                ORDER BY created_at ASC
                LIMIT " . intval($limit);

        $result = $db->query($sql);
        $notifications = [];

        while ($row = $db->fetchByAssoc($result)) {
            $notifications[] = [
                'id' => $row['id'],
                'payload' => json_decode($row['payload'], true),
                'created_at' => $row['created_at']
            ];
        }

        return $notifications;
    }

    /**
     * Mark notification as sent via WebSocket
     *
     * @param string $queueId Queue entry ID
     */
    public function markAsSent(string $queueId): void
    {
        global $db;

        $sql = "UPDATE notification_queue
                SET status = 'sent', sent_at = NOW()
                WHERE id = '" . $db->quote($queueId) . "'";
        $db->query($sql);
    }

    /**
     * Mark notification as acknowledged by user
     *
     * @param string $queueId Queue entry ID
     */
    public function markAsAcknowledged(string $queueId): void
    {
        global $db;

        $sql = "UPDATE notification_queue
                SET status = 'acknowledged', acknowledged_at = NOW()
                WHERE id = '" . $db->quote($queueId) . "'";
        $db->query($sql);
    }

    /**
     * Clean up old notification queue entries
     *
     * @param int $daysOld Days after which to delete
     */
    public function cleanupOldNotifications(int $daysOld = 7): void
    {
        global $db;

        $sql = "DELETE FROM notification_queue
                WHERE created_at < DATE_SUB(NOW(), INTERVAL " . intval($daysOld) . " DAY)";
        $db->query($sql);

        $GLOBALS['log']->info("NotificationService: Cleaned up notifications older than $daysOld days");
    }

    /**
     * Sanitize string input
     */
    private function sanitizeString(string $input): string
    {
        return htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize URL input (only allow relative URLs or https)
     */
    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);

        // Allow relative URLs starting with index.php
        if (strpos($url, 'index.php') === 0) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }

        // Allow https URLs only
        if (filter_var($url, FILTER_VALIDATE_URL) && strpos($url, 'https://') === 0) {
            return $url;
        }

        // Default: return empty
        return '';
    }

    /**
     * Validate alert type
     */
    private function validateAlertType(string $type): string
    {
        $validTypes = ['info', 'success', 'warning', 'error'];
        return in_array($type, $validTypes) ? $type : 'info';
    }

    /**
     * Validate priority level
     */
    private function validatePriority(string $priority): string
    {
        $validPriorities = ['low', 'normal', 'high', 'urgent'];
        return in_array($priority, $validPriorities) ? $priority : 'normal';
    }
}
