<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once('modules/ACLActions/SugarACLStrategy.php');

/**
 * SugarACLFunnelDashboard
 *
 * Role-based ACL strategy for FunnelDashboard module
 * Integrates with SuiteCRM's Role Management UI for granular permission control
 *
 * Permissions appear in Admin -> Role Management under FunnelDashboard:
 * - CRO Dashboard: Executive strategic overview
 * - Sales Ops Dashboard: Operations and workflow management
 * - BDM Dashboard: Individual sales rep dashboard
 * - Dashboard: General funnel analytics
 */
class SugarACLFunnelDashboard extends SugarACLStrategy
{
    /**
     * Dashboard actions that require specific permissions
     */
    protected $dashboardActions = array(
        'crodashboard',
        'salesopsdashboard',
        'bdmdashboard',
        'dashboard'
    );

    /**
     * Check access to FunnelDashboard actions
     *
     * @param string $module The module name
     * @param string $view The action/view being accessed
     * @param array $context Additional context
     * @return bool Whether access is allowed
     */
    public function checkAccess($module, $view, $context)
    {
        global $current_user;

        // Field-level access always allowed
        if ($view == 'field') {
            return true;
        }

        // Admin users always have full access
        if (!empty($current_user) && $current_user->isAdmin()) {
            return true;
        }

        // Check if this is a dashboard action
        if (in_array($view, $this->dashboardActions)) {
            return $this->checkDashboardAccess($view, $current_user);
        }

        // Standard actions (list, view, edit, delete) use default ACL
        return parent::checkAccess($module, $view, $context);
    }

    /**
     * Check dashboard-specific access using role permissions
     *
     * @param string $action The dashboard action
     * @param object $user The current user
     * @return bool Whether access is allowed
     */
    protected function checkDashboardAccess($action, $user)
    {
        if (empty($user) || empty($user->id)) {
            return false;
        }

        // Check ACL permission from database (set via Role Management)
        $access = $this->getUserAccess($user->id, 'FunnelDashboard', $action);

        // If explicit permission is set, use it
        if ($access !== null) {
            return $access >= ACL_ALLOW_ENABLED || $access >= ACL_ALLOW_ALL;
        }

        // Fallback to default based on action
        return $this->getDefaultAccess($action);
    }

    /**
     * Get user's ACL access level for a specific module action
     *
     * @param string $userId User ID
     * @param string $module Module name
     * @param string $action Action name
     * @return int|null ACL access level or null if not set
     */
    protected function getUserAccess($userId, $module, $action)
    {
        global $db;

        // Query the ACL permissions through roles
        $query = "
            SELECT ara.access_override
            FROM acl_roles_actions ara
            INNER JOIN acl_roles_users aru ON ara.role_id = aru.role_id AND aru.deleted = 0
            INNER JOIN acl_actions aa ON ara.action_id = aa.id AND aa.deleted = 0
            WHERE aru.user_id = " . $db->quoted($userId) . "
            AND aa.category = " . $db->quoted($module) . "
            AND aa.name = " . $db->quoted($action) . "
            AND ara.deleted = 0
            ORDER BY ara.access_override DESC
            LIMIT 1
        ";

        $result = $db->query($query);
        if ($row = $db->fetchByAssoc($result)) {
            return (int)$row['access_override'];
        }

        // Check default action permission (no role assigned)
        $query = "
            SELECT aclaccess
            FROM acl_actions
            WHERE category = " . $db->quoted($module) . "
            AND name = " . $db->quoted($action) . "
            AND deleted = 0
            LIMIT 1
        ";

        $result = $db->query($query);
        if ($row = $db->fetchByAssoc($result)) {
            return (int)$row['aclaccess'];
        }

        return null;
    }

    /**
     * Get default access for an action when no explicit permission exists
     *
     * @param string $action The action name
     * @return bool Default access
     */
    protected function getDefaultAccess($action)
    {
        $defaults = array(
            'dashboard' => true,        // General dashboard - allow by default
            'crodashboard' => false,    // CRO - restrict by default
            'salesopsdashboard' => false, // Sales Ops - restrict by default
            'bdmdashboard' => false,    // BDM - restrict by default
        );

        return isset($defaults[$action]) ? $defaults[$action] : false;
    }

    /**
     * Check if user has any dashboard access
     * Useful for menu visibility
     *
     * @param object $user The user to check
     * @return array List of accessible dashboards
     */
    public static function getAccessibleDashboards($user = null)
    {
        global $current_user;
        $user = $user ?: $current_user;

        if (empty($user)) {
            return array();
        }

        $acl = new self();
        $accessible = array();

        foreach (array('dashboard', 'crodashboard', 'salesopsdashboard', 'bdmdashboard') as $action) {
            if ($acl->checkAccess('FunnelDashboard', $action, array())) {
                $accessible[] = $action;
            }
        }

        return $accessible;
    }
}
