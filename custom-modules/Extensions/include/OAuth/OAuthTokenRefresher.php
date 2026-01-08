<?php
/**
 * OAuthTokenRefresher - Automatically refreshes OAuth tokens before they expire
 */

if (!defined("sugarEntry") || !sugarEntry) {
    die("Not A Valid Entry Point");
}

require_once("modules/ExternalOAuthConnection/ExternalOAuthConnection.php");
require_once("modules/ExternalOAuthProvider/ExternalOAuthProvider.php");

class OAuthTokenRefresher
{
    /**
     * Refresh buffer time in seconds (refresh 5 minutes before expiry)
     */
    const REFRESH_BUFFER = 300;

    /**
     * Check if token needs refresh and refresh it if necessary
     *
     * @param string $connectionId The OAuth connection ID
     * @return bool True if token is valid (refreshed or still valid), false on failure
     */
    public static function ensureValidToken($connectionId)
    {
        global $log;

        if (empty($connectionId)) {
            if ($log) $log->error("OAuthTokenRefresher: No connection ID provided");
            return false;
        }

        // Get the connection bean
        $connection = BeanFactory::getBean("ExternalOAuthConnection", $connectionId);

        if (!$connection || !$connection->id) {
            if ($log) $log->error("OAuthTokenRefresher: Connection not found - " . $connectionId);
            return false;
        }

        // Check if token needs refresh
        $expiresAt = (int)$connection->expires_in;
        $now = time();
        $refreshBuffer = self::REFRESH_BUFFER;

        if ($log) $log->info("OAuthTokenRefresher: Token for " . $connection->name . " expires at " . date("Y-m-d H:i:s", $expiresAt) . ", now is " . date("Y-m-d H:i:s", $now));

        // If token is still valid with buffer time, no refresh needed
        if ($expiresAt > ($now + $refreshBuffer)) {
            if ($log) $log->info("OAuthTokenRefresher: Token still valid, no refresh needed");
            return true;
        }

        // Token expired or about to expire, refresh it
        if ($log) $log->info("OAuthTokenRefresher: Token expired or expiring soon, refreshing...");

        return self::refreshToken($connection);
    }

