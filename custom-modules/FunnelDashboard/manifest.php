<?php
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

$manifest = array(
    'acceptable_sugar_versions' => array(
        'regex_matches' => array('7\\..*', '8\\..*'),
    ),
    'acceptable_sugar_flavors' => array('CE', 'PRO', 'ENT'),
    'readme' => '',
    'key' => 'funnel_dashboard',
    'author' => 'SuiteCRM Extended',
    'description' => 'Funnel Dashboards - Segmented by category with stage-tracking and analytics',
    'icon' => '',
    'is_uninstallable' => true,
    'name' => 'Funnel Dashboard',
    'published_date' => '2025-11-16',
    'type' => 'module',
    'version' => '1.0.0',
    'remove_tables' => 'prompt',
);

$installdefs = array(
    'id' => 'funnel_dashboard',
    'copy' => array(
        array(
            'from' => '<basepath>/FunnelDashboard.php',
            'to' => 'modules/FunnelDashboard/FunnelDashboard.php',
        ),
        array(
            'from' => '<basepath>/vardefs.php',
            'to' => 'modules/FunnelDashboard/vardefs.php',
        ),
        array(
            'from' => '<basepath>/Menu.php',
            'to' => 'modules/FunnelDashboard/Menu.php',
        ),
        array(
            'from' => '<basepath>/views',
            'to' => 'modules/FunnelDashboard/views',
        ),
        array(
            'from' => '<basepath>/language',
            'to' => 'modules/FunnelDashboard/language',
        ),
    ),
    'beans' => array(
        array(
            'module' => 'FunnelDashboard',
            'class' => 'FunnelDashboard',
            'path' => 'modules/FunnelDashboard/FunnelDashboard.php',
            'tab' => true,
        ),
    ),
    'language' => array(
        array(
            'from' => '<basepath>/language/en_us.lang.php',
            'to_module' => 'FunnelDashboard',
            'language' => 'en_us',
        ),
    ),
);
