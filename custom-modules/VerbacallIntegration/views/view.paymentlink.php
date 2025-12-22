<?php
/**
 * VerbacallIntegration - Payment Link View
 *
 * Popup window for generating Verbacall discount/payment links for leads.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/MVC/View/SugarView.php');
require_once('modules/VerbacallIntegration/VerbacallClient.php');

class VerbacallIntegrationViewPaymentlink extends SugarView
{
    public function preDisplay()
    {
        $this->options['show_header'] = false;
        $this->options['show_footer'] = false;
    }

    public function display()
    {
        global $sugar_config, $current_user;

        $leadId = isset($_REQUEST['lead_id']) ? $_REQUEST['lead_id'] : '';

        if (empty($leadId)) {
            $this->displayError('Lead ID is required');
            return;
        }

        $lead = BeanFactory::getBean('Leads', $leadId);
        if (!$lead || $lead->deleted) {
            $this->displayError('Lead not found');
            return;
        }

        // Handle discount offer creation
        if (!empty($_POST['create_offer'])) {
            $this->createDiscountOffer($lead);
            return;
        }

        // Handle email sending
        if (!empty($_POST['send_email'])) {
            $this->sendPaymentEmail($lead);
            return;
        }

        // Fetch plans
        try {
            $client = new VerbacallClient();
            $plans = $client->getPlans();
        } catch (Exception $e) {
            $this->displayError('Failed to load plans: ' . $e->getMessage());
            return;
        }

        // Default settings from config
        $defaultDiscount = $sugar_config['verbacall_default_discount'] ?? 10;
        $expiryDays = $sugar_config['verbacall_expiry_days'] ?? 7;

        $this->displayPaymentUI($lead, $plans, $defaultDiscount, $expiryDays);
    }

    private function createDiscountOffer($lead)
    {
        // Clear ALL output buffers to prevent contamination from other modules
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Prevent any further output from other modules
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        global $current_user;

        $planId = $_POST['plan_id'] ?? '';
        $discount = floatval($_POST['discount'] ?? 10);
        $expiryDays = intval($_POST['expiry_days'] ?? 7);

        if (empty($planId)) {
            echo json_encode(['success' => false, 'error' => 'Please select a plan']);
            exit;
        }

        if (empty($lead->email1)) {
            echo json_encode(['success' => false, 'error' => 'Lead has no email address']);
            exit;
        }

        if ($discount < 0 || $discount > 100) {
            echo json_encode(['success' => false, 'error' => 'Discount must be between 0 and 100']);
            exit;
        }

        try {
            $client = new VerbacallClient();

            // Get current user's email for tracking
            $createdBy = !empty($current_user->email1) ? $current_user->email1 : null;

            $result = $client->createDiscountOffer(
                $lead->email1,
                $planId,
                $discount,
                $lead->id,
                $expiryDays,
                $createdBy
            );

            // Log to Lead Journey
            $this->logTouchpoint($lead->id, 'verbacall_discount_offer', [
                'plan_id' => $planId,
                'discount_percentage' => $discount,
                'expiry_days' => $expiryDays,
                'offer_id' => $result['id'] ?? null,
                'discount_url' => $result['discountUrl'] ?? null,
                'created_by' => $createdBy
            ]);

            $GLOBALS['log']->info("VerbacallIntegration: Discount offer created for lead {$lead->id}, plan {$planId}, {$discount}% off");

            echo json_encode([
                'success' => true,
                'discountUrl' => $result['discountUrl'] ?? '',
                'offerId' => $result['id'] ?? '',
                'expiresAt' => $result['expiresAt'] ?? ''
            ]);
        } catch (Exception $e) {
            $GLOBALS['log']->error("VerbacallIntegration: Failed to create discount offer - " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
            $journey->name = 'Verbacall Payment Link Generated';
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

    private function sendPaymentEmail($lead)
    {
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

        $fromEmail = $sugar_config['notify_fromaddress'] ?? 'noreply@boomershub.com';
        $fromName = $sugar_config['notify_fromname'] ?? 'Boomers Hub';

        $leadName = trim($lead->first_name . ' ' . $lead->last_name);
        if (empty($leadName)) {
            $leadName = 'there';
        }

        $subject = $_POST['subject'] ?? 'Special Offer: Exclusive Discount on Verbacall';
        $body = $_POST['body'] ?? '';
        $discountUrl = $_POST['discount_url'] ?? '';

        if (empty($body)) {
            echo json_encode(['success' => false, 'message' => 'Email body is required']);
            exit;
        }

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
                // Log to Lead Journey
                $this->logTouchpoint($lead->id, 'verbacall_payment_email_sent', [
                    'discount_url' => $discountUrl,
                    'sent_by' => $current_user->id,
                    'sent_to' => $lead->email1,
                    'subject' => $subject
                ]);

                $GLOBALS['log']->info("VerbacallIntegration: Payment link email sent to {$lead->email1} for lead {$lead->id}");

                echo json_encode(['success' => true, 'message' => 'Email sent successfully!']);
            } else {
                $GLOBALS['log']->error("VerbacallIntegration: Failed to send payment email to {$lead->email1}");
                echo json_encode(['success' => false, 'message' => 'Failed to send email. Please check mail configuration.']);
            }
        } catch (Exception $e) {
            $GLOBALS['log']->error("VerbacallIntegration: Email exception - " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }

        exit;
    }

    private function displayPaymentUI($lead, $plans, $defaultDiscount, $expiryDays)
    {
        $leadName = htmlspecialchars(trim($lead->first_name . ' ' . $lead->last_name));
        $leadEmail = htmlspecialchars($lead->email1);

        // Build plans data as JSON for JavaScript
        $plansJson = json_encode($plans);

        // Build plans dropdown options with data attributes
        $planOptions = '<option value="">-- Select a Plan --</option>';
        foreach ($plans as $plan) {
            $monthlyPrice = floatval($plan['monthlyPrice'] ?? 0);
            $yearlyPrice = floatval($plan['yearlyPrice'] ?? 0);
            $planName = htmlspecialchars($plan['name'] ?? 'Unknown Plan');
            $planId = htmlspecialchars($plan['id'] ?? '');
            $planOptions .= "<option value=\"{$planId}\" data-monthly=\"{$monthlyPrice}\" data-yearly=\"{$yearlyPrice}\">{$planName}</option>";
        }

        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Verbacall Payment Link</title>
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
            max-width: 520px;
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
            border-bottom: 2px solid #fd7e14;
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
        .form-group { margin-bottom: 18px; }
        label {
            display: block;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }
        input[type="number"] {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            background-color: #fff;
            color: #333;
            font-size: 15px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        select#planId {
            width: 100%;
            height: 48px;
            padding: 12px 14px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            background: #ffffff !important;
            background-color: #ffffff !important;
            color: #000000 !important;
            -webkit-text-fill-color: #000000 !important;
            font-size: 15px !important;
            font-weight: 500 !important;
            cursor: pointer;
            opacity: 1 !important;
            text-indent: 0 !important;
            text-shadow: none !important;
            -webkit-appearance: menulist !important;
            -moz-appearance: menulist !important;
            appearance: menulist !important;
            line-height: 1.5 !important;
        }
        select#planId option {
            color: #000000 !important;
            background: #ffffff !important;
            background-color: #ffffff !important;
            -webkit-text-fill-color: #000000 !important;
            font-size: 15px !important;
            padding: 10px;
        }
        input[type="number"]:focus, select:focus {
            outline: none;
            border-color: #fd7e14;
            box-shadow: 0 0 0 4px rgba(253,126,20,0.15);
        }
        .billing-toggle {
            display: flex;
            gap: 0;
            margin-bottom: 18px;
        }
        .billing-toggle label {
            flex: 1;
            padding: 12px;
            text-align: center;
            border: 2px solid #dee2e6;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            color: #666;
            background: #f8f9fa;
            transition: all 0.2s;
            margin-bottom: 0;
        }
        .billing-toggle label:first-child {
            border-radius: 8px 0 0 8px;
            border-right: 1px solid #dee2e6;
        }
        .billing-toggle label:last-child {
            border-radius: 0 8px 8px 0;
            border-left: 1px solid #dee2e6;
        }
        .billing-toggle input { display: none; }
        .billing-toggle input:checked + label {
            background: #fd7e14;
            color: #fff;
            border-color: #fd7e14;
        }
        .price-summary {
            background: #fff8f0;
            border: 2px solid #fd7e14;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 18px;
        }
        .price-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .price-row:last-child { margin-bottom: 0; }
        .price-row.original { color: #666; }
        .price-row.original .amount { text-decoration: line-through; }
        .price-row.discount { color: #28a745; }
        .price-row.final {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            padding-top: 8px;
            border-top: 1px solid #ffd9b3;
        }
        .price-row .amount { font-weight: 600; }
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
            background: #fd7e14;
            color: #fff;
        }
        .btn-primary:hover { background: #e96b00; }
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
            background: #28a745;
            color: #fff;
        }
        .btn-copy:hover { background: #218838; }
        .result-box {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 16px;
            border-radius: 8px;
            margin-top: 16px;
            display: none;
        }
        .result-box.show { display: block; }
        .result-box p {
            color: #155724;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
        }
        .result-url {
            word-break: break-all;
            color: #0056b3;
            font-family: "SF Mono", Monaco, monospace;
            font-size: 12px;
            background: #fff;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 12px;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 6px;
            margin-top: 16px;
            text-align: center;
            font-size: 14px;
            border: 1px solid #f5c6cb;
        }
        .hidden { display: none; }
        .button-row {
            display: flex;
            gap: 10px;
        }
        .button-row .btn { margin-bottom: 0; }
        .form-row {
            display: flex;
            gap: 12px;
        }
        .form-row .form-group { flex: 1; }
        .email-composer {
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px solid #dee2e6;
        }
        .email-composer h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        .email-composer .form-group {
            margin-bottom: 14px;
        }
        .email-composer .form-group label {
            display: block;
            color: #555;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .email-composer input[type="text"],
        .email-composer textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            color: #333;
            background: #fff;
        }
        .email-composer input[type="text"]:focus,
        .email-composer textarea:focus {
            outline: none;
            border-color: #fd7e14;
            box-shadow: 0 0 0 3px rgba(253,126,20,0.15);
        }
        .email-composer textarea {
            resize: vertical;
            min-height: 150px;
            line-height: 1.5;
        }
        .status {
            padding: 12px;
            border-radius: 6px;
            margin-top: 12px;
            text-align: center;
            font-size: 14px;
            display: none;
        }
        .status.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; display: block; }
        .status.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; display: block; }
        .status.loading { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; display: block; }
    </style>
</head>
<body>
    <div class="container">
        <h1>💳 Generate Payment Link</h1>

        <div class="lead-info">
            <p><strong>Lead:</strong> {$leadName}</p>
            <p><strong>Email:</strong> {$leadEmail}</p>
        </div>

        <div class="form-group">
            <label for="planId">Select Plan</label>
            <select id="planId" onchange="updatePriceSummary()" style="color: #000 !important; -webkit-text-fill-color: #000 !important; background: #fff !important;">{$planOptions}</select>
        </div>

        <div class="billing-toggle">
            <input type="radio" name="billingCycle" id="monthly" value="monthly" checked onchange="updatePriceSummary()">
            <label for="monthly">Monthly</label>
            <input type="radio" name="billingCycle" id="yearly" value="yearly" onchange="updatePriceSummary()">
            <label for="yearly">Annual (Save!)</label>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="discount">Discount (%)</label>
                <input type="number" id="discount" value="{$defaultDiscount}" min="0" max="100" step="1" oninput="updatePriceSummary()">
            </div>
            <div class="form-group">
                <label for="expiryDays">Expires In (days)</label>
                <input type="number" id="expiryDays" value="{$expiryDays}" min="1" max="30">
            </div>
        </div>

        <div class="price-summary" id="priceSummary" style="display:none;">
            <div class="price-row original">
                <span>Original Price:</span>
                <span class="amount" id="originalPrice">$0.00</span>
            </div>
            <div class="price-row discount">
                <span>Discount (<span id="discountPct">0</span>%):</span>
                <span class="amount" id="discountAmount">-$0.00</span>
            </div>
            <div class="price-row final">
                <span>Final Price:</span>
                <span class="amount" id="finalPrice">$0.00</span>
            </div>
        </div>

        <div class="button-row">
            <button class="btn btn-primary" onclick="generateLink()" id="generateBtn">Generate Discount Link</button>
            <button class="btn btn-secondary" onclick="window.close()">Close</button>
        </div>

        <div class="result-box" id="result">
            <p>✅ Payment Link Generated:</p>
            <div class="result-url" id="resultUrl"></div>
            <div class="button-row" style="margin-top:12px;">
                <button class="btn btn-copy" onclick="copyUrl()">📋 Copy URL</button>
                <button class="btn btn-primary" onclick="showEmailComposer()">📧 Compose Email</button>
            </div>
        </div>

        <div class="email-composer" id="emailComposer" style="display:none;">
            <h3>📧 Email Preview</h3>
            <div class="form-group">
                <label for="emailSubject">Subject:</label>
                <input type="text" id="emailSubject" value="Special Offer: Exclusive Discount on Verbacall">
            </div>
            <div class="form-group">
                <label for="emailBody">Message:</label>
                <textarea id="emailBody" rows="12"></textarea>
            </div>
            <div class="button-row">
                <button class="btn btn-primary" onclick="sendEmail()" id="sendEmailBtn">📤 Send Email</button>
                <button class="btn btn-secondary" onclick="hideEmailComposer()">Cancel</button>
            </div>
            <div class="status" id="emailStatus"></div>
        </div>

        <div class="error hidden" id="error"></div>
    </div>

    <script>
    function updatePriceSummary() {
        var select = document.getElementById("planId");
        var selectedOption = select.options[select.selectedIndex];
        var priceSummary = document.getElementById("priceSummary");

        if (!selectedOption || !selectedOption.value) {
            priceSummary.style.display = "none";
            return;
        }

        var isYearly = document.getElementById("yearly").checked;
        var monthlyPrice = parseFloat(selectedOption.getAttribute("data-monthly")) || 0;
        var yearlyPrice = parseFloat(selectedOption.getAttribute("data-yearly")) || 0;
        var discount = parseFloat(document.getElementById("discount").value) || 0;

        var basePrice = isYearly ? yearlyPrice : monthlyPrice;
        var discountAmt = basePrice * (discount / 100);
        var finalPrice = basePrice - discountAmt;
        var period = isYearly ? "/year" : "/month";

        document.getElementById("originalPrice").textContent = "$" + basePrice.toFixed(2) + period;
        document.getElementById("discountPct").textContent = discount;
        document.getElementById("discountAmount").textContent = "-$" + discountAmt.toFixed(2);
        document.getElementById("finalPrice").textContent = "$" + finalPrice.toFixed(2) + period;

        priceSummary.style.display = "block";
    }

    function generateLink() {
        var btn = document.getElementById("generateBtn");
        var planSelect = document.getElementById("planId");

        if (!planSelect.value) {
            document.getElementById("error").textContent = "Please select a plan";
            document.getElementById("error").classList.remove("hidden");
            return;
        }

        btn.disabled = true;
        btn.textContent = "Generating...";

        document.getElementById("error").classList.add("hidden");
        document.getElementById("result").classList.remove("show");

        var isYearly = document.getElementById("yearly").checked;
        var data = new URLSearchParams();
        data.append("create_offer", "1");
        data.append("plan_id", planSelect.value);
        data.append("discount", document.getElementById("discount").value);
        data.append("expiry_days", document.getElementById("expiryDays").value);
        data.append("billing_cycle", isYearly ? "yearly" : "monthly");

        fetch(window.location.href, {
            method: "POST",
            headers: {
                "Accept": "application/json"
            },
            body: data,
            credentials: "same-origin"
        })
        .then(function(response) {
            return response.text().then(function(text) {
                var jsonMatch = text.match(/^\s*(\{[\s\S]*?\})\s*/);
                if (jsonMatch) {
                    try {
                        return JSON.parse(jsonMatch[1]);
                    } catch (e) {}
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error("Raw response:", text.substring(0, 500));
                    throw new Error("Invalid JSON response from server");
                }
            });
        })
        .then(function(data) {
            if (data.success) {
                document.getElementById("resultUrl").textContent = data.discountUrl;
                document.getElementById("result").classList.add("show");
            } else {
                document.getElementById("error").textContent = data.error || "Failed to generate link";
                document.getElementById("error").classList.remove("hidden");
            }
            btn.disabled = false;
            btn.textContent = "Generate Discount Link";
        })
        .catch(function(err) {
            document.getElementById("error").textContent = "Error: " + err.message;
            document.getElementById("error").classList.remove("hidden");
            btn.disabled = false;
            btn.textContent = "Generate Discount Link";
        });
    }

    function copyUrl() {
        var url = document.getElementById("resultUrl").textContent;
        navigator.clipboard.writeText(url).then(function() {
            alert("URL copied to clipboard!");
        }).catch(function() {
            var textArea = document.createElement("textarea");
            textArea.value = url;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand("copy");
            document.body.removeChild(textArea);
            alert("URL copied to clipboard!");
        });
    }

    var generatedUrl = "";
    var leadName = "{$leadName}";

    function showEmailComposer() {
        generatedUrl = document.getElementById("resultUrl").textContent;
        var select = document.getElementById("planId");
        var planName = select.options[select.selectedIndex].text;
        var discount = document.getElementById("discount").value;
        var expiryDays = document.getElementById("expiryDays").value;
        var finalPriceText = document.getElementById("finalPrice").textContent;

        var body = "Hello " + leadName + ",\\n\\n";
        body += "Great news! We've prepared an exclusive offer just for you.\\n\\n";
        body += "Plan: " + planName + "\\n";
        body += "Your Special Price: " + finalPriceText + " (" + discount + "% OFF!)\\n";
        body += "Offer Expires: " + expiryDays + " days\\n\\n";
        body += "Click below to claim your discount:\\n" + generatedUrl + "\\n\\n";
        body += "This exclusive link is created just for you and will expire soon.\\n\\n";
        body += "If you have any questions, please don't hesitate to reach out.\\n\\n";
        body += "Best regards";

        document.getElementById("emailBody").value = body;
        document.getElementById("emailComposer").style.display = "block";
    }

    function hideEmailComposer() {
        document.getElementById("emailComposer").style.display = "none";
    }

    function sendEmail() {
        var btn = document.getElementById("sendEmailBtn");
        var subject = document.getElementById("emailSubject").value;
        var body = document.getElementById("emailBody").value;

        btn.disabled = true;
        btn.textContent = "Sending...";
        showEmailStatus("loading", "Sending email...");

        var data = new URLSearchParams();
        data.append("send_email", "1");
        data.append("subject", subject);
        data.append("body", body);
        data.append("discount_url", generatedUrl);

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
                var jsonMatch = text.match(/^\\s*(\\{[\\s\\S]*?\\})\\s*/);
                if (jsonMatch) { try { return JSON.parse(jsonMatch[1]); } catch (e) {} }
                try { return JSON.parse(text); } catch (e) {
                    console.error("Raw response:", text.substring(0, 500));
                    throw new Error("Invalid JSON response");
                }
            });
        })
        .then(function(data) {
            showEmailStatus(data.success ? "success" : "error", data.success ? "✓ " + data.message : data.message || data.error);
            btn.disabled = false;
            btn.textContent = "📤 Send Email";
            if (data.success) setTimeout(hideEmailComposer, 2000);
        })
        .catch(function(err) {
            showEmailStatus("error", "Error: " + err.message);
            btn.disabled = false;
            btn.textContent = "📤 Send Email";
        });
    }

    function showEmailStatus(type, message) {
        var el = document.getElementById("emailStatus");
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
