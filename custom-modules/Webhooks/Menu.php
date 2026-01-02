<?php
/**
 * Webhooks Module Menu
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

global $mod_strings, $app_strings;

if (ACLController::checkAccess('Webhooks', 'edit', true)) {
    $module_menu = array();
    $module_menu[] = array(
        'index.php?module=Webhooks&action=index',
        'API Keys',
        'Webhooks'
    );
    $module_menu[] = array(
        'index.php?module=Webhooks&action=docs',
        'API Documentation',
        'Webhooks'
    );
}
