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
        header('Content-Type: application/json');

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

    private function displayPaymentUI($lead, $plans, $defaultDiscount, $expiryDays)
    {
        $leadName = htmlspecialchars(trim($lead->first_name . ' ' . $lead->last_name));
        $leadEmail = htmlspecialchars($lead->email1);

        // Build plans dropdown options
        $planOptions = '';
        foreach ($plans as $plan) {
            $price = number_format($plan['monthlyPrice'] ?? 0, 2);
            $planName = htmlspecialchars($plan['name'] ?? 'Unknown Plan');
            $planId = htmlspecialchars($plan['id'] ?? '');
            $planOptions .= "<option value=\"{$planId}\">{$planName} - \${$price}/mo</option>";
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
        .form-group { margin-bottom: 18px; }
        label {
            display: block;
            color: rgba(255,255,255,0.7);
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 500;
        }
        select, input {
            width: 100%;
            padding: 12px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            background: rgba(0,0,0,0.3);
            color: #fff;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        select:focus, input:focus {
            outline: none;
            border-color: #f39c12;
        }
        select option {
            background: #1a1a2e;
            color: #fff;
        }
        .btn {
            width: 100%;
            padding: 14px;
            font-size: 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            margin-bottom: 10px;
            font-weight: 500;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .btn-primary {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: #fff;
        }
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        .result-box {
            background: rgba(78,204,163,0.1);
            border: 1px solid #4ecca3;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            display: none;
        }
        .result-box.show { display: block; }
        .result-box p {
            color: #fff;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .result-url {
            word-break: break-all;
            color: #4ecca3;
            font-family: monospace;
            font-size: 12px;
            background: rgba(0,0,0,0.3);
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        .error {
            background: rgba(220,53,69,0.2);
            color: #ff6b6b;
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
            text-align: center;
            font-size: 14px;
        }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Generate Payment Link</h1>

        <div class="lead-info">
            <p><strong>Lead:</strong> {$leadName}</p>
            <p><strong>Email:</strong> {$leadEmail}</p>
        </div>

        <div class="form-group">
            <label>Select Plan</label>
            <select id="planId">{$planOptions}</select>
        </div>

        <div class="form-group">
            <label>Discount Percentage (%)</label>
            <input type="number" id="discount" value="{$defaultDiscount}" min="0" max="100" step="1">
        </div>

        <div class="form-group">
            <label>Offer Expires In (days)</label>
            <input type="number" id="expiryDays" value="{$expiryDays}" min="1" max="30">
        </div>

        <button class="btn btn-primary" onclick="generateLink()" id="generateBtn">Generate Discount Link</button>
        <button class="btn btn-secondary" onclick="window.close()">Close</button>

        <div class="result-box" id="result">
            <p><strong>Payment Link:</strong></p>
            <div class="result-url" id="resultUrl"></div>
            <button class="btn btn-secondary" onclick="copyUrl()">Copy URL</button>
        </div>

        <div class="error hidden" id="error"></div>
    </div>

    <script>
    function generateLink() {
        var btn = document.getElementById("generateBtn");
        btn.disabled = true;
        btn.textContent = "Generating...";

        document.getElementById("error").classList.add("hidden");
        document.getElementById("result").classList.remove("show");

        var data = new URLSearchParams();
        data.append("create_offer", "1");
        data.append("plan_id", document.getElementById("planId").value);
        data.append("discount", document.getElementById("discount").value);
        data.append("expiry_days", document.getElementById("expiryDays").value);

        fetch(window.location.href, {
            method: "POST",
            body: data
        })
        .then(function(r) { return r.json(); })
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
    <p>{$escapedMessage}</p>
    <button onclick="window.close()">Close</button>
</body>
</html>
HTML;
        exit;
    }
}
