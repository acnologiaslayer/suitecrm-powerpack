<?php
if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

$listViewDefs['NotificationHub'] = array(
    'NAME' => array(
        'width' => '40%',
        'label' => 'LBL_NAME',
        'default' => true,
        'link' => true,
    ),
    'DATE_ENTERED' => array(
        'width' => '20%',
        'label' => 'LBL_DATE_ENTERED',
        'default' => true,
    ),
    'DATE_MODIFIED' => array(
        'width' => '20%',
        'label' => 'LBL_DATE_MODIFIED',
        'default' => true,
    ),
);
