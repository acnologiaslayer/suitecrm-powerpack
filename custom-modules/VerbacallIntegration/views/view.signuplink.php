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
        header('Content-Type: application/json');

        if (empty($lead->email1)) {
            echo json_encode(['success' => false, 'message' => 'Lead has no email address']);
            exit;
        }

        require_once('include/SugarPHPMailer.php');

        global $sugar_config, $current_user;

        $client = new VerbacallClient();
        $signupUrl = $client->generateSignupUrl($lead->id, $lead->email1);

        $fromEmail = $sugar_config['notify_fromaddress'] ?? 'noreply@boomershub.com';
        $fromName = $sugar_config['notify_fromname'] ?? 'Boomers Hub';

        $leadName = trim($lead->first_name . ' ' . $lead->last_name);
        if (empty($leadName)) {
            $leadName = 'there';
        }

        $subject = "Get Started with Verbacall - Your AI Phone Solution";

        $body = "Hello {$leadName},\n\n";
        $body .= "You've been invited to try Verbacall, our AI-powered phone solution that helps you manage calls more efficiently.\n\n";
        $body .= "Click the link below to create your account:\n";
        $body .= "$signupUrl\n\n";
        $body .= "This personalized link is created just for you.\n\n";
        $body .= "If you have any questions, please don't hesitate to reach out.\n\n";
        $body .= "Best regards,\n";
        $body .= $fromName;

        try {
            $mail = new SugarPHPMailer();
            $mail->setMailerForSystem();
            $mail->From = $fromEmail;
            $mail->FromName = $fromName;
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
        $leadName = htmlspecialchars(trim($lead->first_name . ' ' . $lead->last_name));
        $leadEmail = htmlspecialchars($lead->email1);
        $escapedUrl = htmlspecialchars($signupUrl);
        $linkSent = !empty($lead->verbacall_link_sent_c) ? date('M j, Y g:i A', strtotime($lead->verbacall_link_sent_c)) : null;

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
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            max-width: 500px;
            width: 100%;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.1);
        }
        h1 {
            color: #fff;
            font-size: 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        h1::before { content: ""; font-size: 24px; }
        .lead-info {
            background: rgba(0,0,0,0.3);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .lead-info p {
            color: rgba(255,255,255,0.7);
            margin-bottom: 8px;
            font-size: 14px;
        }
        .lead-info strong { color: #fff; }
        .link-sent-badge {
            background: rgba(78,204,163,0.2);
            color: #4ecca3;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            margin-top: 10px;
            display: inline-block;
        }
        .url-box {
            background: rgba(0,0,0,0.5);
            padding: 15px;
            border-radius: 8px;
            word-break: break-all;
            color: #4ecca3;
            font-family: monospace;
            font-size: 12px;
            margin-bottom: 20px;
            border: 1px solid rgba(78,204,163,0.3);
        }
        .btn {
            width: 100%;
            padding: 14px;
            font-size: 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 500;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .btn-primary {
            background: linear-gradient(135deg, #4ecca3, #38a3a5);
            color: #1a1a2e;
        }
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        .btn-secondary:hover {
            background: rgba(255,255,255,0.15);
        }
        .status {
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
            text-align: center;
            display: none;
            font-size: 14px;
        }
        .status.success {
            background: rgba(78,204,163,0.2);
            color: #4ecca3;
            display: block;
        }
        .status.error {
            background: rgba(220,53,69,0.2);
            color: #ff6b6b;
            display: block;
        }
        .status.loading {
            background: rgba(255,193,7,0.2);
            color: #ffc107;
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Verbacall Sign-up Link</h1>

        <div class="lead-info">
            <p><strong>Lead:</strong> {$leadName}</p>
            <p><strong>Email:</strong> {$leadEmail}</p>
HTML;

        if ($linkSent) {
            echo "<div class=\"link-sent-badge\">Last sent: {$linkSent}</div>";
        }

        echo <<<HTML
        </div>

        <div class="url-box" id="signupUrl">{$escapedUrl}</div>

        <button class="btn btn-secondary" onclick="copyUrl()">Copy URL</button>
        <button class="btn btn-primary" onclick="sendEmail()" id="sendBtn">Send Email to Lead</button>
        <button class="btn btn-secondary" onclick="window.close()">Close</button>

        <div class="status" id="status"></div>
    </div>

    <script>
    function copyUrl() {
        var url = document.getElementById("signupUrl").textContent;
        navigator.clipboard.writeText(url).then(function() {
            showStatus("success", "URL copied to clipboard!");
        }).catch(function() {
            // Fallback for older browsers
            var textArea = document.createElement("textarea");
            textArea.value = url;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand("copy");
            document.body.removeChild(textArea);
            showStatus("success", "URL copied to clipboard!");
        });
    }

    function sendEmail() {
        var btn = document.getElementById("sendBtn");
        btn.disabled = true;
        btn.textContent = "Sending...";
        showStatus("loading", "Sending email...");

        fetch(window.location.href, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "send_email=1"
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            showStatus(data.success ? "success" : "error", data.message);
            btn.disabled = false;
            btn.textContent = "Send Email to Lead";
        })
        .catch(function(err) {
            showStatus("error", "Error: " + err.message);
            btn.disabled = false;
            btn.textContent = "Send Email to Lead";
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
        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Error</title>
    <style>
        body {
            background: #1a1a2e;
            color: #ff6b6b;
            padding: 50px;
            text-align: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        h2 { margin-bottom: 20px; }
        button {
            margin-top: 20px;
            padding: 10px 20px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <h2>Error</h2>
    <p>{$message}</p>
    <button onclick="window.close()">Close</button>
</body>
</html>
HTML;
        exit;
    }
}
