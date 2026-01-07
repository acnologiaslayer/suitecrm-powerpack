<?php
/**
 * InboundEmail DetailView metadata
 * This file provides a basic detail view for the InboundEmail module
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

$module_name = 'InboundEmail';

$viewdefs[$module_name]['DetailView'] = array(
    'templateMeta' => array(
        'form' => array(
            'buttons' => array(
                'EDIT',
                'DELETE',
            ),
        ),
        'maxColumns' => '2',
        'widths' => array(
            array('label' => '10', 'field' => '30'),
            array('label' => '10', 'field' => '30'),
        ),
    ),
    'panels' => array(
        'default' => array(
            array(
                'name',
                'status',
            ),
            array(
                'server_url',
                'protocol',
            ),
            array(
                'email_user',
                'mailbox',
            ),
            array(
                'is_personal',
                'deleted',
            ),
        ),
    ),
);
