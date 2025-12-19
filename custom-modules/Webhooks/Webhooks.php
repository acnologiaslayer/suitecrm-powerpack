<?php
/**
 * Webhooks Module Bean
 *
 * This module provides notification webhook endpoints and services.
 * It doesn't store records but provides the webhook API and notification services.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('data/SugarBean.php');

class Webhooks extends SugarBean
{
    public $module_name = 'Webhooks';
    public $module_dir = 'modules/Webhooks';
    public $object_name = 'Webhooks';
    public $table_name = 'webhooks';
    public $new_schema = true;
    public $disable_row_level_security = true;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Bean implements ACL
     */
    public function bean_implements($interface)
    {
        switch ($interface) {
            case 'ACL':
                return true;
        }
        return false;
    }
}
