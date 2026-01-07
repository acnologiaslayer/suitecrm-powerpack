<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

class EmailToLeadLinker
{
    /**
     * Link emails to leads based on matching email addresses
     */
    public static function linkEmailsToLeads($maxEmails = 100)
    {
        global $db;

        if (!$db) {
            $db = DBManagerFactory::getInstance();
        }

        $linked = 0;

        // Find emails that are not yet linked to any lead
        $query = "
            SELECT DISTINCT e.id as email_id
            FROM emails e
            WHERE e.deleted = 0
            AND NOT EXISTS (
                SELECT 1 FROM emails_beans eb
                WHERE eb.email_id = e.id
                AND eb.bean_module = 'Leads'
                AND eb.deleted = 0
            )
            ORDER BY e.date_entered DESC
            LIMIT " . (int)$maxEmails;

        $result = $db->query($query);

        while ($row = $db->fetchByAssoc($result)) {
            $emailId = $row['email_id'];

            // Find leads that match email addresses in this email
            $leadQuery = "
                SELECT DISTINCT l.id as lead_id
                FROM leads l
                JOIN email_addr_bean_rel eabr ON l.id = eabr.bean_id
                    AND eabr.bean_module = 'Leads'
                    AND eabr.deleted = 0
                JOIN email_addresses ea ON eabr.email_address_id = ea.id
                    AND ea.deleted = 0
                JOIN emails_email_addr_rel ear ON ea.id = ear.email_address_id
                    AND ear.deleted = 0
                WHERE ear.email_id = " . $db->quoted($emailId) . "
                AND l.deleted = 0
            ";

            $leadResult = $db->query($leadQuery);

            while ($leadRow = $db->fetchByAssoc($leadResult)) {
                $leadId = $leadRow['lead_id'];

                // Check if link already exists
                $checkQuery = "
                    SELECT id FROM emails_beans
                    WHERE email_id = " . $db->quoted($emailId) . "
                    AND bean_id = " . $db->quoted($leadId) . "
                    AND bean_module = 'Leads'
                ";
                $checkResult = $db->query($checkQuery);

                if (!$db->fetchByAssoc($checkResult)) {
                    // Create the link
                    $linkId = create_guid();
                    $insertQuery = "
                        INSERT INTO emails_beans (id, email_id, bean_id, bean_module, date_modified, deleted)
                        VALUES (
                            " . $db->quoted($linkId) . ",
                            " . $db->quoted($emailId) . ",
                            " . $db->quoted($leadId) . ",
                            'Leads',
                            NOW(),
                            0
                        )
                    ";
                    $db->query($insertQuery);

                    // Also update the email parent fields for primary relationship
                    $updateQuery = "
                        UPDATE emails
                        SET parent_type = 'Leads', parent_id = " . $db->quoted($leadId) . "
                        WHERE id = " . $db->quoted($emailId) . "
                        AND (parent_id IS NULL OR parent_id = '')
                    ";
                    $db->query($updateQuery);

                    $linked++;
                    $GLOBALS['log']->info("EmailToLeadLinker: Linked email $emailId to lead $leadId");
                }
            }
        }

        return $linked;
    }

    /**
     * Hook for after email save
     */
    public static function afterSave($bean, $event, $arguments)
    {
        self::linkSingleEmail($bean->id);
    }

    /**
     * Link a single email to matching leads
     */
    public static function linkSingleEmail($emailId)
    {
        global $db;

        if (!$db) {
            $db = DBManagerFactory::getInstance();
        }

        $leadQuery = "
            SELECT DISTINCT l.id as lead_id
            FROM leads l
            JOIN email_addr_bean_rel eabr ON l.id = eabr.bean_id
                AND eabr.bean_module = 'Leads'
                AND eabr.deleted = 0
            JOIN email_addresses ea ON eabr.email_address_id = ea.id
                AND ea.deleted = 0
            JOIN emails_email_addr_rel ear ON ea.id = ear.email_address_id
                AND ear.deleted = 0
            WHERE ear.email_id = " . $db->quoted($emailId) . "
            AND l.deleted = 0
        ";

        $leadResult = $db->query($leadQuery);
        $linked = 0;

        while ($leadRow = $db->fetchByAssoc($leadResult)) {
            $leadId = $leadRow['lead_id'];

            $checkQuery = "
                SELECT id FROM emails_beans
                WHERE email_id = " . $db->quoted($emailId) . "
                AND bean_id = " . $db->quoted($leadId) . "
                AND bean_module = 'Leads'
            ";
            $checkResult = $db->query($checkQuery);

            if (!$db->fetchByAssoc($checkResult)) {
                $linkId = create_guid();
                $insertQuery = "
                    INSERT INTO emails_beans (id, email_id, bean_id, bean_module, date_modified, deleted)
                    VALUES (
                        " . $db->quoted($linkId) . ",
                        " . $db->quoted($emailId) . ",
                        " . $db->quoted($leadId) . ",
                        'Leads',
                        NOW(),
                        0
                    )
                ";
                $db->query($insertQuery);

                $updateQuery = "
                    UPDATE emails
                    SET parent_type = 'Leads', parent_id = " . $db->quoted($leadId) . "
                    WHERE id = " . $db->quoted($emailId) . "
                    AND (parent_id IS NULL OR parent_id = '')
                ";
                $db->query($updateQuery);

                $linked++;
            }
        }

        return $linked;
    }
}
