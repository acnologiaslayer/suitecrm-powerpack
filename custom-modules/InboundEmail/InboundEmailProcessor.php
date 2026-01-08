<?php
/**
 * InboundEmailProcessor - Processes inbound emails with error handling
 * Includes automatic OAuth token refresh
 */

if (!defined("sugarEntry") || !sugarEntry) die("Not A Valid Entry Point");

require_once("modules/InboundEmail/InboundEmail.php");

class InboundEmailProcessor
{
    private $config;
    private $client;
    private $processedCount = 0;
    private $linkedCount = 0;
    private $errorCount = 0;

    public static function processAll()
    {
        global $log;

        // First, refresh any expiring OAuth tokens
        self::refreshOAuthTokens();

        $configs = InboundEmail::getActiveConfigs();
        $results = [];

        foreach ($configs as $config) {
            try {
                // Ensure OAuth token is valid for this config
                if (!empty($config->external_oauth_connection_id)) {
                    self::ensureValidOAuthToken($config->external_oauth_connection_id);
                }

                $processor = new InboundEmailProcessor($config);
                $results[$config->id] = $processor->process();
            } catch (Exception $e) {
                if ($log) $log->error("InboundEmailProcessor: Error processing " . $config->name . " - " . $e->getMessage());
                $results[$config->id] = [
                    "success" => false,
                    "message" => $e->getMessage(),
                    "processed" => 0,
                    "linked" => 0,
                    "errors" => 1
                ];
            }
        }

        return $results;
    }

    /**
     * Refresh all OAuth tokens that are about to expire
     */
    private static function refreshOAuthTokens()
    {
        global $log;

        $refresherPath = "custom/include/OAuth/OAuthTokenRefresher.php";
        if (!file_exists($refresherPath)) {
            if ($log) $log->warn("InboundEmailProcessor: OAuthTokenRefresher not found at " . $refresherPath);
            return;
        }

        require_once($refresherPath);

        try {
            $results = OAuthTokenRefresher::refreshAllExpiring();
            foreach ($results as $id => $result) {
                if ($log) $log->info("InboundEmailProcessor: Token refresh for " . $result["name"] . ": " . ($result["success"] ? "SUCCESS" : "FAILED"));
            }
        } catch (Exception $e) {
            if ($log) $log->error("InboundEmailProcessor: Error refreshing OAuth tokens - " . $e->getMessage());
        }
    }

    /**
     * Ensure a specific OAuth token is valid
     */
    private static function ensureValidOAuthToken($connectionId)
    {
        global $log;

        $refresherPath = "custom/include/OAuth/OAuthTokenRefresher.php";
        if (!file_exists($refresherPath)) {
            return false;
        }

        require_once($refresherPath);

        try {
            return OAuthTokenRefresher::ensureValidToken($connectionId);
        } catch (Exception $e) {
            if ($log) $log->error("InboundEmailProcessor: Error ensuring valid OAuth token - " . $e->getMessage());
            return false;
        }
    }

    public function __construct(InboundEmail $config)
    {
        $this->config = $config;

        // Load InboundEmailClient
        $clientPath = "custom/modules/InboundEmail/InboundEmailClient.php";
        if (!file_exists($clientPath)) {
            $clientPath = "modules/InboundEmail/InboundEmailClient.php";
        }
        require_once($clientPath);

        $this->client = new InboundEmailClient($config);
    }

    public function process()
    {
        global $log;

        if ($log) $log->info("InboundEmailProcessor: Starting processing for " . $this->config->name);

        if (!InboundEmailClient::isAvailable()) {
            return [
                "success" => false,
                "message" => "IMAP not available",
                "processed" => 0,
                "linked" => 0,
                "errors" => 1
            ];
        }

        try {
            $emails = $this->client->fetchNewEmails(50);
        } catch (Exception $e) {
            if ($log) $log->error("InboundEmailProcessor: Failed to fetch emails - " . $e->getMessage());
            return [
                "success" => false,
                "message" => "Fetch failed: " . $e->getMessage(),
                "processed" => 0,
                "linked" => 0,
                "errors" => 1
            ];
        }

        if (empty($emails)) {
            return [
                "success" => true,
                "message" => "No new emails",
                "processed" => 0,
                "linked" => 0,
                "errors" => 0
            ];
        }

        $lastUid = 0;

        foreach ($emails as $email) {
            try {
                $this->processEmail($email);
                $this->processedCount++;
                $lastUid = max($lastUid, $email["uid"]);
            } catch (Exception $e) {
                if ($log) $log->error("InboundEmailProcessor: Error processing email - " . $e->getMessage());
                $this->errorCount++;
            }
        }

        return [
            "success" => true,
            "message" => "Processed " . $this->processedCount . " emails",
            "processed" => $this->processedCount,
            "linked" => $this->linkedCount,
            "errors" => $this->errorCount
        ];
    }

