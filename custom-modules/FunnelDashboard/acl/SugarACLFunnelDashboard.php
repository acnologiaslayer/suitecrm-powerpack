<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once('modules/ACLActions/SugarACLStrategy.php');

class SugarACLFunnelDashboard extends SugarACLStrategy
{
    public function checkAccess($module, $view, $context)
    {
        // Allow all access - permissions can be managed via Role Management if needed
        return true;
    }
}
