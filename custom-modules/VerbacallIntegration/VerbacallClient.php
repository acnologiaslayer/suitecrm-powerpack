<?php
/**
 * VerbacallClient - API client for Verbacall services
 *
 * Handles communication with Verbacall API for signup URLs, plans, and discount offers.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

class VerbacallClient
{
    private $apiUrl;
    private $timeout = 30;

    public function __construct()
    {
        global $sugar_config;

        $this->apiUrl = rtrim(
            getenv('VERBACALL_API_URL') ?:
            ($sugar_config['verbacall_api_url'] ?? 'https://app.verbacall.com'),
            '/'
        );
    }

    /**
     * Generate signup URL for a lead
     *
     * @param string $leadId SuiteCRM Lead ID
     * @param string $email Lead's email address
     * @return string The signup URL
     */
    public function generateSignupUrl($leadId, $email)
    {
        $params = http_build_query([
            'leadId' => $leadId,
            'email' => $email
        ]);

        return $this->apiUrl . '/auth/register?' . $params;
    }

    /**
     * Fetch available plans from Verbacall
     * GET /api/public/plans
     *
     * @return array List of plans
     * @throws Exception on API error
     */
    public function getPlans()
    {
        $url = $this->apiUrl . '/api/public/plans';

        $response = $this->makeRequest($url, null, 'GET');

        return $response;
    }

    /**
     * Get a single plan by ID
     * GET /api/public/plans/:id
     *
     * @param int $planId Plan ID
     * @return array Plan details
     * @throws Exception on API error
     */
    public function getPlan($planId)
    {
        $url = $this->apiUrl . '/api/public/plans/' . intval($planId);

        $response = $this->makeRequest($url, null, 'GET');

        return $response;
    }

    /**
     * Create a discount offer
     * POST /api/discount-offers
     *
     * @param string $email Customer email
     * @param int $planId Plan ID
     * @param float $discountPercentage Discount percentage (1-100)
     * @param string $suitecrmLeadId SuiteCRM Lead ID for tracking
     * @param int $expiryDays Days until offer expires (default: 7)
     * @param string|null $createdBy BDR email who created it
     * @return array Discount offer details including discountUrl
     * @throws Exception on API error
     */
    public function createDiscountOffer($email, $planId, $discountPercentage, $suitecrmLeadId, $expiryDays = 7, $createdBy = null)
    {
        $url = $this->apiUrl . '/api/discount-offers';

        $payload = [
            'email' => $email,
            'planId' => intval($planId),
            'discountPercentage' => floatval($discountPercentage),
            'suitecrmLeadId' => $suitecrmLeadId,
            'expiryDays' => intval($expiryDays)
        ];

        if ($createdBy) {
            $payload['createdBy'] = $createdBy;
        }

        $response = $this->makeRequest($url, $payload, 'POST');

        return $response;
    }

    /**
     * Validate a discount offer token
     * GET /api/discount-offers/validate/:token
     *
     * @param string $token Offer token
     * @return array Validation result
     * @throws Exception on API error
     */
    public function validateOffer($token)
    {
        $url = $this->apiUrl . '/api/discount-offers/validate/' . urlencode($token);

        $response = $this->makeRequest($url, null, 'GET');

        return $response;
    }

    /**
     * Test API connection
     *
     * @return bool True if connection successful
     * @throws Exception on connection failure
     */
    public function testConnection()
    {
        try {
            $plans = $this->getPlans();
            return is_array($plans);
        } catch (Exception $e) {
            throw new Exception('Connection test failed: ' . $e->getMessage());
        }
    }

    /**
     * Make HTTP request to Verbacall API
     *
     * @param string $url API endpoint URL
     * @param array|null $data Request data (for POST)
     * @param string $method HTTP method
     * @return array Decoded response
     * @throws Exception on error
     */
    private function makeRequest($url, $data = null, $method = 'GET')
    {
        $ch = curl_init();

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json'
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        // Log the request
        $GLOBALS['log']->debug("VerbacallClient: $method $url - HTTP $httpCode");

        if ($errno) {
            $GLOBALS['log']->error("VerbacallClient: cURL error ($errno): $error");
            throw new Exception("Connection error: $error");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMsg = isset($decoded['message']) ? $decoded['message'] :
                       (isset($decoded['error']) ? $decoded['error'] : "HTTP $httpCode");
            $GLOBALS['log']->error("VerbacallClient: API error - $errorMsg");
            throw new Exception("Verbacall API error: $errorMsg");
        }

        return $decoded;
    }

    /**
     * Get API base URL
     *
     * @return string
     */
    public function getApiUrl()
    {
        return $this->apiUrl;
    }
}