    private function processEmail($emailData)
    {
        global $db;

        // Check for duplicate
        $messageId = $emailData["message_id"] ?? "";
        if ($messageId) {
            $check = $db->query("SELECT id FROM emails WHERE message_id = " . $db->quoted($messageId) . " AND deleted = 0");
            if ($db->fetchByAssoc($check)) {
                return; // Already exists
            }
        }

        // Create email record
        $email = BeanFactory::newBean("Emails");
        $email->name = $emailData["subject"] ?? "(No Subject)";
        $email->date_sent_received = $emailData["date"] ?? gmdate("Y-m-d H:i:s");
        $email->message_id = $messageId;
        $email->from_addr = $emailData["from"] ?? "";
        $email->from_name = $emailData["from_name"] ?? "";
        $email->to_addrs = implode(", ", $emailData["to"] ?? []);
        $email->description = $emailData["body"] ?? "";
        $email->description_html = $emailData["body_html"] ?? "";
        $email->type = "inbound";
        $email->status = "unread";
        $email->intent = "pick";
        $email->mailbox_id = $this->config->id;

        // Assign to the inbound email account owner
        if (!empty($this->config->created_by)) {
            $email->assigned_user_id = $this->config->created_by;
        } elseif (!empty($this->config->group_id)) {
            // For group inboxes, assign to the first user in the group
            $email->team_id = $this->config->group_id;
        }

        $email->save();

        // Store email body in emails_text table (required for search and display)
        $this->storeEmailText($email, $emailData);

        // Link to folder
        $this->linkToFolder($email);

        // Link to leads/contacts by email address
        $this->linkToRecords($email, $emailData);
    }

    /**
     * Store email text content in emails_text table
     * This is required for proper email display and search in SuiteCRM
     */
    private function storeEmailText($email, $emailData)
    {
        global $db;

        // Check if entry already exists
        $check = $db->query("SELECT email_id FROM emails_text WHERE email_id = " . $db->quoted($email->id));
        if ($db->fetchByAssoc($check)) {
            return; // Already exists
        }

        $fromAddr = $emailData["from"] ?? "";
        $toAddrs = implode(", ", $emailData["to"] ?? []);
        $ccAddrs = implode(", ", $emailData["cc"] ?? []);
        $bccAddrs = implode(", ", $emailData["bcc"] ?? []);

        $sql = "INSERT INTO emails_text (email_id, from_addr, to_addrs, cc_addrs, bcc_addrs, description, description_html, raw_source, deleted) VALUES (" .
            $db->quoted($email->id) . ", " .
            $db->quoted($fromAddr) . ", " .
            $db->quoted($toAddrs) . ", " .
            $db->quoted($ccAddrs) . ", " .
            $db->quoted($bccAddrs) . ", " .
            $db->quoted($emailData["body"] ?? "") . ", " .
            $db->quoted($emailData["body_html"] ?? "") . ", " .
            $db->quoted("") . ", 0)";

        $db->query($sql);
    }

    private function linkToFolder($email)
    {
        global $db;

        $folderId = $this->config->id;

        // Check if folder exists
        $check = $db->query("SELECT id FROM folders WHERE id = " . $db->quoted($folderId) . " AND deleted = 0");
        if (!$db->fetchByAssoc($check)) {
            return;
        }

        // Link email to folder
        $db->query("INSERT INTO folders_rel (id, folder_id, polymorphic_module, polymorphic_id, deleted) VALUES (" .
            $db->quoted(create_guid()) . ", " .
            $db->quoted($folderId) . ", " .
            $db->quoted("Emails") . ", " .
            $db->quoted($email->id) . ", 0)"
        );
    }

