<?php
/**
 * InboundEmail vardefs - Field definitions for core inbound_email table with OAuth support
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

$dictionary['InboundEmail'] = [
    'table' => 'inbound_email',
    'audited' => false,
    'fields' => [
        'id' => [
            'name' => 'id',
            'vname' => 'LBL_ID',
            'type' => 'id',
            'required' => true,
        ],
        'name' => [
            'name' => 'name',
            'vname' => 'LBL_NAME',
            'type' => 'varchar',
            'len' => 255,
        ],
        'date_entered' => [
            'name' => 'date_entered',
            'vname' => 'LBL_DATE_ENTERED',
            'type' => 'datetime',
        ],
        'date_modified' => [
            'name' => 'date_modified',
            'vname' => 'LBL_DATE_MODIFIED',
            'type' => 'datetime',
        ],
        'modified_user_id' => [
            'name' => 'modified_user_id',
            'type' => 'id',
        ],
        'created_by' => [
            'name' => 'created_by',
            'type' => 'id',
        ],
        'deleted' => [
            'name' => 'deleted',
            'type' => 'bool',
            'default' => 0,
        ],
        'status' => [
            'name' => 'status',
            'vname' => 'LBL_STATUS',
            'type' => 'varchar',
            'len' => 100,
            'default' => 'Active',
        ],
        'server_url' => [
            'name' => 'server_url',
            'vname' => 'LBL_SERVER',
            'type' => 'varchar',
            'len' => 100,
        ],
        'email_user' => [
            'name' => 'email_user',
            'vname' => 'LBL_EMAIL_USER',
            'type' => 'varchar',
            'len' => 100,
        ],
        'email_password' => [
            'name' => 'email_password',
            'vname' => 'LBL_PASSWORD',
            'type' => 'varchar',
            'len' => 100,
        ],
        'port' => [
            'name' => 'port',
            'vname' => 'LBL_PORT',
            'type' => 'int',
            'default' => 993,
        ],
        'protocol' => [
            'name' => 'protocol',
            'vname' => 'LBL_PROTOCOL',
            'type' => 'varchar',
            'len' => 255,
            'default' => 'imap',
        ],
        'mailbox' => [
            'name' => 'mailbox',
            'vname' => 'LBL_MAILBOX',
            'type' => 'text',
        ],
        'is_ssl' => [
            'name' => 'is_ssl',
            'vname' => 'LBL_SSL',
            'type' => 'bool',
            'default' => 0,
        ],
        'mailbox_type' => [
            'name' => 'mailbox_type',
            'type' => 'varchar',
            'len' => 10,
        ],
        'is_personal' => [
            'name' => 'is_personal',
            'type' => 'bool',
            'default' => 0,
        ],
        'delete_seen' => [
            'name' => 'delete_seen',
            'type' => 'bool',
            'default' => 0,
        ],
        'auth_type' => [
            'name' => 'auth_type',
            'vname' => 'LBL_AUTH_TYPE',
            'type' => 'varchar',
            'len' => 255,
            'default' => 'basic',
        ],
        'external_oauth_connection_id' => [
            'name' => 'external_oauth_connection_id',
            'vname' => 'LBL_OAUTH_CONNECTION',
            'type' => 'id',
        ],
        'group_id' => [
            'name' => 'group_id',
            'type' => 'id',
        ],
        'stored_options' => [
            'name' => 'stored_options',
            'type' => 'text',
        ],
    ],
    'indices' => [
        [
            'name' => 'inbound_emailpk',
            'type' => 'primary',
            'fields' => ['id'],
        ],
    ],
];
