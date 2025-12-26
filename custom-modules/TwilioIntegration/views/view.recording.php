<?php
/**
 * Twilio Recording Secure Download Endpoint
 * Serves call recordings with role-based permission checks
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');

class TwilioIntegrationViewRecording extends SugarView
{
    public function display()
    {
        global $current_user;

        // Must be logged in
        if (empty($current_user) || empty($current_user->id)) {
            $this->sendError(401, 'Authentication required');
            return;
        }

        // Check ACL permission for viewing recordings
        if (!$this->hasRecordingPermission()) {
            $this->sendError(403, 'You do not have permission to view call recordings');
            return;
        }

        // Get recording identifier
        $recordingId = isset($_REQUEST['recording_id']) ? $_REQUEST['recording_id'] : '';
        $documentId = isset($_REQUEST['document_id']) ? $_REQUEST['document_id'] : '';
        $callSid = isset($_REQUEST['call_sid']) ? $_REQUEST['call_sid'] : '';

        if (!empty($documentId)) {
            $this->serveFromDocument($documentId);
        } elseif (!empty($recordingId)) {
            $this->serveFromTwilio($recordingId);
        } elseif (!empty($callSid)) {
            $this->serveByCallSid($callSid);
        } else {
            $this->sendError(400, 'Missing recording identifier (recording_id, document_id, or call_sid)');
        }
    }

    /**
     * Check if current user has permission to view recordings
     */
    private function hasRecordingPermission()
    {
        global $current_user;

        // Admins always have access
        if ($current_user->isAdmin()) {
            return true;
        }

        // Check custom ACL action
        require_once('modules/ACLActions/ACLAction.php');
        $access = ACLAction::getUserAccessLevel($current_user->id, 'TwilioIntegration', 'view_recordings');

        // ACL_ALLOW_ALL = 90, ACL_ALLOW_OWNER = 75, ACL_ALLOW_GROUP = 80, ACL_ALLOW_NONE = 1
        // -99 means not configured (default deny)
        if ($access >= 90 || $access === ACL_ALLOW_ALL) {
            return true;
        }

        // Also check if user has been explicitly granted access through role assignment
        // Check acl_roles_users and acl_roles_actions
        $db = DBManagerFactory::getInstance();
        $userId = $db->quote($current_user->id);

        $sql = "SELECT ara.access
                FROM acl_roles_users aru
                JOIN acl_roles_actions ara ON aru.role_id = ara.role_id
                JOIN acl_actions aa ON ara.action_id = aa.id
                WHERE aru.user_id = '$userId'
                AND aru.deleted = 0
                AND ara.deleted = 0
                AND aa.category = 'TwilioIntegration'
                AND aa.name = 'view_recordings'
                AND aa.deleted = 0
                AND ara.access >= 90
                LIMIT 1";

        $result = $db->query($sql);
        if ($db->fetchByAssoc($result)) {
            return true;
        }

        return false;
    }

    /**
     * Serve recording from SuiteCRM Documents module
     */
    private function serveFromDocument($documentId)
    {
        require_once('modules/Documents/Document.php');
        require_once('modules/DocumentRevisions/DocumentRevision.php');

        $document = BeanFactory::getBean('Documents', $documentId);

        if (!$document || empty($document->id)) {
            $this->sendError(404, 'Recording document not found');
            return;
        }

        // Get the latest revision
        $revisionId = $document->document_revision_id;
        if (empty($revisionId)) {
            $this->sendError(404, 'Recording file not found');
            return;
        }

        $revision = BeanFactory::getBean('DocumentRevisions', $revisionId);
        if (!$revision || empty($revision->id)) {
            $this->sendError(404, 'Recording revision not found');
            return;
        }

        // Get the file path
        $filepath = 'upload/' . $revision->id;

        if (!file_exists($filepath)) {
            $this->sendError(404, 'Recording file not found on disk');
            return;
        }

        // Log access for audit
        $this->logRecordingAccess($documentId, 'document');

        // Serve the file
        $this->serveFile($filepath, $revision->filename, $revision->file_mime_type ?? 'audio/mpeg');
    }

    /**
     * Serve recording directly from local storage (by recording SID)
     */
    private function serveFromTwilio($recordingSid)
    {
        global $sugar_config;

        $storagePath = $sugar_config['twilio_recording_path'] ?? 'upload/twilio_recordings';

        // Handle upload:// protocol
        if (strpos($storagePath, 'upload://') === 0) {
            $storagePath = str_replace('upload://', 'upload/', $storagePath);
        }

        // Find the recording file
        $pattern = $storagePath . "/*{$recordingSid}*.mp3";
        $files = glob($pattern);

        if (empty($files)) {
            $this->sendError(404, 'Recording not found');
            return;
        }

        $filepath = $files[0];
        $filename = basename($filepath);

        // Log access for audit
        $this->logRecordingAccess($recordingSid, 'twilio_sid');

        $this->serveFile($filepath, $filename, 'audio/mpeg');
    }

    /**
     * Find and serve recording by Call SID
     */
    private function serveByCallSid($callSid)
    {
        $db = DBManagerFactory::getInstance();
        $callSidSafe = $db->quote($callSid);

        // First, try to find associated Document
        $sql = "SELECT d.id
                FROM documents d
                WHERE d.description LIKE '%$callSidSafe%'
                AND d.deleted = 0
                ORDER BY d.date_entered DESC
                LIMIT 1";

        $result = $db->query($sql);
        if ($row = $db->fetchByAssoc($result)) {
            $this->serveFromDocument($row['id']);
            return;
        }

        // Try local storage
        global $sugar_config;
        $storagePath = $sugar_config['twilio_recording_path'] ?? 'upload/twilio_recordings';

        if (strpos($storagePath, 'upload://') === 0) {
            $storagePath = str_replace('upload://', 'upload/', $storagePath);
        }

        $pattern = $storagePath . "/*{$callSid}*.mp3";
        $files = glob($pattern);

        if (!empty($files)) {
            $this->logRecordingAccess($callSid, 'call_sid');
            $this->serveFile($files[0], basename($files[0]), 'audio/mpeg');
            return;
        }

        $this->sendError(404, 'Recording not found for this call');
    }

    /**
     * Serve a file to the browser
     */
    private function serveFile($filepath, $filename, $mimeType)
    {
        if (!file_exists($filepath)) {
            $this->sendError(404, 'File not found');
            return;
        }

        $filesize = filesize($filepath);

        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . $filesize);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');

        // Handle range requests for audio seeking
        if (isset($_SERVER['HTTP_RANGE'])) {
            $this->serveRangeRequest($filepath, $filesize, $mimeType);
        } else {
            readfile($filepath);
        }

        exit;
    }

    /**
     * Handle HTTP Range requests for audio playback
     */
    private function serveRangeRequest($filepath, $filesize, $mimeType)
    {
        $range = $_SERVER['HTTP_RANGE'];

        if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            $start = intval($matches[1]);
            $end = !empty($matches[2]) ? intval($matches[2]) : $filesize - 1;

            if ($start > $end || $start >= $filesize) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $filesize);
                exit;
            }

            $length = $end - $start + 1;

            header('HTTP/1.1 206 Partial Content');
            header('Content-Type: ' . $mimeType);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $filesize);
            header('Content-Length: ' . $length);

            $fp = fopen($filepath, 'rb');
            fseek($fp, $start);

            $bufferSize = 8192;
            $remaining = $length;

            while ($remaining > 0 && !feof($fp)) {
                $readSize = min($bufferSize, $remaining);
                echo fread($fp, $readSize);
                $remaining -= $readSize;
                flush();
            }

            fclose($fp);
        }
    }

    /**
     * Log recording access for audit trail
     */
    private function logRecordingAccess($identifier, $type)
    {
        global $current_user;
        $db = DBManagerFactory::getInstance();

        $id = create_guid();
        $action = 'recording_access';
        $data = json_encode([
            'identifier' => $identifier,
            'identifier_type' => $type,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        $dataSafe = $db->quote($data);
        $userId = $db->quote($current_user->id);
        $dateCreated = gmdate('Y-m-d H:i:s');

        try {
            $sql = "INSERT INTO twilio_audit_log (id, action, data, user_id, date_created)
                    VALUES ('$id', '$action', '$dataSafe', '$userId', '$dateCreated')";
            $db->query($sql, false);
        } catch (Exception $e) {
            $GLOBALS['log']->warn("Failed to log recording access: " . $e->getMessage());
        }
    }

    /**
     * Send error response
     */
    private function sendError($code, $message)
    {
        $statusMessages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found'
        ];

        $statusText = $statusMessages[$code] ?? 'Error';
        header("HTTP/1.1 $code $statusText");
        header('Content-Type: application/json');

        echo json_encode([
            'error' => true,
            'code' => $code,
            'message' => $message
        ]);

        exit;
    }

    /**
     * Check if user can view a specific recording (owner-based check)
     * For future use with owner-level permissions
     */
    public static function canUserViewRecording($userId, $recordingDocumentId)
    {
        // Admin always can view
        $user = BeanFactory::getBean('Users', $userId);
        if ($user && $user->isAdmin()) {
            return true;
        }

        // Check if user has view_recordings permission at any level
        $access = ACLAction::getUserAccessLevel($userId, 'TwilioIntegration', 'view_recordings');

        if ($access >= 90) {
            return true; // Full access
        }

        if ($access >= 75) {
            // Owner access - check if user owns the related call
            $db = DBManagerFactory::getInstance();
            $docId = $db->quote($recordingDocumentId);
            $userIdSafe = $db->quote($userId);

            $sql = "SELECT c.id FROM calls c
                    JOIN calls_documents cd ON c.id = cd.call_id
                    WHERE cd.document_id = '$docId'
                    AND (c.assigned_user_id = '$userIdSafe' OR c.created_by = '$userIdSafe')
                    AND c.deleted = 0
                    LIMIT 1";

            $result = $db->query($sql);
            if ($db->fetchByAssoc($result)) {
                return true;
            }
        }

        return false;
    }
}
