<?php
/**
 * EmailLinkingService
 * 
 * Service class that auto-links emails to Leads/Contacts by email address matching
 * and logs interactions to the LeadJourney timeline.
 * 
 * This works with SuiteCRM's NATIVE InboundEmail + OAuth system.
 * No custom email fetching module required.
 * 
 * How it works:
 * 1. User configures OAuth connection in Admin → Email → External OAuth Connections
 * 2. User creates Inbound Email account using the OAuth connection
 * 3. SuiteCRM's native scheduler fetches emails via IMAP/OAuth
 * 4. This service hooks into after_save to auto-link and log to LeadJourney
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

class EmailLinkingService
{
    /**
     * Hook called after an Email bean is saved
     * 
     * @param SugarBean $bean The Email bean
     * @param string $event The event name
     * @param array $arguments Additional arguments
     */
    public function afterEmailSave($bean, $event, $arguments)
    {
        // Skip if already linked to a parent
        if (!empty($bean->parent_id) && !empty($bean->parent_type)) {
            // Still log to LeadJourney if it's a Lead or Contact
            if (in_array($bean->parent_type, ['Leads', 'Contacts'])) {
                $this->logToLeadJourney($bean);
            }
            return;
        }

        // Skip if this is an outbound email (we only auto-link inbound)
        if ($bean->type !== 'inbound') {
            return;
        }

        // Try to find matching Lead/Contact
        $match = $this->findMatchingRecord($bean->from_addr);

        if ($match) {
            // Link the email
            $bean->parent_type = $match['type'];
            $bean->parent_id = $match['id'];
            
            // Assign to the Lead/Contact's owner if not already assigned
            if (empty($bean->assigned_user_id) && !empty($match['assigned_user_id'])) {
                $bean->assigned_user_id = $match['assigned_user_id'];
            }

            // Save without re-triggering hooks
            $bean->update_date_modified = false;
            $bean->save(false);

            $GLOBALS['log']->info("EmailLinkingService: Linked email {$bean->id} to {$match['type']} {$match['id']}");

            // Log to LeadJourney
            $this->logToLeadJourney($bean, $match);
        }
    }

    /**
     * Find matching Lead or Contact by email address
     * 
     * Search priority: Leads → Contacts → Accounts (by domain)
     * 
     * @param string $emailAddress Email address to search
     * @return array|null Match info or null
     */
    private function findMatchingRecord($emailAddress)
    {
        if (empty($emailAddress)) {
            return null;
        }

        $db = DBManagerFactory::getInstance();
        $emailSafe = $db->quote(strtolower(trim($emailAddress)));

        // Search Leads first
        $sql = "SELECT l.id, l.first_name, l.last_name, l.assigned_user_id
                FROM leads l
                INNER JOIN email_addr_bean_rel eabr 
                    ON eabr.bean_id = l.id 
                    AND eabr.bean_module = 'Leads' 
                    AND eabr.deleted = 0
                INNER JOIN email_addresses ea 
                    ON ea.id = eabr.email_address_id 
                    AND ea.deleted = 0
                WHERE LOWER(ea.email_address) = '$emailSafe'
                AND l.deleted = 0
                LIMIT 1";

        $result = $db->query($sql);
        if ($row = $db->fetchByAssoc($result)) {
            return [
                'id' => $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'type' => 'Leads',
                'assigned_user_id' => $row['assigned_user_id']
            ];
        }

        // Search Contacts
        $sql = "SELECT c.id, c.first_name, c.last_name, c.assigned_user_id
                FROM contacts c
                INNER JOIN email_addr_bean_rel eabr 
                    ON eabr.bean_id = c.id 
                    AND eabr.bean_module = 'Contacts' 
                    AND eabr.deleted = 0
                INNER JOIN email_addresses ea 
                    ON ea.id = eabr.email_address_id 
                    AND ea.deleted = 0
                WHERE LOWER(ea.email_address) = '$emailSafe'
                AND c.deleted = 0
                LIMIT 1";

        $result = $db->query($sql);
        if ($row = $db->fetchByAssoc($result)) {
            return [
                'id' => $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'type' => 'Contacts',
                'assigned_user_id' => $row['assigned_user_id']
            ];
        }

        return null;
    }

    /**
     * Log email interaction to LeadJourney timeline
     * 
     * @param SugarBean $emailBean The Email bean
     * @param array|null $match Match info (optional, will use bean's parent if not provided)
     */
    private function logToLeadJourney($emailBean, $match = null)
    {
        // Determine parent from match or from bean
        $parentType = $match['type'] ?? $emailBean->parent_type;
        $parentId = $match['id'] ?? $emailBean->parent_id;
        $assignedUserId = $match['assigned_user_id'] ?? $emailBean->assigned_user_id;

        // Only log for Leads and Contacts
        if (!in_array($parentType, ['Leads', 'Contacts'])) {
            return;
        }

        // Check if LeadJourneyLogger exists
        $loggerFile = 'custom/modules/LeadJourney/LeadJourneyLogger.php';
        if (!file_exists($loggerFile)) {
            $loggerFile = 'modules/LeadJourney/LeadJourneyLogger.php';
            if (!file_exists($loggerFile)) {
                $GLOBALS['log']->warn("EmailLinkingService: LeadJourneyLogger not found");
                return;
            }
        }

        require_once($loggerFile);

        // Determine direction
        $direction = ($emailBean->type === 'inbound') ? 'inbound' : 'outbound';

        // Get email body (prefer plain text for logging)
        $body = $emailBean->description;
        if (empty($body) && !empty($emailBean->description_html)) {
            $body = strip_tags($emailBean->description_html);
        }

        LeadJourneyLogger::logEmail([
            'message_id' => $emailBean->message_id ?? '',
            'from' => $emailBean->from_addr ?? '',
            'from_name' => $emailBean->from_addr_name ?? '',
            'to' => $emailBean->to_addrs ?? '',
            'subject' => $emailBean->name ?? '',
            'body' => substr($body, 0, 500),
            'direction' => $direction,
            'date' => $emailBean->date_sent ?? date('Y-m-d H:i:s'),
            'parent_type' => $parentType,
            'parent_id' => $parentId,
            'assigned_user_id' => $assignedUserId
        ]);

        $GLOBALS['log']->info("EmailLinkingService: Logged email to LeadJourney for $parentType $parentId");
    }

    /**
     * Static helper to manually link an email by ID
     * Useful for batch processing or UI actions
     * 
     * @param string $emailId Email bean ID
     * @return array Result with success/message
     */
    public static function linkEmailById($emailId)
    {
        $email = BeanFactory::getBean('Emails', $emailId);
        
        if (!$email || !$email->id) {
            return ['success' => false, 'message' => 'Email not found'];
        }

        $service = new EmailLinkingService();
        $match = $service->findMatchingRecord($email->from_addr);

        if (!$match) {
            return ['success' => false, 'message' => 'No matching Lead/Contact found for ' . $email->from_addr];
        }

        $email->parent_type = $match['type'];
        $email->parent_id = $match['id'];
        $email->save(false);

        $service->logToLeadJourney($email, $match);

        return [
            'success' => true,
            'message' => "Linked to {$match['type']}: {$match['name']}",
            'match' => $match
        ];
    }

    /**
     * Batch link all unlinked inbound emails
     * 
     * @param int $limit Maximum emails to process
     * @return array Summary of processing
     */
    public static function linkUnlinkedEmails($limit = 100)
    {
        $db = DBManagerFactory::getInstance();

        $sql = "SELECT id FROM emails 
                WHERE deleted = 0 
                AND type = 'inbound'
                AND (parent_id IS NULL OR parent_id = '')
                ORDER BY date_entered DESC
                LIMIT " . intval($limit);

        $result = $db->query($sql);
        $linked = 0;
        $failed = 0;

        while ($row = $db->fetchByAssoc($result)) {
            $linkResult = self::linkEmailById($row['id']);
            if ($linkResult['success']) {
                $linked++;
            } else {
                $failed++;
            }
        }

        return [
            'processed' => $linked + $failed,
            'linked' => $linked,
            'not_matched' => $failed
        ];
    }
}
