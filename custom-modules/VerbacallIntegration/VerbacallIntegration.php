<?php
/**
 * VerbacallIntegration Module Bean
 *
 * Provides Verbacall integration for lead signup and payment link generation.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('data/SugarBean.php');

class VerbacallIntegration extends SugarBean
{
    public $module_name = 'VerbacallIntegration';
    public $module_dir = 'modules/VerbacallIntegration';
    public $object_name = 'VerbacallIntegration';
    public $table_name = 'verbacall_integration';
    public $new_schema = true;
    public $disable_row_level_security = true;

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

    /**
     * Get Verbacall configuration
     */
    public static function getConfig()
    {
        global $sugar_config;

        return array(
            'api_url' => rtrim(
                getenv('VERBACALL_API_URL') ?:
                ($sugar_config['verbacall_api_url'] ?? 'https://app.verbacall.com'),
                '/'
            ),
            'default_discount' => floatval(
                getenv('VERBACALL_DEFAULT_DISCOUNT') ?:
                ($sugar_config['verbacall_default_discount'] ?? 10)
            ),
            'expiry_days' => intval(
                getenv('VERBACALL_EXPIRY_DAYS') ?:
                ($sugar_config['verbacall_expiry_days'] ?? 7)
            ),
        );
    }

    /**
     * Check if Verbacall is configured
     */
    public static function isConfigured()
    {
        $config = self::getConfig();
        return !empty($config['api_url']);
    }
}
