<?php
/**
 * Notification Security - Authentication and Rate Limiting
 *
 * Handles API key validation, HMAC signature verification, and rate limiting
 * for the notification webhook endpoint.
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

class NotificationSecurity
{
    private $rateLimitWindow = 60;   // seconds
    private $rateLimitMax = 100;     // requests per window

    /**
     * Detect authentication method from request headers
     */
    public function detectAuthMethod(): string
    {
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return 'api_key';
        }
        if (!empty($_SERVER['HTTP_X_SIGNATURE'])) {
            return 'hmac';
        }
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
            if (stripos($auth, 'Bearer ') === 0) {
                return 'bearer';
            }
        }
        return 'none';
    }

    /**
     * Validate authentication based on detected method
     */
    public function validateAuth(string $method): bool
    {
        switch ($method) {
            case 'api_key':
                return $this->validateApiKey();
            case 'hmac':
                return $this->validateHmacSignature();
            case 'bearer':
                return $this->validateBearerToken();
            default:
                return false;
        }
    }

    /**
     * Validate API key authentication
     */
    private function validateApiKey(): bool
    {
        $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (empty($providedKey)) {
            return false;
        }

        $validKeys = $this->getValidApiKeys();

        foreach ($validKeys as $keyData) {
            if (hash_equals($keyData['key'], $providedKey)) {
                $this->logAccess($keyData['name'], 'api_key');
                $this->updateLastUsed($keyData['id']);
                return true;
            }
        }

        return false;
    }

    /**
     * Validate HMAC signature authentication
     */
    private function validateHmacSignature(): bool
    {
        global $sugar_config;

        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
        $secret = $sugar_config['notification_webhook_secret'] ?? '';

        if (empty($secret) || empty($signature) || empty($timestamp)) {
            return false;
        }

        // Prevent replay attacks (5 minute window)
        $timestampInt = intval($timestamp);
        if (abs(time() - $timestampInt) > 300) {
            $GLOBALS['log']->warn("Notification Webhook: Timestamp outside allowed window");
            return false;
        }

        // Calculate expected signature
        $body = file_get_contents('php://input');
        $expectedSignature = hash_hmac('sha256', $timestamp . $body, $secret);

        if (hash_equals($expectedSignature, $signature)) {
            $this->logAccess('HMAC', 'hmac');
            return true;
        }

        return false;
    }

    /**
     * Validate Bearer token (for internal use/future OAuth2)
     */
    private function validateBearerToken(): bool
    {
        global $sugar_config;

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($authHeader, 'Bearer ') !== 0) {
            return false;
        }

        $token = substr($authHeader, 7);
        $secret = $sugar_config['notification_jwt_secret'] ?? '';

        if (empty($secret) || empty($token)) {
            return false;
        }

        // Simple JWT validation (for internal requests)
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        list($header, $payload, $sig) = $parts;
        $expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true)), '+/', '-_'), '=');

        if (hash_equals($expectedSig, $sig)) {
            $payloadData = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

            // Check expiration
            if (isset($payloadData['exp']) && $payloadData['exp'] < time()) {
                return false;
            }

            $this->logAccess('JWT', 'bearer');
            return true;
        }

        return false;
    }

    /**
     * Get valid API keys from database
     */
    private function getValidApiKeys(): array
    {
        global $db;

        $sql = "SELECT id, name, api_key as `key` FROM notification_api_keys
                WHERE is_active = 1 AND deleted = 0";
        $result = $db->query($sql);

        $keys = [];
        while ($row = $db->fetchByAssoc($result)) {
            $keys[] = $row;
        }

        return $keys;
    }

    /**
     * Update last_used_at timestamp for API key
     */
    private function updateLastUsed(string $keyId): void
    {
        global $db;

        $sql = "UPDATE notification_api_keys SET last_used_at = NOW() WHERE id = '" . $db->quote($keyId) . "'";
        $db->query($sql);
    }

    /**
     * Check rate limit for IP address
     */
    public function checkRateLimit(string $ip): bool
    {
        global $db;

        $window = time() - $this->rateLimitWindow;
        $ipSafe = $db->quote($ip);

        $sql = "SELECT COUNT(*) as count FROM notification_rate_limit
                WHERE ip_address = '$ipSafe'
                AND created_at > FROM_UNIXTIME($window)";
        $result = $db->query($sql);
        $row = $db->fetchByAssoc($result);

        if ($row && intval($row['count']) >= $this->rateLimitMax) {
            $GLOBALS['log']->warn("Notification Webhook: Rate limit exceeded for IP $ip");
            return false;
        }

        // Log this request for rate limiting
        $sql = "INSERT INTO notification_rate_limit (ip_address, created_at)
                VALUES ('$ipSafe', NOW())";
        $db->query($sql);

        // Clean up old entries periodically (1% chance)
        if (rand(1, 100) === 1) {
            $this->cleanupRateLimitTable();
        }

        return true;
    }

    /**
     * Clean up old rate limit entries
     */
    private function cleanupRateLimitTable(): void
    {
        global $db;

        $sql = "DELETE FROM notification_rate_limit WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $db->query($sql);
    }

    /**
     * Log access for audit trail
     */
    private function logAccess(string $keyName, string $method): void
    {
        $ip = $this->getClientIp();
        $GLOBALS['log']->info("Notification Webhook accessed via $method: $keyName from $ip");
    }

    /**
     * Get client IP address (handles proxies)
     */
    public function getClientIp(): string
    {
        // Cloudflare
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        // X-Forwarded-For (first IP in chain)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }

        // X-Real-IP
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }

        // Direct connection
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Generate a new API key
     */
    public static function generateApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Create a JWT token for WebSocket authentication
     */
    public static function createJwtToken(string $userId, int $expiresIn = 3600): string
    {
        global $sugar_config;

        $secret = $sugar_config['notification_jwt_secret'] ?? 'default-secret-change-me';

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'userId' => $userId,
            'iat' => time(),
            'exp' => time() + $expiresIn
        ]);

        $base64Header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $base64Payload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        $signature = hash_hmac('sha256', "$base64Header.$base64Payload", $secret, true);
        $base64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return "$base64Header.$base64Payload.$base64Signature";
    }

    /**
     * Validate a JWT token and extract user ID
     */
    public static function validateJwtToken(string $token): ?string
    {
        global $sugar_config;

        $secret = $sugar_config['notification_jwt_secret'] ?? 'default-secret-change-me';

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($header, $payload, $sig) = $parts;

        $expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true)), '+/', '-_'), '=');

        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }

        $payloadData = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        if (!$payloadData || !isset($payloadData['userId'])) {
            return null;
        }

        // Check expiration
        if (isset($payloadData['exp']) && $payloadData['exp'] < time()) {
            return null;
        }

        return $payloadData['userId'];
    }
}
