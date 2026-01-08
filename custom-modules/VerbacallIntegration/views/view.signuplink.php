<?php
/**
 * VerbacallIntegration - Signup Link View
 *
 * Popup window for generating and sending Verbacall signup links to leads.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');
require_once('modules/VerbacallIntegration/VerbacallClient.php');

class VerbacallIntegrationViewSignuplink extends SugarView
{
    public function preDisplay()
    {
        $this->options['show_header'] = false;
        $this->options['show_footer'] = false;
    }

    public function display()
    {
        global $current_user;

        $leadId = isset($_REQUEST['lead_id']) ? $_REQUEST['lead_id'] : '';

        if (empty($leadId)) {
            $this->displayError('Lead ID is required');
            return;
        }

        // Get lead data
        $lead = BeanFactory::getBean('Leads', $leadId);
        if (!$lead || $lead->deleted) {
            $this->displayError('Lead not found');
            return;
        }

        // Handle email sending action
        if (!empty($_POST['send_email'])) {
            $this->sendSignupEmail($lead);
            return;
        }

        // Generate signup URL
        $client = new VerbacallClient();
        $signupUrl = $client->generateSignupUrl($leadId, $lead->email1);

        $this->displaySignupUI($lead, $signupUrl);
    }

    private function sendSignupEmail($lead)
    {
        // Clear ALL output buffers to prevent contamination from other modules
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        if (empty($lead->email1)) {
            echo json_encode(['success' => false, 'message' => 'Lead has no email address']);
            exit;
        }

        require_once('include/SugarPHPMailer.php');

        global $sugar_config, $current_user;

        $client = new VerbacallClient();
        $signupUrl = $client->generateSignupUrl($lead->id, $lead->email1);

        // Use logged-in user's name and email, fallback to system outbound email
        $fromName = trim($current_user->first_name . ' ' . $current_user->last_name);
        if (empty($fromName)) {
            $fromName = $current_user->user_name ?? 'Verbacall';
        }

        // Get FROM email - try user's email first, fallback to no-reply
        $fromEmail = $current_user->email1;
        if (empty($fromEmail)) {
            $fromEmail = 'no-reply@verbacall.com';
        }

        $leadName = trim($lead->first_name . ' ' . $lead->last_name);
        if (empty($leadName)) {
            $leadName = 'there';
        }

        // Use custom subject/body if provided, otherwise use defaults
        if (!empty($_POST['subject'])) {
            $subject = $_POST['subject'];
        } else {
            $subject = "Get Started with Verbacall - Your AI Phone Solution";
        }

        if (!empty($_POST['body'])) {
            $body = $_POST['body'];
        } else {
            $body = "Hello {$leadName},\n\n";
            $body .= "You've been invited to try Verbacall, our AI-powered phone solution that helps you manage calls more efficiently.\n\n";
            $body .= "Click the link below to create your account:\n";
            $body .= "$signupUrl\n\n";
            $body .= "This personalized link is created just for you.\n\n";
            $body .= "If you have any questions, please don't hesitate to reach out.\n\n";
            $body .= "Best regards,\n";
            $body .= $fromName;
        }

        try {
            $mail = new SugarPHPMailer();
            $mail->setMailerForSystem();

            // setMailerForSystem() doesn't set From/FromName, load from OutboundEmail directly
            require_once('include/OutboundEmail/OutboundEmail.php');
            $oe = new OutboundEmail();
            $systemOe = $oe->getSystemMailerSettings();
            if ($systemOe && !empty($systemOe->smtp_from_addr)) {
                $mail->From = $systemOe->smtp_from_addr;
                $mail->FromName = $systemOe->smtp_from_name ?: $fromName;
            } else {
                // Fallback to config values
                $mail->From = $fromEmail;
                $mail->FromName = $fromName;
            }

            $mail->addAddress($lead->email1, $leadName);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);

            $sent = $mail->send();

            if ($sent) {
                // Update lead with sent timestamp
                $lead->verbacall_link_sent_c = gmdate('Y-m-d H:i:s');
                $lead->save();

                // Log to Lead Journey if module exists
                $this->logTouchpoint($lead->id, 'verbacall_signup_sent', [
                    'signup_url' => $signupUrl,
                    'sent_by' => $current_user->id,
                    'sent_to' => $lead->email1
                ]);

                $GLOBALS['log']->info("VerbacallIntegration: Signup email sent to {$lead->email1} for lead {$lead->id}");

                echo json_encode(['success' => true, 'message' => 'Email sent successfully!']);
            } else {
                $GLOBALS['log']->error("VerbacallIntegration: Failed to send signup email to {$lead->email1}");
                echo json_encode(['success' => false, 'message' => 'Failed to send email. Please check mail configuration.']);
            }
        } catch (Exception $e) {
            $GLOBALS['log']->error("VerbacallIntegration: Email exception - " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }

        exit;
    }

    private function logTouchpoint($leadId, $type, $data)
    {
        try {
            $journey = BeanFactory::newBean('LeadJourney');
            if (!$journey) return;

            $journey->id = create_guid();
            $journey->new_with_id = true;
            $journey->name = 'Verbacall Signup Link Sent';
            $journey->parent_type = 'Leads';
            $journey->parent_id = $leadId;
            $journey->touchpoint_type = $type;
            $journey->touchpoint_date = gmdate('Y-m-d H:i:s');
            $journey->touchpoint_data = json_encode($data);
            $journey->save();
        } catch (Exception $e) {
            $GLOBALS['log']->warn("VerbacallIntegration: Could not log touchpoint - " . $e->getMessage());
        }
    }

    private function displaySignupUI($lead, $signupUrl)
    {
        global $current_user;

        // Plain text versions for JavaScript email body (no HTML encoding)
        $leadNamePlain = trim($lead->first_name . ' ' . $lead->last_name);
        if (empty($leadNamePlain)) {
            $leadNamePlain = 'there';
        }
        $senderNamePlain = trim($current_user->first_name . ' ' . $current_user->last_name);
        if (empty($senderNamePlain)) {
            $senderNamePlain = $current_user->user_name ?? 'Verbacall';
        }

        // HTML-encoded versions for display in HTML
        $leadName = htmlspecialchars($leadNamePlain);
        $leadEmail = htmlspecialchars($lead->email1);
        $escapedUrl = htmlspecialchars($signupUrl);
        $linkSent = !empty($lead->verbacall_link_sent_c) ? date('M j, Y g:i A', strtotime($lead->verbacall_link_sent_c)) : null;
        $senderName = htmlspecialchars($senderNamePlain);

        // JSON-encode for safe JavaScript embedding (handles quotes and special chars)
        $leadNameJs = json_encode($leadNamePlain);
        $senderNameJs = json_encode($senderNamePlain);

        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Verbacall Signup Link</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 480px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            border: 1px solid #e0e0e0;
        }
        h1 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #28a745;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .lead-info {
            background: #f8f9fa;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        .lead-info p {
            color: #666;
            margin-bottom: 6px;
            font-size: 14px;
        }
        .lead-info p:last-child { margin-bottom: 0; }
        .lead-info strong { color: #333; }
        .link-sent-badge {
            background: #d4edda;
            color: #155724;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 12px;
            margin-top: 10px;
            display: inline-block;
            border: 1px solid #c3e6cb;
        }
        .url-box {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            word-break: break-all;
            color: #0056b3;
            font-family: "SF Mono", Monaco, monospace;
            font-size: 12px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }
        .btn {
            width: 100%;
            padding: 12px;
            font-size: 14px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin-bottom: 10px;
            font-weight: 600;
            transition: background 0.2s, transform 0.1s;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }
        .btn-primary {
            background: #28a745;
            color: #fff;
        }
        .btn-primary:hover { background: #218838; }
        .btn-primary:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }
        .btn-secondary:hover { background: #5a6268; }
        .btn-copy {
            background: #17a2b8;
            color: #fff;
        }
        .btn-copy:hover { background: #138496; }
        .status {
            padding: 12px;
            border-radius: 6px;
            margin-top: 16px;
            text-align: center;
            display: none;
            font-size: 14px;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: block;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: block;
        }
        .status.loading {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
            display: block;
        }
        .email-composer {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }
        .email-composer h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 16px;
        }
        .form-group {
            margin-bottom: 14px;
        }
        .form-group label {
            display: block;
            color: #555;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .form-group input[type="text"],
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            color: #333;
            background: #fff;
        }
        .form-group input[type="text"]:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #28a745;
            box-shadow: 0 0 0 3px rgba(40,167,69,0.15);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 150px;
            line-height: 1.5;
        }
        .button-row {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }
        .button-row .btn {
            flex: 1;
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>✉️ Verbacall Sign-up Link</h1>

        <div class="lead-info">
            <p><strong>Lead:</strong> {$leadName}</p>
            <p><strong>Email:</strong> {$leadEmail}</p>
HTML;

        if ($linkSent) {
            echo "<div class=\"link-sent-badge\">Last sent: {$linkSent}</div>";
        }

        echo <<<HTML
        </div>

        <p style="color:#555;font-size:13px;margin-bottom:8px;font-weight:600;">Sign-up URL:</p>
        <div class="url-box" id="signupUrl">{$escapedUrl}</div>

        <button class="btn btn-copy" onclick="copyUrl()">📋 Copy URL</button>
        <button class="btn btn-primary" onclick="showEmailComposer()" id="composeBtn">📧 Compose Email</button>
        <button class="btn btn-secondary" onclick="window.close()">Close</button>

        <div class="email-composer" id="emailComposer" style="display:none;">
            <h3>Email Preview</h3>
            <div class="form-group">
                <label for="emailSubject">Subject:</label>
                <input type="text" id="emailSubject" value="Get Started with Verbacall - Your AI Phone Solution">
            </div>
            <div class="form-group">
                <label for="emailBody">Message:</label>
                <textarea id="emailBody" rows="12"></textarea>
            </div>
            <div class="button-row">
                <button class="btn btn-primary" onclick="sendEmail()" id="sendBtn">📤 Send Email</button>
                <button class="btn btn-secondary" onclick="hideEmailComposer()">Cancel</button>
            </div>
        </div>

        <div class="status" id="status"></div>
    </div>

    <script>
    var leadNameJs = {$leadNameJs};
    var senderNameJs = {$senderNameJs};
    var signupUrlJs = "{$escapedUrl}";
    var defaultBody = "Hello " + leadNameJs + ",\\n\\nYou've been invited to try Verbacall, our AI-powered phone solution that helps you manage calls more efficiently.\\n\\nClick the link below to create your account:\\n" + signupUrlJs + "\\n\\nThis personalized link is created just for you.\\n\\nIf you have any questions, please don't hesitate to reach out.\\n\\nBest regards,\\n" + senderNameJs;

    function copyUrl() {
        var url = document.getElementById("signupUrl").textContent;
        navigator.clipboard.writeText(url).then(function() {
            showStatus("success", "✓ URL copied to clipboard!");
        }).catch(function() {
            var textArea = document.createElement("textarea");
            textArea.value = url;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand("copy");
            document.body.removeChild(textArea);
            showStatus("success", "✓ URL copied to clipboard!");
        });
    }

    function showEmailComposer() {
        document.getElementById("emailBody").value = defaultBody;
        document.getElementById("emailComposer").style.display = "block";
        document.getElementById("composeBtn").style.display = "none";
    }

    function hideEmailComposer() {
        document.getElementById("emailComposer").style.display = "none";
        document.getElementById("composeBtn").style.display = "block";
    }

    function sendEmail() {
        var btn = document.getElementById("sendBtn");
        var subject = document.getElementById("emailSubject").value;
        var body = document.getElementById("emailBody").value;

        btn.disabled = true;
        btn.textContent = "Sending...";
        showStatus("loading", "Sending email...");

        var data = new URLSearchParams();
        data.append("send_email", "1");
        data.append("subject", subject);
        data.append("body", body);

        fetch(window.location.href, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept": "application/json"
            },
            body: data.toString(),
            credentials: "same-origin"
        })
        .then(function(response) {
            return response.text().then(function(text) {
                var jsonMatch = text.match(/^\s*(\{[\s\S]*?\})\s*/);
                if (jsonMatch) {
                    try { return JSON.parse(jsonMatch[1]); } catch (e) {}
                }
                try { return JSON.parse(text); } catch (e) {
                    console.error("Raw response:", text.substring(0, 500));
                    throw new Error("Invalid JSON response from server");
                }
            });
        })
        .then(function(data) {
            showStatus(data.success ? "success" : "error", data.success ? "✓ " + data.message : data.message);
            btn.disabled = false;
            btn.textContent = "📤 Send Email";
            if (data.success) hideEmailComposer();
        })
        .catch(function(err) {
            showStatus("error", "Error: " + err.message);
            btn.disabled = false;
            btn.textContent = "📤 Send Email";
        });
    }

    function showStatus(type, message) {
        var el = document.getElementById("status");
        el.className = "status " + type;
        el.textContent = message;
    }
    </script>
</body>
</html>
HTML;
        exit;
    }

    private function displayError($message)
    {
        $escapedMessage = htmlspecialchars($message);
        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Error - Verbacall</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-container {
            max-width: 400px;
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            border: 1px solid #e0e0e0;
            text-align: center;
        }
        .error-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        h2 {
            color: #dc3545;
            font-size: 20px;
            margin-bottom: 12px;
        }
        p {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 20px;
        }
        button {
            padding: 10px 24px;
            background: #6c757d;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s;
        }
        button:hover { background: #5a6268; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <h2>Error</h2>
        <p>{$escapedMessage}</p>
        <button onclick="window.close()">Close</button>
    </div>
</body>
</html>
HTML;
        exit;
    }
}
