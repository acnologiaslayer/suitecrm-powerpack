<?php
if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

$searchdefs['Webhooks'] = array(
    'templateMeta' => array(
        'maxColumns' => '3',
        'widths' => array('label' => '10', 'field' => '30'),
    ),
    'layout' => array(
        'basic_search' => array(
            'name' => array('name' => 'name', 'label' => 'LBL_NAME', 'default' => true),
        ),
        'advanced_search' => array(
            'name' => array('name' => 'name', 'label' => 'LBL_NAME', 'default' => true),
        ),
    ),
);
