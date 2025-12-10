<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

/**
 * PowerPack Custom ACL Actions
 *
 * These actions will appear in Admin -> Role Management for the FunnelDashboard module
 * Allows granular permission control for different dashboard views
 */

// Add custom dashboard actions to the module type
$GLOBALS['ACLActions']['module']['actions']['crodashboard'] = array(
    'aclaccess' => array(ACL_ALLOW_ALL, ACL_ALLOW_DEFAULT, ACL_ALLOW_NONE),
    'label' => 'LBL_ACTION_CRO_DASHBOARD',
    'default' => ACL_ALLOW_NONE,
);

$GLOBALS['ACLActions']['module']['actions']['salesopsdashboard'] = array(
    'aclaccess' => array(ACL_ALLOW_ALL, ACL_ALLOW_DEFAULT, ACL_ALLOW_NONE),
    'label' => 'LBL_ACTION_SALESOPS_DASHBOARD',
    'default' => ACL_ALLOW_NONE,
);

$GLOBALS['ACLActions']['module']['actions']['bdmdashboard'] = array(
    'aclaccess' => array(ACL_ALLOW_ALL, ACL_ALLOW_DEFAULT, ACL_ALLOW_NONE),
    'label' => 'LBL_ACTION_BDM_DASHBOARD',
    'default' => ACL_ALLOW_NONE,
);

$GLOBALS['ACLActions']['module']['actions']['dashboard'] = array(
    'aclaccess' => array(ACL_ALLOW_ALL, ACL_ALLOW_DEFAULT, ACL_ALLOW_NONE),
    'label' => 'LBL_ACTION_DASHBOARD',
    'default' => ACL_ALLOW_ALL,
);
