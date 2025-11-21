<?php
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

$manifest = array(
    'acceptable_sugar_versions' => array(
        'regex_matches' => array('7\\..*', '8\\..*'),
    ),
    'acceptable_sugar_flavors' => array('CE', 'PRO', 'ENT'),
    'readme' => '',
    'key' => 'twilio_integration',
    'author' => 'SuiteCRM Extended',
    'description' => 'Twilio Integration - Click-to-call, Auto logging, and Call recordings',
    'icon' => '',
    'is_uninstallable' => true,
    'name' => 'Twilio Integration',
    'published_date' => '2025-11-16',
    'type' => 'module',
    'version' => '1.0.0',
    'remove_tables' => 'prompt',
);

$installdefs = array(
    'id' => 'twilio_integration',
    'copy' => array(
        array(
            'from' => '<basepath>/TwilioIntegration.php',
            'to' => 'modules/TwilioIntegration/TwilioIntegration.php',
        ),
        array(
            'from' => '<basepath>/vardefs.php',
            'to' => 'modules/TwilioIntegration/vardefs.php',
        ),
        array(
            'from' => '<basepath>/Menu.php',
            'to' => 'modules/TwilioIntegration/Menu.php',
        ),
        array(
            'from' => '<basepath>/TwilioClient.php',
            'to' => 'modules/TwilioIntegration/TwilioClient.php',
        ),
        array(
            'from' => '<basepath>/TwilioHooks.php',
            'to' => 'modules/TwilioIntegration/TwilioHooks.php',
        ),
        array(
            'from' => '<basepath>/logic_hooks.php',
            'to' => 'modules/TwilioIntegration/logic_hooks.php',
        ),
        array(
            'from' => '<basepath>/views',
            'to' => 'modules/TwilioIntegration/views',
        ),
        array(
            'from' => '<basepath>/language',
            'to' => 'modules/TwilioIntegration/language',
        ),
        array(
            'from' => '<basepath>/Extensions/modules/Contacts/Ext/Vardefs/twilio_js.php',
            'to' => 'custom/Extension/modules/Contacts/Ext/Vardefs/twilio_js.php',
        ),
        array(
            'from' => '<basepath>/Extensions/modules/Leads/Ext/Vardefs/twilio_js.php',
            'to' => 'custom/Extension/modules/Leads/Ext/Vardefs/twilio_js.php',
        ),
    ),
    'beans' => array(
        array(
            'module' => 'TwilioIntegration',
            'class' => 'TwilioIntegration',
            'path' => 'modules/TwilioIntegration/TwilioIntegration.php',
            'tab' => true,
        ),
    ),
    'language' => array(
        array(
            'from' => '<basepath>/language/en_us.lang.php',
            'to_module' => 'TwilioIntegration',
            'language' => 'en_us',
        ),
    ),
);
