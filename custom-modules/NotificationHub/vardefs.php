<?php
/**
 * NotificationHub - Vardefs
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

$dictionary['NotificationHub'] = [
    'table' => 'notification_api_keys',
    'audited' => false,
    'duplicate_merge' => false,
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
            'type' => 'name',
            'len' => 100,
            'required' => true,
        ],
        'api_key' => [
            'name' => 'api_key',
            'vname' => 'LBL_API_KEY',
            'type' => 'varchar',
            'len' => 255,
            'required' => true,
        ],
        'description' => [
            'name' => 'description',
            'vname' => 'LBL_DESCRIPTION',
            'type' => 'text',
        ],
        'created_by' => [
            'name' => 'created_by',
            'vname' => 'LBL_CREATED_BY',
            'type' => 'id',
        ],
        'created_at' => [
            'name' => 'created_at',
            'vname' => 'LBL_CREATED_AT',
            'type' => 'datetime',
        ],
        'last_used_at' => [
            'name' => 'last_used_at',
            'vname' => 'LBL_LAST_USED_AT',
            'type' => 'datetime',
        ],
        'is_active' => [
            'name' => 'is_active',
            'vname' => 'LBL_IS_ACTIVE',
            'type' => 'bool',
            'default' => 1,
        ],
        'deleted' => [
            'name' => 'deleted',
            'vname' => 'LBL_DELETED',
            'type' => 'bool',
            'default' => 0,
        ],
    ],
    'indices' => [
        [
            'name' => 'notification_api_keys_pk',
            'type' => 'primary',
            'fields' => ['id'],
        ],
        [
            'name' => 'idx_api_key',
            'type' => 'index',
            'fields' => ['api_key'],
        ],
    ],
];
