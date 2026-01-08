<?php
if (!defined("sugarEntry") || !sugarEntry) {
    die("Not A Valid Entry Point");
}

// Link emails to leads scheduler
$job_strings[] = "linkEmailsToLeads";

function linkEmailsToLeads()
{
    global $db;

    if (!$db) {
        $db = DBManagerFactory::getInstance();
    }

    $GLOBALS["log"]->info("linkEmailsToLeads: Starting email-to-lead linking job");

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
        AND e.type = 'inbound'
        AND NOT EXISTS (
            SELECT 1 FROM emails_beans eb
            WHERE eb.email_id = e.id
            AND eb.bean_id = l.id
            AND eb.bean_module = 'Leads'
            AND eb.deleted = 0
        )
    ";

    $result = $db->query($insertQuery);
    $linkedCount = $db->getAffectedRowCount($result);

    $GLOBALS["log"]->info("linkEmailsToLeads: Linked $linkedCount emails to leads");

    return true;
}

// Process inbound emails scheduler
$job_strings[] = "processInboundEmails";

function processInboundEmails()
{
    $GLOBALS["log"]->info("processInboundEmails: Starting email sync");

    // Try custom path first, then module path
    $processorFile = "custom/modules/InboundEmail/InboundEmailProcessor.php";
    if (!file_exists($processorFile)) {
        $processorFile = "modules/InboundEmail/InboundEmailProcessor.php";
    }

    if (!file_exists($processorFile)) {
        $GLOBALS["log"]->error("processInboundEmails: InboundEmailProcessor not found");
        return true;
    }

    require_once($processorFile);

    try {
        $results = InboundEmailProcessor::processAll();

        $totalProcessed = 0;
        $totalLinked = 0;

        foreach ($results as $configId => $result) {
            if (isset($result["processed"])) {
                $totalProcessed += $result["processed"];
            }
            if (isset($result["linked"])) {
                $totalLinked += $result["linked"];
            }
        }

        $GLOBALS["log"]->info("processInboundEmails: Completed - Processed: $totalProcessed, Linked: $totalLinked");

    } catch (Exception $e) {
        $GLOBALS["log"]->error("processInboundEmails: Error - " . $e->getMessage());
    }

    return true;
}

// Refresh OAuth tokens scheduler
$job_strings[] = "refreshOAuthTokens";

function refreshOAuthTokens()
{
    global $log;

    if ($log) $log->info("refreshOAuthTokens: Starting OAuth token refresh job");

    $refresherPath = "custom/include/OAuth/OAuthTokenRefresher.php";
    if (!file_exists($refresherPath)) {
        if ($log) $log->error("refreshOAuthTokens: OAuthTokenRefresher not found");
        return true;
    }

    require_once($refresherPath);

    try {
        $results = OAuthTokenRefresher::refreshAllExpiring();

        $successCount = 0;
        $failCount = 0;

        foreach ($results as $id => $result) {
            if ($result["success"]) {
                $successCount++;
            } else {
                $failCount++;
            }
            if ($log) $log->info("refreshOAuthTokens: " . $result["name"] . " - " . ($result["success"] ? "SUCCESS" : "FAILED"));
        }

        if ($log) $log->info("refreshOAuthTokens: Completed - Success: $successCount, Failed: $failCount");

    } catch (Exception $e) {
        if ($log) $log->error("refreshOAuthTokens: Error - " . $e->getMessage());
    }

    return true;
}