    /**
     * Refresh the OAuth token
     *
     * @param ExternalOAuthConnection $connection
     * @return bool True on success, false on failure
     */
    public static function refreshToken($connection)
    {
        global $log;

        $refreshToken = $connection->refresh_token;

        if (empty($refreshToken)) {
            if ($log) $log->error("OAuthTokenRefresher: No refresh token available for connection " . $connection->id);
            return false;
        }

        $providerId = $connection->external_oauth_provider_id;

        if (empty($providerId)) {
            if ($log) $log->error("OAuthTokenRefresher: No provider ID for connection " . $connection->id);
            return false;
        }

        // Get the provider
        $provider = BeanFactory::getBean("ExternalOAuthProvider", $providerId);

        if (!$provider || !$provider->id) {
            if ($log) $log->error("OAuthTokenRefresher: Provider not found - " . $providerId);
            return false;
        }

        // Get the connector class
        $connectorClass = self::getConnectorClass($provider->connector);

        if (!$connectorClass) {
            if ($log) $log->error("OAuthTokenRefresher: Could not load connector class for " . $provider->connector);
            return false;
        }

        try {
            $connector = new $connectorClass($providerId);

            // Refresh the token
            $newToken = $connector->refreshAccessToken($refreshToken);

            if (!$newToken) {
                if ($log) $log->error("OAuthTokenRefresher: Failed to refresh token - null response");
                return false;
            }

            // Map the new token
            $mappedToken = $connector->mapAccessToken($newToken);

            if (empty($mappedToken) || empty($mappedToken["access_token"])) {
                if ($log) $log->error("OAuthTokenRefresher: Failed to map refreshed token");
                return false;
            }

            // Update the connection with new token data
            $connection->access_token = $mappedToken["access_token"];
            $connection->token_type = $mappedToken["token_type"] ?? "Bearer";

            // expires_in from League OAuth2 getExpires() is already a Unix timestamp
            // but just in case its a duration, check and convert
            $expiresIn = $mappedToken["expires_in"];
            $now = time();

            if ($log) $log->info("OAuthTokenRefresher: Raw expires_in value: " . $expiresIn);

            // If expires_in is within reasonable range of now (e.g., within 1 year from now),
            // its likely already a timestamp. Otherwise treat as duration.
            $oneYearFromNow = $now + (365 * 24 * 60 * 60);
            $oneYearAgo = $now - (365 * 24 * 60 * 60);

            if ($expiresIn > $oneYearAgo && $expiresIn < $oneYearFromNow) {
                // Looks like a timestamp already
                $connection->expires_in = $expiresIn;
                if ($log) $log->info("OAuthTokenRefresher: Treating expires_in as timestamp: " . date("Y-m-d H:i:s", $expiresIn));
            } else if ($expiresIn < 86400 * 30) {
                // Small number, likely a duration in seconds (less than 30 days)
                $connection->expires_in = $now + $expiresIn;
                if ($log) $log->info("OAuthTokenRefresher: Treating expires_in as duration, new expiry: " . date("Y-m-d H:i:s", $connection->expires_in));
            } else {
                // Default: treat as timestamp
                $connection->expires_in = $expiresIn;
                if ($log) $log->info("OAuthTokenRefresher: Default treating expires_in as timestamp: " . date("Y-m-d H:i:s", $expiresIn));
            }

            // Update refresh token if a new one was provided
            if (!empty($mappedToken["refresh_token"])) {
                $connection->refresh_token = $mappedToken["refresh_token"];
            }

            // Save the connection
            $connection->save();

            if ($log) $log->info("OAuthTokenRefresher: Successfully refreshed token for connection " . $connection->id . ", new expiry: " . date("Y-m-d H:i:s", $connection->expires_in));

            return true;

        } catch (Exception $e) {
            if ($log) $log->error("OAuthTokenRefresher: Exception during token refresh - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the connector class for a given connector type
     *
     * @param string $connectorType
     * @return string|null
     */
    private static function getConnectorClass($connectorType)
    {
        $connectorMap = [
            "Microsoft" => "MicrosoftOAuthProviderConnector",
            "Google" => "GoogleOAuthProviderConnector",
            "Generic" => "GenericOAuthProviderConnector",
        ];

        $className = $connectorMap[$connectorType] ?? "GenericOAuthProviderConnector";

        // Try to load the connector class
        $paths = [
            "modules/ExternalOAuthConnection/provider/{$connectorType}/{$className}.php",
            "modules/ExternalOAuthConnection/provider/Generic/GenericOAuthProviderConnector.php",
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                require_once($path);
                if (class_exists($className)) {
                    return $className;
                }
            }
        }

        // Fallback to generic
        $genericPath = "modules/ExternalOAuthConnection/provider/Generic/GenericOAuthProviderConnector.php";
        if (file_exists($genericPath)) {
            require_once($genericPath);
            return "GenericOAuthProviderConnector";
        }

        return null;
    }

    /**
     * Refresh all tokens that are about to expire
     * Can be called from a scheduler job
     *
     * @return array Results for each connection
     */
    public static function refreshAllExpiring()
    {
        global $db, $log;

        $results = [];
        $now = time();
        $threshold = $now + self::REFRESH_BUFFER;

        // Find all connections with tokens about to expire
        $query = "SELECT id, name FROM external_oauth_connection
                  WHERE deleted = 0
                  AND refresh_token IS NOT NULL
                  AND refresh_token != ''
                  AND (expires_in < $threshold OR expires_in IS NULL)";

        $result = $db->query($query);

        while ($row = $db->fetchByAssoc($result)) {
            if ($log) $log->info("OAuthTokenRefresher: Checking connection " . $row["name"] . " (ID: " . $row["id"] . ")");

            $success = self::ensureValidToken($row["id"]);
            $results[$row["id"]] = [
                "name" => $row["name"],
                "success" => $success,
            ];
        }

        return $results;
    }
}
