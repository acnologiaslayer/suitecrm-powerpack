<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

class InboundEmailFolderHook
{
    /**
     * Automatically create folder entry when InboundEmail is saved
     */
    public function afterSave($bean, $event, $arguments)
    {
        global $db;

        if (!$db) {
            $db = DBManagerFactory::getInstance();
        }

        $inboundEmailId = $bean->id;
        $folderName = $bean->name;

        // Check if folder already exists
        $checkQuery = "SELECT id FROM folders WHERE id = " . $db->quoted($inboundEmailId);
        $result = $db->query($checkQuery);

        if (!$db->fetchByAssoc($result)) {
            // Create folder with same ID as inbound email
            $insertQuery = "
                INSERT INTO folders (id, name, folder_type, parent_folder, has_child, is_group, is_dynamic, assign_to_id, created_by, modified_by, deleted)
                VALUES (
                    " . $db->quoted($inboundEmailId) . ",
                    " . $db->quoted($folderName) . ",
                    'inbound',
                    NULL,
                    0,
                    1,
                    0,
                    '1',
                    '1',
                    '1',
                    0
                )
            ";
            $db->query($insertQuery);

            // Auto-subscribe all admin users
            $adminQuery = "SELECT id FROM users WHERE deleted = 0 AND is_admin = 1";
            $adminResult = $db->query($adminQuery);

            while ($admin = $db->fetchByAssoc($adminResult)) {
                $subId = create_guid();
                $subQuery = "
                    INSERT INTO folders_subscriptions (id, folder_id, assigned_user_id)
                    VALUES (
                        " . $db->quoted($subId) . ",
                        " . $db->quoted($inboundEmailId) . ",
                        " . $db->quoted($admin['id']) . "
                    )
                ";
                $db->query($subQuery);
            }

            $GLOBALS['log']->info("InboundEmailFolderHook: Created folder for inbound email: $folderName ($inboundEmailId)");
        } else {
            // Update folder name if changed
            $updateQuery = "UPDATE folders SET name = " . $db->quoted($folderName) . " WHERE id = " . $db->quoted($inboundEmailId);
            $db->query($updateQuery);
        }
    }

    /**
     * Also create folder entries for emails when they are synced
     */
    public static function linkEmailToFolder($emailBean)
    {
        global $db;

        if (!$db) {
            $db = DBManagerFactory::getInstance();
        }

        $mailboxId = $emailBean->mailbox_id;
        if (empty($mailboxId)) {
            return;
        }

        // Check if email is already linked to folder
        $checkQuery = "SELECT id FROM folders_rel WHERE polymorphic_id = " . $db->quoted($emailBean->id) . " AND polymorphic_module = 'Emails'";
        $result = $db->query($checkQuery);

        if (!$db->fetchByAssoc($result)) {
            // Link email to folder
            $relId = create_guid();
            $insertQuery = "
                INSERT INTO folders_rel (id, folder_id, polymorphic_module, polymorphic_id, deleted)
                VALUES (
                    " . $db->quoted($relId) . ",
                    " . $db->quoted($mailboxId) . ",
                    'Emails',
                    " . $db->quoted($emailBean->id) . ",
                    0
                )
            ";
            $db->query($insertQuery);
        }

        // Update intent to imported if still pick
        if ($emailBean->intent == 'pick') {
            $updateQuery = "UPDATE emails SET intent = 'imported', category_id = 'inbound' WHERE id = " . $db->quoted($emailBean->id);
            $db->query($updateQuery);
        }
    }
}
