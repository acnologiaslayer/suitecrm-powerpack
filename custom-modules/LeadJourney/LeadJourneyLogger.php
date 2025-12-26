<?php
/**
 * LeadJourneyLogger
 * Unified logging utility for recording all communication activities to the LeadJourney timeline
 * Supports calls, SMS, emails, and custom touchpoints
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

class LeadJourneyLogger
{
    /**
     * Log an inbound or outbound call to the LeadJourney timeline
     *
     * @param array $data Call data including:
     *   - call_sid: Twilio Call SID
     *   - from: Caller phone number
     *   - to: Recipient phone number
     *   - direction: 'inbound' or 'outbound'
     *   - status: Call status (completed, no-answer, busy, failed)
     *   - duration: Call duration in seconds
     *   - recording_url: URL or path to recording (optional)
     *   - recording_sid: Twilio Recording SID (optional)
     *   - document_id: CRM Document ID for recording (optional)
     *   - parent_type: Parent module type (Leads, Contacts)
     *   - parent_id: Parent record ID
     *   - assigned_user_id: User ID
     * @return string|null Journey entry ID or null on failure
     */
    public static function logCall($data)
    {
        if (empty($data['parent_type']) || empty($data['parent_id'])) {
            $GLOBALS['log']->warn("LeadJourneyLogger::logCall - Missing parent_type or parent_id");
            return null;
        }

        $direction = $data['direction'] ?? 'outbound';
        $status = $data['status'] ?? 'unknown';
        $duration = intval($data['duration'] ?? 0);
        $from = $data['from'] ?? '';
        $to = $data['to'] ?? '';

        // Build name
        $dirLabel = ($direction === 'inbound') ? 'Inbound' : 'Outbound';
        $statusLabel = ucfirst(str_replace('-', ' ', $status));
        $name = "$dirLabel Call - $statusLabel";

        if ($duration > 0) {
            $minutes = floor($duration / 60);
            $seconds = $duration % 60;
            $name .= " ({$minutes}m {$seconds}s)";
        }

        // Build description
        $description = "$dirLabel call\n";
        $description .= "From: $from\n";
        $description .= "To: $to\n";
        $description .= "Status: $statusLabel\n";
        $description .= "Duration: " . gmdate('H:i:s', $duration) . "\n";

        if (!empty($data['call_sid'])) {
            $description .= "Call SID: {$data['call_sid']}\n";
        }

        // Build touchpoint data
        $touchpointData = [
            'call_sid' => $data['call_sid'] ?? '',
            'from' => $from,
            'to' => $to,
            'direction' => $direction,
            'status' => $status,
            'duration' => $duration
        ];

        // Add recording info if available
        if (!empty($data['recording_url'])) {
            $touchpointData['recording_url'] = $data['recording_url'];
            $description .= "\nRecording available";
        }

        if (!empty($data['recording_sid'])) {
            $touchpointData['recording_sid'] = $data['recording_sid'];
        }

        if (!empty($data['document_id'])) {
            $touchpointData['document_id'] = $data['document_id'];
        }

        return self::createJourneyEntry([
            'parent_type' => $data['parent_type'],
            'parent_id' => $data['parent_id'],
            'name' => $name,
            'description' => $description,
            'touchpoint_type' => 'call',
            'touchpoint_date' => $data['date'] ?? gmdate('Y-m-d H:i:s'),
            'touchpoint_data' => json_encode($touchpointData),
            'source' => 'twilio',
            'assigned_user_id' => $data['assigned_user_id'] ?? '',
            'recording_url' => $data['recording_url'] ?? null
        ]);
    }

    /**
     * Log an SMS message to the LeadJourney timeline
     *
     * @param array $data SMS data including:
     *   - message_sid: Twilio Message SID
     *   - from: Sender phone number
     *   - to: Recipient phone number
     *   - body: Message content
     *   - direction: 'inbound' or 'outbound'
     *   - status: Message status
     *   - media_urls: Array of media URLs (optional)
     *   - parent_type: Parent module type
     *   - parent_id: Parent record ID
     *   - assigned_user_id: User ID
     * @return string|null Journey entry ID or null on failure
     */
    public static function logSMS($data)
    {
        if (empty($data['parent_type']) || empty($data['parent_id'])) {
            $GLOBALS['log']->warn("LeadJourneyLogger::logSMS - Missing parent_type or parent_id");
            return null;
        }

        $direction = $data['direction'] ?? 'outbound';
        $body = $data['body'] ?? '';
        $from = $data['from'] ?? '';
        $to = $data['to'] ?? '';

        // Build name
        $dirLabel = ($direction === 'inbound') ? 'Received' : 'Sent';
        $preview = strlen($body) > 50 ? substr($body, 0, 47) . '...' : $body;
        $name = "$dirLabel SMS: $preview";

        // Build description
        $description = ($direction === 'inbound') ? "Inbound SMS\n" : "Outbound SMS\n";
        $description .= "From: $from\n";
        $description .= "To: $to\n";
        $description .= "Time: " . ($data['date'] ?? date('Y-m-d H:i:s')) . "\n\n";
        $description .= "Message:\n$body";

        if (!empty($data['media_urls'])) {
            $description .= "\n\n[Contains " . count($data['media_urls']) . " media attachment(s)]";
        }

        // Build touchpoint data
        $touchpointData = [
            'message_sid' => $data['message_sid'] ?? '',
            'from' => $from,
            'to' => $to,
            'body' => $body,
            'direction' => $direction,
            'status' => $data['status'] ?? ''
        ];

        if (!empty($data['media_urls'])) {
            $touchpointData['media_urls'] = $data['media_urls'];
        }

        return self::createJourneyEntry([
            'parent_type' => $data['parent_type'],
            'parent_id' => $data['parent_id'],
            'name' => $name,
            'description' => $description,
            'touchpoint_type' => 'sms',
            'touchpoint_date' => $data['date'] ?? gmdate('Y-m-d H:i:s'),
            'touchpoint_data' => json_encode($touchpointData),
            'source' => 'twilio',
            'assigned_user_id' => $data['assigned_user_id'] ?? ''
        ]);
    }

    /**
     * Log an email to the LeadJourney timeline
     *
     * @param array $data Email data including:
     *   - subject: Email subject
     *   - from: Sender email
     *   - to: Recipient email
     *   - body: Email body (text or HTML)
     *   - direction: 'inbound' or 'outbound'
     *   - message_id: Email Message-ID header (for threading)
     *   - in_reply_to: In-Reply-To header (for threading)
     *   - parent_type: Parent module type
     *   - parent_id: Parent record ID
     *   - assigned_user_id: User ID
     * @return string|null Journey entry ID or null on failure
     */
    public static function logEmail($data)
    {
        if (empty($data['parent_type']) || empty($data['parent_id'])) {
            $GLOBALS['log']->warn("LeadJourneyLogger::logEmail - Missing parent_type or parent_id");
            return null;
        }

        $direction = $data['direction'] ?? 'outbound';
        $subject = $data['subject'] ?? '(No Subject)';
        $from = $data['from'] ?? '';
        $to = $data['to'] ?? '';

        // Build name
        $dirLabel = ($direction === 'inbound') ? 'Received' : 'Sent';
        $name = "$dirLabel Email: $subject";

        // Build description (plain text excerpt)
        $bodyText = strip_tags($data['body'] ?? '');
        $bodyPreview = strlen($bodyText) > 300 ? substr($bodyText, 0, 297) . '...' : $bodyText;

        $description = ($direction === 'inbound') ? "Inbound Email\n" : "Outbound Email\n";
        $description .= "From: $from\n";
        $description .= "To: $to\n";
        $description .= "Subject: $subject\n";
        $description .= "Time: " . ($data['date'] ?? date('Y-m-d H:i:s')) . "\n\n";
        $description .= "Preview:\n$bodyPreview";

        // Build touchpoint data
        $touchpointData = [
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'direction' => $direction,
            'message_id' => $data['message_id'] ?? '',
            'in_reply_to' => $data['in_reply_to'] ?? ''
        ];

        return self::createJourneyEntry([
            'parent_type' => $data['parent_type'],
            'parent_id' => $data['parent_id'],
            'name' => $name,
            'description' => $description,
            'touchpoint_type' => 'email',
            'touchpoint_date' => $data['date'] ?? gmdate('Y-m-d H:i:s'),
            'touchpoint_data' => json_encode($touchpointData),
            'source' => $data['source'] ?? 'email',
            'assigned_user_id' => $data['assigned_user_id'] ?? ''
        ]);
    }

    /**
     * Log a voicemail to the LeadJourney timeline
     *
     * @param array $data Voicemail data
     * @return string|null Journey entry ID or null on failure
     */
    public static function logVoicemail($data)
    {
        if (empty($data['parent_type']) || empty($data['parent_id'])) {
            return null;
        }

        $from = $data['from'] ?? '';
        $duration = intval($data['duration'] ?? 0);
        $transcription = $data['transcription'] ?? '';

        $name = "Voicemail from $from";
        if ($duration > 0) {
            $minutes = floor($duration / 60);
            $seconds = $duration % 60;
            $name .= " ({$minutes}m {$seconds}s)";
        }

        $description = "Voicemail Received\n";
        $description .= "From: $from\n";
        $description .= "Duration: " . gmdate('H:i:s', $duration) . "\n";

        if (!empty($transcription)) {
            $description .= "\nTranscription:\n$transcription";
        }

        $touchpointData = [
            'from' => $from,
            'duration' => $duration,
            'transcription' => $transcription,
            'recording_sid' => $data['recording_sid'] ?? '',
            'recording_url' => $data['recording_url'] ?? ''
        ];

        return self::createJourneyEntry([
            'parent_type' => $data['parent_type'],
            'parent_id' => $data['parent_id'],
            'name' => $name,
            'description' => $description,
            'touchpoint_type' => 'voicemail',
            'touchpoint_date' => $data['date'] ?? gmdate('Y-m-d H:i:s'),
            'touchpoint_data' => json_encode($touchpointData),
            'source' => 'twilio',
            'assigned_user_id' => $data['assigned_user_id'] ?? '',
            'recording_url' => $data['recording_url'] ?? null
        ]);
    }

    /**
     * Update an existing journey entry with recording information
     *
     * @param string $journeyId Journey entry ID
     * @param string $recordingUrl Recording URL or path
     * @param string $recordingSid Twilio Recording SID (optional)
     * @param string $documentId CRM Document ID (optional)
     * @return bool Success
     */
    public static function updateWithRecording($journeyId, $recordingUrl, $recordingSid = '', $documentId = '')
    {
        if (empty($journeyId)) {
            return false;
        }

        $db = DBManagerFactory::getInstance();
        $journeyIdSafe = $db->quote($journeyId);

        // Get current entry
        $sql = "SELECT touchpoint_data FROM lead_journey WHERE id = '$journeyIdSafe' AND deleted = 0";
        $result = $db->query($sql);
        $row = $db->fetchByAssoc($result);

        if (!$row) {
            return false;
        }

        // Update touchpoint data
        $touchpointData = json_decode($row['touchpoint_data'], true) ?: [];
        $touchpointData['recording_url'] = $recordingUrl;

        if (!empty($recordingSid)) {
            $touchpointData['recording_sid'] = $recordingSid;
        }

        if (!empty($documentId)) {
            $touchpointData['document_id'] = $documentId;
        }

        $touchpointDataSafe = $db->quote(json_encode($touchpointData));
        $recordingUrlSafe = $db->quote($recordingUrl);

        // Check if recording_url column exists
        $columns = $db->get_columns('lead_journey');
        $hasRecordingUrlColumn = isset($columns['recording_url']);

        if ($hasRecordingUrlColumn) {
            $sql = "UPDATE lead_journey
                    SET touchpoint_data = '$touchpointDataSafe',
                        recording_url = '$recordingUrlSafe',
                        date_modified = NOW()
                    WHERE id = '$journeyIdSafe'";
        } else {
            $sql = "UPDATE lead_journey
                    SET touchpoint_data = '$touchpointDataSafe',
                        date_modified = NOW()
                    WHERE id = '$journeyIdSafe'";
        }

        $db->query($sql);

        $GLOBALS['log']->info("LeadJourneyLogger: Updated journey $journeyId with recording");
        return true;
    }

    /**
     * Create a journey entry in the database
     *
     * @param array $data Entry data
     * @return string|null Entry ID or null on failure
     */
    private static function createJourneyEntry($data)
    {
        $db = DBManagerFactory::getInstance();

        $id = create_guid();
        $now = gmdate('Y-m-d H:i:s');

        $name = $db->quote($data['name'] ?? '');
        $description = $db->quote($data['description'] ?? '');
        $parentType = $db->quote($data['parent_type']);
        $parentId = $db->quote($data['parent_id']);
        $touchpointType = $db->quote($data['touchpoint_type'] ?? 'other');
        $touchpointDate = $db->quote($data['touchpoint_date'] ?? $now);
        $touchpointData = $db->quote($data['touchpoint_data'] ?? '{}');
        $source = $db->quote($data['source'] ?? '');
        $assignedUserId = $db->quote($data['assigned_user_id'] ?? '');
        $userId = isset($GLOBALS['current_user']) ? $db->quote($GLOBALS['current_user']->id) : "''";

        // Check if recording_url column exists
        $columns = $db->get_columns('lead_journey');
        $hasRecordingUrlColumn = isset($columns['recording_url']);

        $recordingUrlValue = '';
        $recordingUrlColumn = '';
        if ($hasRecordingUrlColumn && !empty($data['recording_url'])) {
            $recordingUrlColumn = ', recording_url';
            $recordingUrlValue = ", '" . $db->quote($data['recording_url']) . "'";
        }

        $sql = "INSERT INTO lead_journey (
                    id, name, description, date_entered, date_modified,
                    modified_user_id, created_by, deleted, parent_type, parent_id,
                    touchpoint_type, touchpoint_date, touchpoint_data, source,
                    assigned_user_id{$recordingUrlColumn}
                ) VALUES (
                    '$id', '$name', '$description', '$now', '$now',
                    $userId, $userId, 0, '$parentType', '$parentId',
                    '$touchpointType', '$touchpointDate', '$touchpointData', '$source',
                    '$assignedUserId'{$recordingUrlValue}
                )";

        $db->query($sql);

        $GLOBALS['log']->info("LeadJourneyLogger: Created journey entry $id for {$data['parent_type']} {$data['parent_id']}");

        return $id;
    }

    /**
     * Find existing journey entry by call SID
     *
     * @param string $callSid Twilio Call SID
     * @return string|null Journey entry ID or null if not found
     */
    public static function findByCallSid($callSid)
    {
        if (empty($callSid)) {
            return null;
        }

        $db = DBManagerFactory::getInstance();
        $callSidSafe = $db->quote($callSid);

        $sql = "SELECT id FROM lead_journey
                WHERE touchpoint_data LIKE '%\"call_sid\":\"$callSidSafe\"%'
                AND deleted = 0
                ORDER BY date_entered DESC
                LIMIT 1";

        $result = $db->query($sql);
        if ($row = $db->fetchByAssoc($result)) {
            return $row['id'];
        }

        return null;
    }

    /**
     * Find existing journey entry by message SID
     *
     * @param string $messageSid Twilio Message SID
     * @return string|null Journey entry ID or null if not found
     */
    public static function findByMessageSid($messageSid)
    {
        if (empty($messageSid)) {
            return null;
        }

        $db = DBManagerFactory::getInstance();
        $messageSidSafe = $db->quote($messageSid);

        $sql = "SELECT id FROM lead_journey
                WHERE touchpoint_data LIKE '%\"message_sid\":\"$messageSidSafe\"%'
                AND deleted = 0
                ORDER BY date_entered DESC
                LIMIT 1";

        $result = $db->query($sql);
        if ($row = $db->fetchByAssoc($result)) {
            return $row['id'];
        }

        return null;
    }
}
