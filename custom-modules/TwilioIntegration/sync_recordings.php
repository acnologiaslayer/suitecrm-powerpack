<?php
/**
 * Twilio Recordings Sync Script
 * Fetches recent calls from Twilio and syncs them to LeadJourney with recordings
 * Run via: php sync_recordings.php [days_back]
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from CLI");
}

// Bootstrap SuiteCRM
chdir(dirname(__FILE__) . '/../../');
define('sugarEntry', true);
require_once('include/entryPoint.php');
require_once('modules/LeadJourney/LeadJourneyLogger.php');

global $sugar_config, $db;

$daysBack = isset($argv[1]) ? intval($argv[1]) : 7;
$startDate = date('Y-m-d', strtotime("-{$daysBack} days"));

echo "=== Twilio Recording Sync ===\n";
echo "Syncing calls from: $startDate\n\n";

$accountSid = $sugar_config['twilio_account_sid'] ?? '';
$authToken = $sugar_config['twilio_auth_token'] ?? '';

if (empty($accountSid) || empty($authToken)) {
    die("Error: Twilio credentials not configured\n");
}

// Create recordings directory
$recordingsDir = 'upload/twilio_recordings';
if (!is_dir($recordingsDir)) {
    mkdir($recordingsDir, 0755, true);
    echo "Created recordings directory: $recordingsDir\n";
}

// Fetch recent calls from Twilio
$url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls.json";
$url .= "?StartTime>=" . urlencode($startDate);
$url .= "&PageSize=100";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => "{$accountSid}:{$authToken}",
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("Error fetching calls: HTTP $httpCode\n");
}

$data = json_decode($response, true);
$calls = $data['calls'] ?? [];

echo "Found " . count($calls) . " calls\n\n";

$synced = 0;
$skipped = 0;
$recordingsDownloaded = 0;

foreach ($calls as $call) {
    $callSid = $call['sid'];
    $from = $call['from'];
    $to = $call['to'];
    $direction = $call['direction'];
    $status = $call['status'];
    $duration = intval($call['duration']);
    $startTime = $call['start_time'];

    echo "Processing: $callSid ($direction: $from -> $to)\n";

    // Skip very short calls
    if ($duration < 3 && $status !== 'completed') {
        echo "  Skipping: Too short or incomplete\n";
        $skipped++;
        continue;
    }

    // Determine the external number (not our Twilio number)
    $twilioNumber = $sugar_config['twilio_phone_number'] ?? '';
    $externalNumber = $from;

    if (strpos($direction, 'outbound') !== false) {
        // For outbound calls, the 'to' is the external number
        $externalNumber = $to;
        // Handle client: format
        if (strpos($to, 'client:') === 0) {
            echo "  Skipping: Client endpoint call\n";
            $skipped++;
            continue;
        }
    }

    // Clean phone number for search
    $cleanNumber = preg_replace('/[^0-9]/', '', $externalNumber);
    if (strlen($cleanNumber) > 10) {
        $cleanNumber = substr($cleanNumber, -10);
    }

    // Skip if phone number is too short (must be at least 7 digits)
    if (strlen($cleanNumber) < 7) {
        echo "  Skipping: Invalid phone number (too short)\n";
        $skipped++;
        continue;
    }

    // Find ALL matching leads by phone (no LIMIT - get all duplicates)
    $sql = "SELECT id, first_name, last_name
            FROM leads
            WHERE deleted = 0
            AND (
                REPLACE(REPLACE(REPLACE(phone_mobile, '-', ''), ' ', ''), '+', '') LIKE '%$cleanNumber%'
                OR REPLACE(REPLACE(REPLACE(phone_home, '-', ''), ' ', ''), '+', '') LIKE '%$cleanNumber%'
                OR REPLACE(REPLACE(REPLACE(phone_work, '-', ''), ' ', ''), '+', '') LIKE '%$cleanNumber%'
            )";

    $result = $db->query($sql);

    // Collect all matching leads
    $matchingLeads = [];
    while ($lead = $db->fetchByAssoc($result)) {
        $matchingLeads[] = $lead;
    }

    if (empty($matchingLeads)) {
        echo "  No matching lead found for: $externalNumber\n";
        $skipped++;
        continue;
    }

    // Safeguard: Skip if too many matches (likely a data issue)
    if (count($matchingLeads) > 10) {
        echo "  Skipping: Too many matches (" . count($matchingLeads) . ") - likely data issue\n";
        $skipped++;
        continue;
    }

    echo "  Found " . count($matchingLeads) . " matching lead(s)\n";

    // Check for recording (only download once, shared across all leads)
    $recordingUrl = null;
    $recordingSid = null;

    $recUrl = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls/{$callSid}/Recordings.json";
    $ch = curl_init($recUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => "{$accountSid}:{$authToken}",
        CURLOPT_TIMEOUT => 30
    ]);
    $recResponse = curl_exec($ch);
    curl_close($ch);

    $recData = json_decode($recResponse, true);

    if (!empty($recData['recordings'])) {
        $recording = $recData['recordings'][0];
        $recordingSid = $recording['sid'];

        echo "  Found recording: $recordingSid (duration: {$recording['duration']}s)\n";

        // Download recording (use timestamp + call SID for unique filename)
        $filename = "recording_" . date('Y-m-d_His', strtotime($startTime)) . "_{$recordingSid}.mp3";
        $filepath = $recordingsDir . '/' . $filename;

        // Only download if not already downloaded
        if (!file_exists($filepath)) {
            $mp3Url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Recordings/{$recordingSid}.mp3";
            $ch = curl_init($mp3Url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => "{$accountSid}:{$authToken}",
                CURLOPT_TIMEOUT => 300,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            $mp3Data = curl_exec($ch);
            $mp3Code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($mp3Code === 200 && !empty($mp3Data)) {
                if (file_put_contents($filepath, $mp3Data)) {
                    echo "  Downloaded recording: $filename (" . strlen($mp3Data) . " bytes)\n";
                    $recordingsDownloaded++;
                }
            }
        } else {
            echo "  Recording already exists: $filename\n";
        }

        $recordingUrl = "index.php?module=TwilioIntegration&action=recording&file=" . urlencode($filename);
    }

    // Create LeadJourney entry for EACH matching lead
    $callDirection = (strpos($direction, 'outbound') !== false) ? 'outbound' : 'inbound';

    foreach ($matchingLeads as $lead) {
        $leadName = trim("{$lead['first_name']} {$lead['last_name']}");

        // Check if already synced for this specific lead
        $existing = LeadJourneyLogger::findByCallSidAndParent($callSid, 'Leads', $lead['id']);
        if ($existing) {
            echo "    Skipping {$leadName}: Already synced\n";
            continue;
        }

        // Determine caller/recipient names based on direction
        $callerName = '';
        $recipientName = '';
        if ($callDirection === 'inbound') {
            $callerName = $leadName;  // Lead is calling us
        } else {
            $recipientName = $leadName;  // We are calling the lead
        }

        $journeyId = LeadJourneyLogger::logCall([
            'call_sid' => $callSid,
            'from' => $from,
            'to' => $to,
            'caller_name' => $callerName,
            'recipient_name' => $recipientName,
            'direction' => $callDirection,
            'status' => $status,
            'duration' => $duration,
            'recording_url' => $recordingUrl,
            'recording_sid' => $recordingSid,
            'parent_type' => 'Leads',
            'parent_id' => $lead['id'],
            'date' => date('Y-m-d H:i:s', strtotime($startTime))
        ]);

        if ($journeyId) {
            echo "    Created journey entry for {$leadName}: $journeyId\n";
            $synced++;
        }
    }

    echo "\n";
}

echo "=== Sync Complete ===\n";
echo "Synced: $synced\n";
echo "Skipped: $skipped\n";
echo "Recordings downloaded: $recordingsDownloaded\n";
