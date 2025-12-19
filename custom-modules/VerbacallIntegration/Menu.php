<?php
/**
 * VerbacallIntegration Module Menu
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

global $mod_strings, $app_strings, $sugar_config;

if (ACLController::checkAccess('VerbacallIntegration', 'edit', true)) {
    $module_menu[] = array(
        'index.php?module=VerbacallIntegration&action=config',
        'Configuration',
        'Config',
        'VerbacallIntegration'
    );
}
