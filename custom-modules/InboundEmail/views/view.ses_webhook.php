<?php
/**
 * AWS SES Inbound Email Webhook
 *
 * Receives inbound emails from AWS SES via SNS notifications
 * Configure AWS SES to forward emails to SNS, then subscribe this webhook URL
 *
 * Webhook URL: https://your-domain.com/legacy/index.php?module=InboundEmail&action=ses_webhook
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');
require_once('modules/LeadJourney/LeadJourneyLogger.php');

class InboundEmailViewSes_webhook extends SugarView
{
    public function display()
    {
        // Get raw POST data
        $rawInput = file_get_contents('php://input');

        if (empty($rawInput)) {
            $this->respond(400, ['error' => 'No input data']);
            return;
        }

        // Log incoming webhook for debugging
        $GLOBALS['log']->info("SES Webhook received: " . substr($rawInput, 0, 500));

        // Parse JSON
        $data = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->respond(400, ['error' => 'Invalid JSON']);
            return;
        }

        // Handle SNS subscription confirmation
        if (isset($data['Type']) && $data['Type'] === 'SubscriptionConfirmation') {
            $this->handleSubscriptionConfirmation($data);
            return;
        }

        // Handle SNS notification (actual email)
        if (isset($data['Type']) && $data['Type'] === 'Notification') {
            $this->handleNotification($data);
            return;
        }

        // Direct SES message (without SNS wrapper)
        if (isset($data['mail']) || isset($data['receipt'])) {
            $this->processEmailNotification($data);
            return;
        }

        $this->respond(400, ['error' => 'Unknown message type']);
    }

    /**
     * Handle SNS subscription confirmation
     */
    private function handleSubscriptionConfirmation($data)
    {
        if (empty($data['SubscribeURL'])) {
            $this->respond(400, ['error' => 'Missing SubscribeURL']);
            return;
        }

        // Validate it's from AWS
        if (!$this->validateSNSMessage($data)) {
            $GLOBALS['log']->error("SES Webhook: Invalid SNS subscription confirmation");
            $this->respond(400, ['error' => 'Invalid signature']);
            return;
        }

        // Auto-confirm subscription by calling the URL
        $ch = curl_init($data['SubscribeURL']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $GLOBALS['log']->info("SES Webhook: SNS subscription confirmed");
            $this->respond(200, ['success' => true, 'message' => 'Subscription confirmed']);
        } else {
            $GLOBALS['log']->error("SES Webhook: Failed to confirm subscription");
            $this->respond(500, ['error' => 'Failed to confirm subscription']);
        }
    }

    /**
     * Handle SNS notification containing email
     */
    private function handleNotification($data)
    {
        // Validate SNS signature
        if (!$this->validateSNSMessage($data)) {
            $GLOBALS['log']->error("SES Webhook: Invalid SNS notification signature");
            $this->respond(400, ['error' => 'Invalid signature']);
            return;
        }

        // Parse the message content (which contains the SES notification)
        $message = json_decode($data['Message'], true);

        if (empty($message)) {
            $GLOBALS['log']->error("SES Webhook: Empty message in notification");
            $this->respond(400, ['error' => 'Empty message']);
            return;
        }

        $this->processEmailNotification($message);
    }

    /**
     * Process the actual email notification from SES
     */
    private function processEmailNotification($message)
    {
        global $db;

        // Extract email details
        $mail = $message['mail'] ?? [];
        $receipt = $message['receipt'] ?? [];
        $content = $message['content'] ?? '';

        // Get sender and recipients
        $fromAddress = '';
        if (!empty($mail['source'])) {
            $fromAddress = $mail['source'];
        } elseif (!empty($mail['commonHeaders']['from'][0])) {
            $fromAddress = $this->extractEmail($mail['commonHeaders']['from'][0]);
        }

        $toAddresses = [];
        if (!empty($mail['destination'])) {
            $toAddresses = $mail['destination'];
        } elseif (!empty($mail['commonHeaders']['to'])) {
            $toAddresses = array_map([$this, 'extractEmail'], $mail['commonHeaders']['to']);
        }

        $subject = $mail['commonHeaders']['subject'] ?? 'No Subject';
        $messageId = $mail['messageId'] ?? create_guid();
        $timestamp = $mail['timestamp'] ?? date('Y-m-d H:i:s');

        $GLOBALS['log']->info("SES Webhook: Processing email from $fromAddress, subject: $subject");

        // Parse email content if provided
        $bodyText = '';
        $bodyHtml = '';
        $attachments = [];

        if (!empty($content)) {
            $parsed = $this->parseEmailContent($content);
            $bodyText = $parsed['text'];
            $bodyHtml = $parsed['html'];
            $attachments = $parsed['attachments'];
        }

        // Find matching Lead/Contact by email
        $linkedRecord = $this->findRecordByEmail($fromAddress);

        // Create Email record in SuiteCRM
        $email = BeanFactory::newBean('Emails');
        $email->name = $subject;
        $email->date_sent = date('Y-m-d H:i:s', strtotime($timestamp));
        $email->type = 'inbound';
        $email->status = 'read';
        $email->from_addr = $fromAddress;
        $email->to_addrs = implode(', ', $toAddresses);
        $email->description = $bodyText;
        $email->description_html = $bodyHtml;
        $email->message_id = $messageId;

        if ($linkedRecord) {
            $email->parent_type = $linkedRecord['module'];
            $email->parent_id = $linkedRecord['id'];
        }

        $email->save();

        // Link email to the record
        if ($linkedRecord && !empty($email->id)) {
            $this->linkEmailToRecord($email->id, $linkedRecord['module'], $linkedRecord['id']);

            // Log to LeadJourney
            LeadJourneyLogger::logEmail(
                $linkedRecord['module'],
                $linkedRecord['id'],
                'inbound',
                $email->id,
                $subject,
                $fromAddress
            );

            $GLOBALS['log']->info("SES Webhook: Email linked to {$linkedRecord['module']} {$linkedRecord['id']}");
        }

        // Save attachments if any
        if (!empty($attachments) && !empty($email->id)) {
            foreach ($attachments as $attachment) {
                $this->saveAttachment($email->id, $attachment);
            }
        }

        $this->respond(200, [
            'success' => true,
            'email_id' => $email->id,
            'linked_to' => $linkedRecord ? $linkedRecord['module'] . ':' . $linkedRecord['id'] : null
        ]);
    }

    /**
     * Validate SNS message signature
     */
    private function validateSNSMessage($message)
    {
        // In production, you should validate the SNS signature
        // For now, we do basic validation

        // Check required fields
        $requiredFields = ['Type', 'TopicArn', 'Timestamp'];
        foreach ($requiredFields as $field) {
            if (empty($message[$field])) {
                return false;
            }
        }

        // Validate TopicArn is from AWS
        if (!preg_match('/^arn:aws:sns:[a-z0-9-]+:\d+:/', $message['TopicArn'])) {
            return false;
        }

        // TODO: Implement full signature verification
        // See: https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html

        return true;
    }

    /**
     * Extract email address from "Name <email>" format
     */
    private function extractEmail($input)
    {
        if (preg_match('/<([^>]+)>/', $input, $matches)) {
            return $matches[1];
        }
        return trim($input);
    }

    /**
     * Parse raw email content (MIME)
     */
    private function parseEmailContent($content)
    {
        $result = [
            'text' => '',
            'html' => '',
            'attachments' => []
        ];

        // Basic MIME parsing - for production use a library like php-mime-mail-parser
        if (strpos($content, 'Content-Type: multipart/') !== false) {
            // Find boundary
            if (preg_match('/boundary="?([^"\s]+)"?/', $content, $matches)) {
                $boundary = $matches[1];
                $parts = explode('--' . $boundary, $content);

                foreach ($parts as $part) {
                    if (strpos($part, 'Content-Type: text/plain') !== false) {
                        $result['text'] = $this->extractBodyFromPart($part);
                    } elseif (strpos($part, 'Content-Type: text/html') !== false) {
                        $result['html'] = $this->extractBodyFromPart($part);
                    } elseif (preg_match('/Content-Disposition: attachment/', $part)) {
                        $attachment = $this->extractAttachmentFromPart($part);
                        if ($attachment) {
                            $result['attachments'][] = $attachment;
                        }
                    }
                }
            }
        } else {
            // Simple text email
            $result['text'] = $content;
        }

        return $result;
    }

    /**
     * Extract body from MIME part
     */
    private function extractBodyFromPart($part)
    {
        // Split headers from body
        $parts = preg_split('/\r?\n\r?\n/', $part, 2);
        if (count($parts) < 2) {
            return '';
        }

        $body = $parts[1];

        // Check for base64 encoding
        if (stripos($parts[0], 'Content-Transfer-Encoding: base64') !== false) {
            $body = base64_decode($body);
        } elseif (stripos($parts[0], 'Content-Transfer-Encoding: quoted-printable') !== false) {
            $body = quoted_printable_decode($body);
        }

        return trim($body);
    }

    /**
     * Extract attachment from MIME part
     */
    private function extractAttachmentFromPart($part)
    {
        // Get filename
        $filename = 'attachment';
        if (preg_match('/filename="?([^"\n]+)"?/', $part, $matches)) {
            $filename = trim($matches[1]);
        }

        // Get content type
        $contentType = 'application/octet-stream';
        if (preg_match('/Content-Type:\s*([^\s;]+)/', $part, $matches)) {
            $contentType = $matches[1];
        }

        // Get content
        $parts = preg_split('/\r?\n\r?\n/', $part, 2);
        if (count($parts) < 2) {
            return null;
        }

        $content = $parts[1];

        // Decode base64
        if (stripos($parts[0], 'Content-Transfer-Encoding: base64') !== false) {
            $content = base64_decode($content);
        }

        return [
            'filename' => $filename,
            'content_type' => $contentType,
            'content' => $content
        ];
    }

    /**
     * Find Lead or Contact by email address
     */
    private function findRecordByEmail($email)
    {
        global $db;

        $email = $db->quote(strtolower(trim($email)));

        // Search in email_addresses table
        $sql = "SELECT eabr.bean_module, eabr.bean_id
                FROM email_addresses ea
                JOIN email_addr_bean_rel eabr ON ea.id = eabr.email_address_id
                WHERE LOWER(ea.email_address) = '$email'
                AND eabr.deleted = 0
                AND ea.deleted = 0
                AND eabr.bean_module IN ('Leads', 'Contacts', 'Accounts')
                ORDER BY FIELD(eabr.bean_module, 'Leads', 'Contacts', 'Accounts')
                LIMIT 1";

        $result = $db->query($sql);
        if ($row = $db->fetchByAssoc($result)) {
            return [
                'module' => $row['bean_module'],
                'id' => $row['bean_id']
            ];
        }

        // Also check email1 fields directly
        foreach (['leads', 'contacts'] as $table) {
            $module = ucfirst(rtrim($table, 's')) . 's';
            if ($module === 'Contactss') $module = 'Contacts';
            if ($module === 'Leadss') $module = 'Leads';

            $sql = "SELECT id FROM $table
                    WHERE LOWER(email1) = '$email'
                    AND deleted = 0
                    LIMIT 1";

            $result = $db->query($sql);
            if ($row = $db->fetchByAssoc($result)) {
                return [
                    'module' => $module,
                    'id' => $row['id']
                ];
            }
        }

        return null;
    }

    /**
     * Link email to record via emails_beans
     */
    private function linkEmailToRecord($emailId, $module, $recordId)
    {
        global $db;

        $id = create_guid();
        $emailId = $db->quote($emailId);
        $module = $db->quote($module);
        $recordId = $db->quote($recordId);

        $sql = "INSERT INTO emails_beans (id, email_id, bean_id, bean_module, date_modified, deleted)
                VALUES ('$id', '$emailId', '$recordId', '$module', NOW(), 0)
                ON DUPLICATE KEY UPDATE deleted = 0";

        $db->query($sql);
    }

    /**
     * Save attachment as Note
     */
    private function saveAttachment($emailId, $attachment)
    {
        $note = BeanFactory::newBean('Notes');
        $note->name = $attachment['filename'];
        $note->parent_type = 'Emails';
        $note->parent_id = $emailId;
        $note->file_mime_type = $attachment['content_type'];
        $note->filename = $attachment['filename'];
        $note->save();

        // Save file content
        if (!empty($note->id) && !empty($attachment['content'])) {
            $uploadDir = 'upload/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $filePath = $uploadDir . $note->id;
            file_put_contents($filePath, $attachment['content']);
        }
    }

    /**
     * Send JSON response
     */
    private function respond($code, $data)
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        die();
    }
}
