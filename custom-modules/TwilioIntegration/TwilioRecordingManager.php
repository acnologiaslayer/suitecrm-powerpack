<?php
/**
 * Twilio Recording Manager
 * Handles downloading and storing call recordings
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('modules/LeadJourney/LeadJourneyLogger.php');

class TwilioRecordingManager
{
    private $storagePath;

    public function __construct()
    {
        global $sugar_config;

        // Default storage path
        $this->storagePath = $sugar_config['twilio_recording_path'] ?? 'upload://twilio_recordings';

        // Ensure directory exists
        $this->ensureStorageDirectory();
    }

    /**
     * Download recording from Twilio and store locally
     *
     * @param string $recordingSid Recording SID
     * @param string $callSid Call SID for reference
     * @param string $callId CRM Call record ID
     * @return array|false Success info or false on failure
     */
    public function downloadRecording($recordingSid, $callSid, $callId = null)
    {
        if (empty($recordingSid)) {
            $GLOBALS['log']->error("TwilioRecordingManager: No recording SID provided");
            return false;
        }

        try {
            $config = TwilioIntegration::getConfig();

            if (empty($config['account_sid']) || empty($config['auth_token'])) {
                $GLOBALS['log']->error("TwilioRecordingManager: Twilio credentials not configured");
                return false;
            }

            // Get recording metadata first
            $metadata = $this->getRecordingMetadata($recordingSid, $config);

            if (!$metadata) {
                $GLOBALS['log']->error("TwilioRecordingManager: Could not fetch recording metadata for $recordingSid");
                return false;
            }

            // Download the actual recording file (MP3 format)
            $recordingUrl = "https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Recordings/{$recordingSid}.mp3";

            $ch = curl_init($recordingUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $config['account_sid'] . ':' . $config['auth_token'],
                CURLOPT_TIMEOUT => 300,
                CURLOPT_FOLLOWLOCATION => true
            ]);

            $recordingData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error || $httpCode !== 200) {
                $GLOBALS['log']->error("TwilioRecordingManager: Failed to download recording - HTTP $httpCode: $error");
                return false;
            }

            // Generate filename
            $filename = $this->generateFilename($recordingSid, $callSid);
            $filepath = $this->getStoragePath() . '/' . $filename;

            // Save to disk
            $saved = file_put_contents($filepath, $recordingData);

            if (!$saved) {
                $GLOBALS['log']->error("TwilioRecordingManager: Failed to save recording to $filepath");
                return false;
            }

            $GLOBALS['log']->info("TwilioRecordingManager: Recording $recordingSid saved to $filepath");

            // Create Document record in CRM
            $documentId = $this->createDocumentRecord($filename, $filepath, $recordingSid, $callSid, $callId, $metadata);

            // Attach to Call record if call ID provided
            if ($callId && $documentId) {
                $this->attachToCallRecord($callId, $documentId, $filepath);
            }

            return [
                'success' => true,
                'recording_sid' => $recordingSid,
                'filename' => $filename,
                'filepath' => $filepath,
                'document_id' => $documentId,
                'duration' => $metadata['duration'] ?? 0,
                'size' => strlen($recordingData)
            ];

        } catch (Exception $e) {
            $GLOBALS['log']->error("TwilioRecordingManager: Exception - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get recording metadata from Twilio API
     */
    private function getRecordingMetadata($recordingSid, $config)
    {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Recordings/{$recordingSid}.json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $config['account_sid'] . ':' . $config['auth_token'],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }

        return null;
    }

    /**
     * Generate filename for recording
     */
    private function generateFilename($recordingSid, $callSid)
    {
        $date = date('Y-m-d');
        $time = date('His');
        return "recording_{$date}_{$time}_{$callSid}_{$recordingSid}.mp3";
    }

    /**
     * Get storage path
     */
    private function getStoragePath()
    {
        global $sugar_config;

        $path = $sugar_config['twilio_recording_path'] ?? 'upload/twilio_recordings';

        // Handle upload:// protocol
        if (strpos($path, 'upload://') === 0) {
            $path = str_replace('upload://', 'upload/', $path);
        }

        return $path;
    }

    /**
     * Ensure storage directory exists
     */
    private function ensureStorageDirectory()
    {
        $path = $this->getStoragePath();

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
            $GLOBALS['log']->info("TwilioRecordingManager: Created storage directory: $path");
        }

        // Create .htaccess to protect recordings
        $htaccessPath = $path . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Deny from all\n");
        }
    }

    /**
     * Create Document record in SuiteCRM
     */
    private function createDocumentRecord($filename, $filepath, $recordingSid, $callSid, $callId, $metadata)
    {
        try {
            require_once('modules/Documents/Document.php');

            $document = BeanFactory::newBean('Documents');
            $document->document_name = "Call Recording - " . date('Y-m-d H:i:s');
            $document->active_date = date('Y-m-d');
            $document->category_id = 'Call Recording';
            $document->subcategory_id = 'Twilio';
            $document->status_id = 'Active';

            $description = "Twilio Call Recording\n";
            $description .= "Recording SID: $recordingSid\n";
            $description .= "Call SID: $callSid\n";
            $description .= "Duration: " . ($metadata['duration'] ?? '0') . " seconds\n";
            $description .= "Date: " . ($metadata['date_created'] ?? date('Y-m-d H:i:s'));

            $document->description = $description;

            // Save document
            $documentId = $document->save();

            // Create DocumentRevision for the file
            require_once('modules/DocumentRevisions/DocumentRevision.php');

            $revision = BeanFactory::newBean('DocumentRevisions');
            $revision->document_id = $documentId;
            $revision->filename = basename($filename);
            $revision->file_ext = 'mp3';
            $revision->file_mime_type = 'audio/mpeg';
            $revision->revision = '1';

            // Move file to SuiteCRM's upload directory
            $uploadFile = "upload://" . create_guid();
            copy($filepath, $uploadFile);

            $revision->save();

            // Link back to document
            $document->document_revision_id = $revision->id;
            $document->save();

            $GLOBALS['log']->info("TwilioRecordingManager: Created Document record $documentId for recording $recordingSid");

            return $documentId;

        } catch (Exception $e) {
            $GLOBALS['log']->error("TwilioRecordingManager: Failed to create Document - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Attach document to Call record
     */
    private function attachToCallRecord($callId, $documentId, $filepath)
    {
        try {
            $call = BeanFactory::getBean('Calls', $callId);

            if ($call && $call->id) {
                // Update call description to include recording link
                $recordingLink = "\n\n📼 Recording: [Document ID: $documentId]";
                $recordingLink .= "\nLocal Path: $filepath";

                $call->description = ($call->description ?? '') . $recordingLink;
                $call->save();

                // Create relationship between Call and Document
                $call->load_relationship('documents');
                $call->documents->add($documentId);

                $GLOBALS['log']->info("TwilioRecordingManager: Attached recording to Call $callId");
                return true;
            }

        } catch (Exception $e) {
            $GLOBALS['log']->error("TwilioRecordingManager: Failed to attach to Call - " . $e->getMessage());
        }

        return false;
    }

    /**
     * Process recording webhook callback from Twilio
     */
    public static function handleRecordingWebhook()
    {
        $recordingSid = $_REQUEST['RecordingSid'] ?? '';
        $callSid = $_REQUEST['CallSid'] ?? '';
        $recordingUrl = $_REQUEST['RecordingUrl'] ?? '';
        $duration = $_REQUEST['RecordingDuration'] ?? 0;
        $status = $_REQUEST['RecordingStatus'] ?? '';

        $GLOBALS['log']->info("TwilioRecordingManager: Webhook received - SID: $recordingSid, Status: $status");

        // Only process completed recordings
        if ($status !== 'completed') {
            return;
        }

        // Find the Call record
        $db = DBManagerFactory::getInstance();
        $callSidSafe = $db->quote($callSid);

        $sql = "SELECT id FROM calls WHERE description LIKE '%$callSidSafe%' AND deleted = 0 ORDER BY date_entered DESC LIMIT 1";
        $result = $db->query($sql);
        $callId = null;

        if ($row = $db->fetchByAssoc($result)) {
            $callId = $row['id'];
        }

        // Download recording asynchronously (or queue it)
        $manager = new TwilioRecordingManager();
        $result = $manager->downloadRecording($recordingSid, $callSid, $callId);

        if ($result) {
            $GLOBALS['log']->info("TwilioRecordingManager: Successfully processed recording $recordingSid");

            // Update LeadJourney with recording information
            $journeyId = LeadJourneyLogger::findByCallSid($callSid);
            if ($journeyId) {
                $recordingUrl = "index.php?module=TwilioIntegration&action=recording&recording_id=" . urlencode($recordingSid);
                LeadJourneyLogger::updateWithRecording(
                    $journeyId,
                    $recordingUrl,
                    $recordingSid,
                    $result['document_id'] ?? ''
                );
                $GLOBALS['log']->info("TwilioRecordingManager: Updated LeadJourney $journeyId with recording");
            }
        } else {
            $GLOBALS['log']->error("TwilioRecordingManager: Failed to process recording $recordingSid");
        }
    }
}
