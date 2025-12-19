<?php
/**
 * NotificationHub Module - Bean Class
 *
 * Admin module for managing notification API keys and testing the notification system.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('data/SugarBean.php');

class NotificationHub extends SugarBean
{
    public $module_dir = 'NotificationHub';
    public $object_name = 'NotificationHub';
    public $table_name = 'notification_api_keys';
    public $new_schema = true;
    public $disable_row_level_security = true;

    public $id;
    public $name;
    public $api_key;
    public $description;
    public $created_by;
    public $created_at;
    public $last_used_at;
    public $is_active;
    public $deleted;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get bean table name
     */
    public function getTableName()
    {
        return 'notification_api_keys';
    }

    /**
     * Generate a new API key
     */
    public static function generateApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Create a new API key entry
     */
    public function createApiKey(string $name, string $description = ''): array
    {
        global $current_user, $db;

        $id = create_guid();
        $apiKey = self::generateApiKey();

        $sql = "INSERT INTO notification_api_keys
                (id, name, api_key, description, created_by, created_at, is_active, deleted)
                VALUES (
                    '" . $db->quote($id) . "',
                    '" . $db->quote($name) . "',
                    '" . $db->quote($apiKey) . "',
                    '" . $db->quote($description) . "',
                    '" . $db->quote($current_user->id) . "',
                    NOW(),
                    1,
                    0
                )";

        $db->query($sql);

        return [
            'id' => $id,
            'name' => $name,
            'api_key' => $apiKey,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Get all API keys (masked)
     */
    public static function getAllApiKeys(): array
    {
        global $db;

        $sql = "SELECT id, name, description, created_at, last_used_at, is_active
                FROM notification_api_keys
                WHERE deleted = 0
                ORDER BY created_at DESC";

        $result = $db->query($sql);
        $keys = [];

        while ($row = $db->fetchByAssoc($result)) {
            $keys[] = $row;
        }

        return $keys;
    }

    /**
     * Toggle API key active status
     */
    public static function toggleApiKey(string $id, bool $active): bool
    {
        global $db;

        $sql = "UPDATE notification_api_keys
                SET is_active = " . ($active ? 1 : 0) . "
                WHERE id = '" . $db->quote($id) . "'";

        return $db->query($sql) !== false;
    }

    /**
     * Delete API key (soft delete)
     */
    public static function deleteApiKey(string $id): bool
    {
        global $db;

        $sql = "UPDATE notification_api_keys
                SET deleted = 1
                WHERE id = '" . $db->quote($id) . "'";

        return $db->query($sql) !== false;
    }

    /**
     * Get API key by ID (full key for display)
     */
    public static function getApiKeyById(string $id): ?array
    {
        global $db;

        $sql = "SELECT id, name, api_key, description, created_at, last_used_at, is_active
                FROM notification_api_keys
                WHERE id = '" . $db->quote($id) . "' AND deleted = 0";

        $result = $db->query($sql);
        $row = $db->fetchByAssoc($result);

        return $row ?: null;
    }

    /**
     * Check if user has admin access
     */
    public static function hasAdminAccess(): bool
    {
        global $current_user;

        if (empty($current_user) || empty($current_user->id)) {
            return false;
        }

        return $current_user->is_admin == 1;
    }

    /**
     * Implement bean_implements for ACL
     */
    public function bean_implements($interface)
    {
        switch ($interface) {
            case 'ACL':
                return true;
        }
        return false;
    }
}
