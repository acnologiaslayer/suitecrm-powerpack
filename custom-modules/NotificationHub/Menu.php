<?php
/**
 * NotificationHub Menu
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

global $mod_strings, $app_strings;

if (ACLController::checkAccess('NotificationHub', 'edit', true)) {
    $module_menu = array();
    $module_menu[] = array(
        'index.php?module=NotificationHub&action=settings',
        'Settings',
        'NotificationHub'
    );
    $module_menu[] = array(
        'index.php?module=NotificationHub&action=settings#test',
        'Test Notifications',
        'NotificationHub'
    );
}
