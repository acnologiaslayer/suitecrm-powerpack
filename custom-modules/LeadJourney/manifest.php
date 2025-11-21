<?php
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

$manifest = array(
    'acceptable_sugar_versions' => array(
        'regex_matches' => array('7\\..*', '8\\..*'),
    ),
    'acceptable_sugar_flavors' => array('CE', 'PRO', 'ENT'),
    'readme' => '',
    'key' => 'lead_journey_timeline',
    'author' => 'SuiteCRM Extended',
    'description' => 'Lead Journey Timeline - Unified view of all touchpoints including calls, emails, site visits, and LinkedIn clicks',
    'icon' => '',
    'is_uninstallable' => true,
    'name' => 'Lead Journey Timeline',
    'published_date' => '2025-11-16',
    'type' => 'module',
    'version' => '1.0.0',
    'remove_tables' => 'prompt',
);

$installdefs = array(
    'id' => 'lead_journey_timeline',
    'copy' => array(
        array(
            'from' => '<basepath>/LeadJourney.php',
            'to' => 'modules/LeadJourney/LeadJourney.php',
        ),
        array(
            'from' => '<basepath>/vardefs.php',
            'to' => 'modules/LeadJourney/vardefs.php',
        ),
        array(
            'from' => '<basepath>/Menu.php',
            'to' => 'modules/LeadJourney/Menu.php',
        ),
        array(
            'from' => '<basepath>/tracking.js',
            'to' => 'modules/LeadJourney/tracking.js',
        ),
        array(
            'from' => '<basepath>/views',
            'to' => 'modules/LeadJourney/views',
        ),
        array(
            'from' => '<basepath>/language',
            'to' => 'modules/LeadJourney/language',
        ),
        array(
            'from' => '<basepath>/Extensions/modules/Contacts/Ext/Vardefs/journey_button.php',
            'to' => 'custom/Extension/modules/Contacts/Ext/Vardefs/journey_button.php',
        ),
        array(
            'from' => '<basepath>/Extensions/modules/Leads/Ext/Vardefs/journey_button.php',
            'to' => 'custom/Extension/modules/Leads/Ext/Vardefs/journey_button.php',
        ),
    ),
    'beans' => array(
        array(
            'module' => 'LeadJourney',
            'class' => 'LeadJourney',
            'path' => 'modules/LeadJourney/LeadJourney.php',
            'tab' => true,
        ),
    ),
    'language' => array(
        array(
            'from' => '<basepath>/language/en_us.lang.php',
            'to_module' => 'LeadJourney',
            'language' => 'en_us',
        ),
    ),
);
