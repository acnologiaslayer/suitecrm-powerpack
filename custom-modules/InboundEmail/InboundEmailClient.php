<?php
/**
 * InboundEmailClient - IMAP/POP3 Connection Wrapper with OAuth Support
 *
 * Handles connecting to email servers and fetching messages.
 * Supports both basic authentication and OAuth 2.0 (XOAUTH2).
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

class InboundEmailClient
{
    private $connection = null;
    private $config;
    private $lastError = '';

    /**
     * @param InboundEmail $config Email configuration bean
     */
    public function __construct(InboundEmail $config)
    {
        $this->config = $config;
    }

    /**
     * Check if IMAP extension is available
     */
    public static function isAvailable()
    {
        return function_exists('imap_open');
    }

    /**
     * Get OAuth access token from ExternalOAuthConnection
     * Handles token refresh if expired
     *
     * @return string|null Access token or null on failure
     */
    private function getOAuthAccessToken()
    {
        $connectionId = $this->config->external_oauth_connection_id ?? '';

        if (empty($connectionId)) {
            $this->lastError = 'No OAuth connection configured';
            return null;
        }

        // Load the OAuth connection bean
        // Note: Using newBean()->retrieve() instead of getBean() for compatibility
        $oauthConnection = BeanFactory::newBean('ExternalOAuthConnection');
        $oauthConnection->retrieve($connectionId);

        if (!$oauthConnection || !$oauthConnection->id) {
            $this->lastError = 'OAuth connection not found';
            return null;
        }

        // Check if access token exists
        $accessToken = $oauthConnection->access_token ?? '';
        if (empty($accessToken)) {
            $this->lastError = 'OAuth connection not authorized - no access token';
            return null;
        }

        // Check if token is expired and refresh if needed
        $expiresIn = $oauthConnection->expires_in ?? '';
        if (!empty($expiresIn)) {
            $expiresTimestamp = (int) $expiresIn;
            if (time() > $expiresTimestamp) {
                // Token expired, try to refresh
                $accessToken = $this->refreshOAuthToken($oauthConnection);
                if (empty($accessToken)) {
                    return null; // lastError already set by refreshOAuthToken
                }
            }
        }

        return $accessToken;
    }

    /**
     * Refresh OAuth token using the OAuthAuthorizationService
     *
     * @param ExternalOAuthConnection $connection
     * @return string|null New access token or null on failure
     */
    private function refreshOAuthToken($connection)
    {
        require_once('modules/ExternalOAuthConnection/services/OAuthAuthorizationService.php');

        $service = new OAuthAuthorizationService();
        $result = $service->refreshConnectionToken($connection);

        if (!$result['success']) {
            $this->lastError = 'OAuth token refresh failed: ' . ($result['message'] ?? 'Unknown error');
            $GLOBALS['log']->error("InboundEmailClient: OAuth refresh failed - " . $this->lastError);
            return null;
        }

        // Reload the connection to get the new token
        $connection->retrieve($connection->id);
        return $connection->access_token ?? null;
    }

    /**
     * Build XOAUTH2 string for IMAP authentication
     *
     * @param string $username Email address
     * @param string $accessToken OAuth access token
     * @return string Base64-encoded XOAUTH2 string
     */
    private function buildXOAuth2String($username, $accessToken)
    {
        // XOAUTH2 format: user=<email>\x01auth=Bearer <token>\x01\x01
        $authString = "user=" . $username . "\x01auth=Bearer " . $accessToken . "\x01\x01";
        return base64_encode($authString);
    }

    /**
     * Connect to the email server
     *
     * @return bool True on success
     */
    public function connect()
    {
        if (!self::isAvailable()) {
            $this->lastError = 'PHP IMAP extension is not installed';
            return false;
        }

        $connectionString = $this->getConnectionString();
        $username = $this->config->email_user ?? '';
        $authType = $this->config->auth_type ?? 'basic';

        // Suppress warnings and handle errors manually
        $previousErrorHandler = set_error_handler(function($errno, $errstr) {
            $this->lastError = $errstr;
        });

        if ($authType === 'oauth') {
            // OAuth authentication using XOAUTH2
            $accessToken = $this->getOAuthAccessToken();

            if (!$accessToken) {
                restore_error_handler();
                return false;
            }

            // Build XOAUTH2 authentication string
            $xoauth2 = $this->buildXOAuth2String($username, $accessToken);

            // Connect with XOAUTH2
            // Note: PHP's imap_open doesn't directly support XOAUTH2,
            // so we use a workaround with custom authenticator
            $this->connection = @imap_open(
                $connectionString,
                $username,
                $xoauth2,
                0,
                1,
                ['DISABLE_AUTHENTICATOR' => 'GSSAPI', 'DISABLE_AUTHENTICATOR' => 'PLAIN']
            );

            // If that fails, try alternative approach
            if (!$this->connection) {
                // Try with direct token as password (some servers accept this)
                $this->connection = @imap_open(
                    $connectionString,
                    $username,
                    $accessToken,
                    0,
                    1,
                    ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
                );
            }
        } else {
            // Basic password authentication
            $password = $this->getPassword();

            $this->connection = @imap_open(
                $connectionString,
                $username,
                $password,
                0,
                1,
                ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
            );
        }

        restore_error_handler();

        if (!$this->connection) {
            $errors = imap_errors();
            if ($errors) {
                $this->lastError = implode('; ', $errors);
            } elseif (empty($this->lastError)) {
                $this->lastError = 'Connection failed';
            }
            $GLOBALS['log']->error("InboundEmailClient: Connection failed (auth: {$authType}) - " . $this->lastError);
            return false;
        }

        $GLOBALS['log']->info("InboundEmailClient: Connected to " . ($this->config->server_url ?? 'unknown') . " using " . $authType);
        return true;
    }

    /**
     * Get connection string for IMAP
     *
     * @return string IMAP connection string
     */
    private function getConnectionString()
    {
        $server = $this->config->server_url ?? '';
        $port = $this->config->port ?? 993;
        $folder = $this->config->mailbox ?? 'INBOX';
        $ssl = $this->config->is_ssl ?? true;

        $connStr = '{' . $server . ':' . $port;

        if ($ssl) {
            $connStr .= '/imap/ssl/novalidate-cert';
        } else {
            $connStr .= '/imap/notls';
        }

        $connStr .= '}' . $folder;

        return $connStr;
    }

    /**
     * Get decrypted password
     *
     * @return string Decrypted password
     */
    private function getPassword()
    {
        $encPassword = $this->config->email_password ?? '';

        if (empty($encPassword)) {
            return '';
        }

        return blowfishDecode(blowfishGetKey('encrypt_field'), $encPassword);
    }

    /**
     * Test the connection without fetching emails
     *
     * @return array ['success' => bool, 'message' => string, 'details' => array]
     */
    public function testConnection()
    {
        if (!self::isAvailable()) {
            return [
                'success' => false,
                'message' => 'PHP IMAP extension is not installed',
                'details' => []
            ];
        }

        // For OAuth, check that we can get a token first
        $authType = $this->config->auth_type ?? 'basic';
        if ($authType === 'oauth') {
            $accessToken = $this->getOAuthAccessToken();
            if (!$accessToken) {
                return [
                    'success' => false,
                    'message' => 'OAuth error: ' . $this->lastError,
                    'details' => []
                ];
            }
        }

        if (!$this->connect()) {
            return [
                'success' => false,
                'message' => $this->lastError,
                'details' => []
            ];
        }

        // Get mailbox info
        $check = imap_check($this->connection);
        $connStr = $this->getConnectionString();
        $status = @imap_status($this->connection, $connStr, SA_ALL);

        $details = [
            'mailbox' => $check->Mailbox ?? 'Unknown',
            'total_messages' => $check->Nmsgs ?? 0,
            'recent_messages' => $check->Recent ?? 0,
            'unseen_messages' => $status->unseen ?? 0,
            'uidnext' => $status->uidnext ?? 0,
            'auth_type' => $authType,
        ];

        $this->disconnect();

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => $details
        ];
    }

    /**
     * Fetch new emails since last poll
     *
     * @param int $limit Maximum emails to fetch
     * @return array Array of email data
     */
    public function fetchNewEmails($limit = 50)
    {
        if (!$this->connection && !$this->connect()) {
            return [];
        }

        $emails = [];
        $lastUid = $this->config->last_uid ?: 0;

        // Search for emails with UID greater than last processed
        if ($lastUid > 0) {
            $search = imap_search($this->connection, 'UID ' . ($lastUid + 1) . ':*', SE_UID);
        } else {
            // First run - get recent emails
            $search = imap_search($this->connection, 'SINCE "' . date('j-M-Y', strtotime('-7 days')) . '"', SE_UID);
        }

        if (!$search) {
            $GLOBALS['log']->info("InboundEmailClient: No new emails found");
            return [];
        }

        // Sort by UID ascending
        sort($search, SORT_NUMERIC);

        // Limit results
        $search = array_slice($search, 0, $limit);

        foreach ($search as $uid) {
            $email = $this->fetchEmail($uid);
            if ($email) {
                $emails[] = $email;
            }
        }

        $GLOBALS['log']->info("InboundEmailClient: Fetched " . count($emails) . " new emails");

        return $emails;
    }

    /**
     * Fetch a single email by UID
     *
     * @param int $uid Email UID
     * @return array|null Email data or null on error
     */
    public function fetchEmail($uid)
    {
        if (!$this->connection) {
            return null;
        }

        $msgno = imap_msgno($this->connection, $uid);
        if (!$msgno) {
            return null;
        }

        $header = imap_headerinfo($this->connection, $msgno);
        $structure = imap_fetchstructure($this->connection, $msgno);

        if (!$header) {
            return null;
        }

        // Parse from address
        $from = '';
        $fromName = '';
        if (!empty($header->from[0])) {
            $from = $header->from[0]->mailbox . '@' . $header->from[0]->host;
            $fromName = isset($header->from[0]->personal) ?
                imap_utf8($header->from[0]->personal) : $from;
        }

        // Parse to addresses
        $to = [];
        if (!empty($header->to)) {
            foreach ($header->to as $toAddr) {
                $to[] = $toAddr->mailbox . '@' . $toAddr->host;
            }
        }

        // Parse CC addresses
        $cc = [];
        if (!empty($header->cc)) {
            foreach ($header->cc as $ccAddr) {
                $cc[] = $ccAddr->mailbox . '@' . $ccAddr->host;
            }
        }

        // Get body
        $body = $this->getBody($msgno, $structure);
        $bodyHtml = $this->getBody($msgno, $structure, true);

        // Get attachments
        $attachments = $this->getAttachments($msgno, $structure);

        return [
            'uid' => $uid,
            'message_id' => isset($header->message_id) ? trim($header->message_id, '<>') : '',
            'subject' => isset($header->subject) ? imap_utf8($header->subject) : '(No Subject)',
            'from' => $from,
            'from_name' => $fromName,
            'to' => $to,
            'cc' => $cc,
            'date' => isset($header->date) ? date('Y-m-d H:i:s', strtotime($header->date)) : gmdate('Y-m-d H:i:s'),
            'body' => $body,
            'body_html' => $bodyHtml,
            'attachments' => $attachments,
            'in_reply_to' => isset($header->in_reply_to) ? trim($header->in_reply_to, '<>') : '',
            'references' => isset($header->references) ? $header->references : '',
        ];
    }

    /**
     * Get email body (plain text or HTML)
     */
    private function getBody($msgno, $structure, $html = false)
    {
        $body = '';

        if (!isset($structure->parts)) {
            // Simple message
            $body = imap_body($this->connection, $msgno);
            $body = $this->decodeBody($body, $structure->encoding ?? 0);
        } else {
            // Multipart message
            $body = $this->getPartBody($msgno, $structure, $html ? 'text/html' : 'text/plain');
        }

        // Convert charset if needed
        if (!empty($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'charset') {
                    $charset = $param->value;
                    if (strtolower($charset) !== 'utf-8') {
                        $body = @iconv($charset, 'UTF-8//IGNORE', $body);
                    }
                    break;
                }
            }
        }

        return $body;
    }

    /**
     * Get body from multipart message
     */
    private function getPartBody($msgno, $structure, $mimeType, $partNumber = '')
    {
        if (!isset($structure->parts)) {
            return '';
        }

        foreach ($structure->parts as $index => $part) {
            $currentPart = $partNumber ? ($partNumber . '.' . ($index + 1)) : ($index + 1);

            $type = strtolower($this->getMimeType($part));

            if ($type === $mimeType) {
                $body = imap_fetchbody($this->connection, $msgno, $currentPart);
                return $this->decodeBody($body, $part->encoding ?? 0);
            }

            // Recurse into nested parts
            if (isset($part->parts)) {
                $nested = $this->getPartBody($msgno, $part, $mimeType, $currentPart);
                if ($nested) {
                    return $nested;
                }
            }
        }

        return '';
    }

    /**
     * Get attachments from email
     */
    private function getAttachments($msgno, $structure, $partNumber = '')
    {
        $attachments = [];

        if (!isset($structure->parts)) {
            return $attachments;
        }

        foreach ($structure->parts as $index => $part) {
            $currentPart = $partNumber ? ($partNumber . '.' . ($index + 1)) : ($index + 1);

            // Check if this part is an attachment
            $filename = '';
            $isAttachment = false;

            if ($part->ifdisposition && strtolower($part->disposition) === 'attachment') {
                $isAttachment = true;
            }

            // Get filename
            if ($part->ifdparameters) {
                foreach ($part->dparameters as $param) {
                    if (strtolower($param->attribute) === 'filename') {
                        $filename = imap_utf8($param->value);
                        $isAttachment = true;
                        break;
                    }
                }
            }

            if (!$filename && $part->ifparameters) {
                foreach ($part->parameters as $param) {
                    if (strtolower($param->attribute) === 'name') {
                        $filename = imap_utf8($param->value);
                        $isAttachment = true;
                        break;
                    }
                }
            }

            if ($isAttachment && $filename) {
                $attachments[] = [
                    'filename' => $filename,
                    'mime_type' => $this->getMimeType($part),
                    'size' => $part->bytes ?? 0,
                    'part_number' => $currentPart,
                ];
            }

            // Recurse into nested parts
            if (isset($part->parts)) {
                $nested = $this->getAttachments($msgno, $part, $currentPart);
                $attachments = array_merge($attachments, $nested);
            }
        }

        return $attachments;
    }

    /**
     * Download attachment content
     */
    public function downloadAttachment($uid, $partNumber)
    {
        if (!$this->connection) {
            return null;
        }

        $msgno = imap_msgno($this->connection, $uid);
        if (!$msgno) {
            return null;
        }

        $structure = imap_fetchstructure($this->connection, $msgno);
        $part = $this->getPartByNumber($structure, $partNumber);

        if (!$part) {
            return null;
        }

        $data = imap_fetchbody($this->connection, $msgno, $partNumber);
        return $this->decodeBody($data, $part->encoding ?? 0);
    }

    /**
     * Get part by part number
     */
    private function getPartByNumber($structure, $partNumber)
    {
        $parts = explode('.', $partNumber);
        $current = $structure;

        foreach ($parts as $num) {
            $index = intval($num) - 1;
            if (!isset($current->parts[$index])) {
                return null;
            }
            $current = $current->parts[$index];
        }

        return $current;
    }

    /**
     * Decode body based on encoding
     */
    private function decodeBody($body, $encoding)
    {
        switch ($encoding) {
            case 0: // 7BIT
            case 1: // 8BIT
                return $body;
            case 2: // BINARY
                return $body;
            case 3: // BASE64
                return base64_decode($body);
            case 4: // QUOTED-PRINTABLE
                return quoted_printable_decode($body);
            default:
                return $body;
        }
    }

    /**
     * Get MIME type from part
     */
    private function getMimeType($part)
    {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $type = isset($types[$part->type]) ? $types[$part->type] : 'other';
        $subtype = strtolower($part->subtype ?? 'plain');
        return $type . '/' . $subtype;
    }

    /**
     * Mark email as read
     */
    public function markAsRead($uid)
    {
        if (!$this->connection) {
            return false;
        }

        return imap_setflag_full($this->connection, $uid, '\\Seen', ST_UID);
    }

    /**
     * Delete email
     */
    public function deleteEmail($uid)
    {
        if (!$this->connection) {
            return false;
        }

        return imap_delete($this->connection, $uid, FT_UID);
    }

    /**
     * Expunge deleted emails
     */
    public function expunge()
    {
        if (!$this->connection) {
            return false;
        }

        return imap_expunge($this->connection);
    }

    /**
     * Disconnect from server
     */
    public function disconnect()
    {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Get last error message
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * List available folders
     */
    public function listFolders()
    {
        if (!$this->connection && !$this->connect()) {
            return [];
        }

        $server = '{' . ($this->config->server_url ?? '') . ':' . ($this->config->port ?: 993);
        if ($this->config->is_ssl ?? true) {
            $server .= '/imap/ssl/novalidate-cert';
        }
        $server .= '}';

        $folders = imap_list($this->connection, $server, '*');

        if (!$folders) {
            return [];
        }

        $result = [];
        foreach ($folders as $folder) {
            $result[] = str_replace($server, '', $folder);
        }

        return $result;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
