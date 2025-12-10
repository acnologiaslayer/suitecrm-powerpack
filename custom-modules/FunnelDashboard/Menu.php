<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

global $mod_strings, $app_strings, $sugar_config, $current_user;

// Include ACL check
require_once('modules/ACLActions/ACLAction.php');

$module_menu = array();

// Always show main dashboard (ACL controlled)
if (ACLAction::userHasAccess($GLOBALS['current_user']->id, 'FunnelDashboard', 'dashboard', 'view')) {
    $module_menu[] = array(
        'index.php?module=FunnelDashboard&action=dashboard',
        $mod_strings['LNK_DASHBOARD'] ?? 'Sales Funnel Dashboard',
        'FunnelDashboard',
        'FunnelDashboard'
    );
}

// CRO Dashboard - check specific permission
if (ACLAction::userHasAccess($GLOBALS['current_user']->id, 'FunnelDashboard', 'crodashboard', 'view')) {
    $module_menu[] = array(
        'index.php?module=FunnelDashboard&action=crodashboard',
        $mod_strings['LNK_CRO_DASHBOARD'] ?? 'CRO Dashboard',
        'FunnelDashboard',
        'FunnelDashboard'
    );
}

// Sales Ops Dashboard - check specific permission
if (ACLAction::userHasAccess($GLOBALS['current_user']->id, 'FunnelDashboard', 'salesopsdashboard', 'view')) {
    $module_menu[] = array(
        'index.php?module=FunnelDashboard&action=salesopsdashboard',
        $mod_strings['LNK_SALESOPS_DASHBOARD'] ?? 'Sales Ops Dashboard',
        'FunnelDashboard',
        'FunnelDashboard'
    );
}

// BDM Dashboard - check specific permission
if (ACLAction::userHasAccess($GLOBALS['current_user']->id, 'FunnelDashboard', 'bdmdashboard', 'view')) {
    $module_menu[] = array(
        'index.php?module=FunnelDashboard&action=bdmdashboard',
        $mod_strings['LNK_BDM_DASHBOARD'] ?? 'BDM Dashboard',
        'FunnelDashboard',
        'FunnelDashboard'
    );
}

// Always show list view
$module_menu[] = array(
    'index.php?module=FunnelDashboard&action=index',
    $mod_strings['LNK_LIST'] ?? 'View Funnels',
    'FunnelDashboard',
    'FunnelDashboard'
);