    private function linkToRecords($email, $emailData)
    {
        global $db;

        $fromAddr = strtolower($emailData["from"] ?? "");
        if (empty($fromAddr)) return;

        // Find matching leads
        $query = "SELECT l.id, l.first_name, l.last_name, l.assigned_user_id FROM leads l
                  JOIN email_addr_bean_rel eabr ON l.id = eabr.bean_id AND eabr.bean_module = " . $db->quoted("Leads") . " AND eabr.deleted = 0
                  JOIN email_addresses ea ON eabr.email_address_id = ea.id AND ea.deleted = 0
                  WHERE l.deleted = 0 AND LOWER(ea.email_address) = " . $db->quoted($fromAddr);

        $result = $db->query($query);
        while ($row = $db->fetchByAssoc($result)) {
            // Check if already linked
            $check = $db->query("SELECT id FROM emails_beans WHERE email_id = " . $db->quoted($email->id) .
                              " AND bean_id = " . $db->quoted($row["id"]) . " AND bean_module = " . $db->quoted("Leads") . " AND deleted = 0");
            if (!$db->fetchByAssoc($check)) {
                $db->query("INSERT INTO emails_beans (id, email_id, bean_id, bean_module, date_modified, deleted) VALUES (" .
                    $db->quoted(create_guid()) . ", " .
                    $db->quoted($email->id) . ", " .
                    $db->quoted($row["id"]) . ", " .
                    $db->quoted("Leads") . ", " .
                    $db->quoted(gmdate("Y-m-d H:i:s")) . ", 0)"
                );
                $this->linkedCount++;

                // Send notification for new email linked to lead
                $this->sendEmailNotification($email, $emailData, $row);
            }
        }
    }

    /**
     * Send notification for incoming email linked to a lead
     *
     * @param object $email Email bean
     * @param array $emailData Raw email data
     * @param array $leadRow Lead data from query
     */
    private function sendEmailNotification($email, $emailData, $leadRow)
    {
        global $log;

        try {
            // Load NotificationService
            $servicePath = "modules/Webhooks/NotificationService.php";
            if (!file_exists($servicePath)) {
                $servicePath = "custom/modules/Webhooks/NotificationService.php";
            }
            if (!file_exists($servicePath)) {
                if ($log) $log->warn("InboundEmailProcessor: NotificationService not found");
                return;
            }

            require_once($servicePath);

            $service = new NotificationService();

            // Build lead name
            $leadName = trim(($leadRow["first_name"] ?? "") . " " . ($leadRow["last_name"] ?? ""));
            if (empty($leadName)) {
                $leadName = "Unknown Lead";
            }

            // Get sender name
            $senderName = $emailData["from_name"] ?? $emailData["from"] ?? "Unknown";

            // Build notification
            $subject = $emailData["subject"] ?? "(No Subject)";
            $preview = strlen($subject) > 60 ? substr($subject, 0, 60) . "..." : $subject;

            $params = [
                "title" => "New email from " . $senderName,
                "message" => $preview,
                "type" => "info",
                "priority" => "normal",
                "target_users" => !empty($leadRow["assigned_user_id"]) ? [$leadRow["assigned_user_id"]] : [],
                "target_module" => "Leads",
                "target_record" => $leadRow["id"],
                "metadata" => [
                    "notification_type" => "inbound_email",
                    "sender_name" => $senderName,
                    "sender_email" => $emailData["from"] ?? "",
                    "lead_id" => $leadRow["id"],
                    "lead_name" => $leadName,
                    "email_id" => $email->id
                ]
            ];

            $result = $service->createNotification($params);

            if ($result["success"]) {
                if ($log) $log->info("InboundEmailProcessor: Sent email notification to " . $result["user_count"] . " users");
            } else {
                if ($log) $log->warn("InboundEmailProcessor: Notification failed - " . ($result["error"] ?? "Unknown error"));
            }
        } catch (Exception $e) {
            if ($log) $log->error("InboundEmailProcessor: Notification error - " . $e->getMessage());
        }
    }
}
