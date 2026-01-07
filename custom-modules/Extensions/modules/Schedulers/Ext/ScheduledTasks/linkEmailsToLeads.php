<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

function linkEmailsToLeads()
{
    global $db;

    if (!$db) {
        $db = DBManagerFactory::getInstance();
    }

    $GLOBALS['log']->info('linkEmailsToLeads: Starting email-to-lead linking job');

    $insertQuery = "
        INSERT INTO emails_beans (id, email_id, bean_id, bean_module, date_modified, deleted)
        SELECT
            UUID(),
            e.id,
            l.id,
            'Leads',
            NOW(),
            0
        FROM emails e
        JOIN emails_email_addr_rel ear ON e.id = ear.email_id AND ear.deleted = 0
        JOIN email_addresses ea ON ear.email_address_id = ea.id AND ea.deleted = 0
        JOIN email_addr_bean_rel eabr ON ea.id = eabr.email_address_id AND eabr.bean_module = 'Leads' AND eabr.deleted = 0
        JOIN leads l ON eabr.bean_id = l.id AND l.deleted = 0
        WHERE e.deleted = 0
        AND NOT EXISTS (
            SELECT 1 FROM emails_beans eb
            WHERE eb.email_id = e.id
            AND eb.bean_id = l.id
            AND eb.bean_module = 'Leads'
        )
        LIMIT 100
    ";

    $db->query($insertQuery);

    $updateQuery = "
        UPDATE emails e
        JOIN emails_beans eb ON e.id = eb.email_id AND eb.bean_module = 'Leads' AND eb.deleted = 0
        SET e.parent_type = 'Leads', e.parent_id = eb.bean_id
        WHERE (e.parent_id IS NULL OR e.parent_id = '')
    ";

    $db->query($updateQuery);

    $GLOBALS['log']->info('linkEmailsToLeads: Completed email-to-lead linking job');

    return true;
}

$job_strings[] = 'linkEmailsToLeads';
