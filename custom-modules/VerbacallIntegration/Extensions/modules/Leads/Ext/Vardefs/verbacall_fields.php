<?php
/**
 * VerbacallIntegration - Custom Fields for Leads
 *
 * Adds Verbacall tracking fields to the Leads module.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

$dictionary['Lead']['fields']['verbacall_signup_c'] = array(
    'name' => 'verbacall_signup_c',
    'vname' => 'LBL_VERBACALL_SIGNUP',
    'type' => 'bool',
    'default' => '0',
    'audited' => true,
    'comment' => 'Has lead signed up for Verbacall',
    'reportable' => true,
    'importable' => 'true',
    'source' => 'custom_fields',
);

$dictionary['Lead']['fields']['verbacall_last_login_c'] = array(
    'name' => 'verbacall_last_login_c',
    'vname' => 'LBL_VERBACALL_LAST_LOGIN',
    'type' => 'datetime',
    'audited' => true,
    'comment' => 'Last Verbacall login timestamp',
    'reportable' => true,
    'importable' => 'true',
    'source' => 'custom_fields',
);

$dictionary['Lead']['fields']['verbacall_minutes_used_c'] = array(
    'name' => 'verbacall_minutes_used_c',
    'vname' => 'LBL_VERBACALL_MINUTES_USED',
    'type' => 'decimal',
    'len' => '10',
    'precision' => '2',
    'default' => '0',
    'audited' => true,
    'comment' => 'Minutes used in Verbacall',
    'reportable' => true,
    'importable' => 'true',
    'source' => 'custom_fields',
);

$dictionary['Lead']['fields']['verbacall_link_sent_c'] = array(
    'name' => 'verbacall_link_sent_c',
    'vname' => 'LBL_VERBACALL_LINK_SENT',
    'type' => 'datetime',
    'audited' => true,
    'comment' => 'When signup link was sent to lead',
    'reportable' => true,
    'importable' => 'true',
    'source' => 'custom_fields',
);
