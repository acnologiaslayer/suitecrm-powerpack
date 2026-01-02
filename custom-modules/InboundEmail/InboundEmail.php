<?php
/**
 * InboundEmail - Module for managing inbound email configurations
 *
 * Handles IMAP/POP3 email account settings for fetching inbound emails
 * and linking them to Leads/Contacts in the CRM.
 *
 * Uses the core SuiteCRM inbound_email table with OAuth support.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('data/SugarBean.php');

class InboundEmail extends SugarBean
{
    public $module_dir = 'InboundEmail';
    public $object_name = 'InboundEmail';
    public $table_name = 'inbound_email';
    public $new_schema = true;
    public $importable = false;

    // Fields - matching core inbound_email table
    public $id;
    public $name;
    public $date_entered;
    public $date_modified;
    public $modified_user_id;
    public $created_by;
    public $deleted;

    // Email server config - core field names
    public $server_url;
    public $port;
    public $protocol;
    public $email_user;
    public $email_password;
    public $is_ssl;
    public $mailbox;
    public $status;
    public $mailbox_type;
    public $is_personal;
    public $delete_seen;

    // OAuth fields
    public $auth_type;
    public $external_oauth_connection_id;

    // Additional fields
    public $group_id;
    public $stored_options;
    public $delete_after_import = false;
    public $last_poll_time;
    public $last_uid;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Override bean_implements to enable ACL
     */
    public function bean_implements($interface)
    {
        switch ($interface) {
            case 'ACL':
                return true;
            default:
                return false;
        }
    }

    /**
     * Get decrypted password
     */
    public function getPassword()
    {
        if (empty($this->email_password)) {
            return '';
        }

        return blowfishDecode(blowfishGetKey('encrypt_field'), $this->email_password);
    }

    /**
     * Set encrypted password
     */
    public function setPassword($password)
    {
        if (empty($password)) {
            $this->email_password = '';
        } else {
            $this->email_password = blowfishEncode(blowfishGetKey('encrypt_field'), $password);
        }
    }

    /**
     * Get all active email configurations
     */
    public static function getActiveConfigs()
    {
        $db = DBManagerFactory::getInstance();
        $configs = [];

        $sql = "SELECT id FROM inbound_email WHERE status = 'Active' AND deleted = 0";
        $result = $db->query($sql);

        while ($row = $db->fetchByAssoc($result)) {
            $config = BeanFactory::getBean('InboundEmail', $row['id']);
            if ($config) {
                $configs[] = $config;
            }
        }

        return $configs;
    }

    /**
     * Get IMAP connection string
     */
    public function getConnectionString()
    {
        $server = $this->server_url;
        $port = $this->port ?: ($this->is_ssl ? 993 : 143);
        $protocol = strtolower($this->protocol ?: 'imap');

        $flags = '/' . $protocol;
        if ($this->is_ssl) {
            $flags .= '/ssl/novalidate-cert';
        }

        return '{' . $server . ':' . $port . $flags . '}' . ($this->mailbox ?: 'INBOX');
    }

    /**
     * Set status and optional message
     *
     * @param string $status Status value (Active, Inactive, error)
     * @param string|null $message Optional status message
     */
    public function setStatus($status, $message = null)
    {
        $this->status = $status;

        // Store message in stored_options if provided
        if ($message !== null) {
            $options = $this->getStoredOptions();
            $options['status_message'] = $message;
            $options['status_time'] = date('Y-m-d H:i:s');
            $this->setStoredOptions($options);
        }

        $this->save();
    }

    /**
     * Update last poll time and optionally last UID
     *
     * @param int|null $lastUid Last processed UID
     */
    public function updateLastPoll($lastUid = null)
    {
        $options = $this->getStoredOptions();
        $options['last_poll_time'] = date('Y-m-d H:i:s');

        if ($lastUid !== null) {
            $options['last_uid'] = $lastUid;
        }

        $this->setStoredOptions($options);
        $this->save();
    }

    /**
     * Get stored options as array
     *
     * @return array
     */
    public function getStoredOptions()
    {
        if (empty($this->stored_options)) {
            return [];
        }

        $decoded = json_decode($this->stored_options, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try base64 decode (SuiteCRM sometimes uses this)
            $decoded = unserialize(base64_decode($this->stored_options));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set stored options from array
     *
     * @param array $options
     */
    public function setStoredOptions($options)
    {
        $this->stored_options = json_encode($options);
    }

    /**
     * Get last UID processed
     *
     * @return int
     */
    public function getLastUid()
    {
        $options = $this->getStoredOptions();
        return $options['last_uid'] ?? 0;
    }
}
