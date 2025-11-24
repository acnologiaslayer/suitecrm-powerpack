#!/bin/bash
set -e

echo "Running SuiteCRM silent installation..."

cd /bitnami/suitecrm

# Required environment variables
DB_HOST="${SUITECRM_DATABASE_HOST}"
DB_PORT="${SUITECRM_DATABASE_PORT_NUMBER:-3306}"
DB_NAME="${SUITECRM_DATABASE_NAME}"
DB_USER="${SUITECRM_DATABASE_USER}"
DB_PASSWORD="${SUITECRM_DATABASE_PASSWORD}"
SITE_URL="${SUITECRM_SITE_URL:-http://localhost}"
ADMIN_USER="${SUITECRM_USERNAME:-admin}"
ADMIN_PASSWORD="${SUITECRM_PASSWORD:-admin}"

# Validate required variables
if [ -z "$DB_HOST" ] || [ -z "$DB_NAME" ] || [ -z "$DB_USER" ] || [ -z "$DB_PASSWORD" ]; then
    echo "ERROR: Missing required database environment variables"
    echo "Required: SUITECRM_DATABASE_HOST, SUITECRM_DATABASE_NAME, SUITECRM_DATABASE_USER, SUITECRM_DATABASE_PASSWORD"
    exit 1
fi

# Ensure proper permissions
chown -R daemon:daemon /bitnami/suitecrm
chmod 777 /opt/bitnami/php/var/run/session 2>/dev/null || true

echo "Running console installer with 10 minute timeout..."

# Use the SuiteCRM console installer with timeout
OUTPUT=$(timeout 600 su -s /bin/bash daemon -c "cd /bitnami/suitecrm && php bin/console suitecrm:app:install \
  --db_username='$DB_USER' \
  --db_password='$DB_PASSWORD' \
  --db_host='$DB_HOST' \
  --db_port='$DB_PORT' \
  --db_name='$DB_NAME' \
  --site_username='$ADMIN_USER' \
  --site_password='$ADMIN_PASSWORD' \
  --site_host='$SITE_URL' \
  --demoData=no 2>&1" || echo "Installer completed or timed out")
INSTALL_STATUS=$?

echo "$OUTPUT"

# Check for config.php in both locations (root and legacy)
if [ $INSTALL_STATUS -eq 0 ] && { [ -f "/bitnami/suitecrm/config.php" ] || [ -f "/bitnami/suitecrm/public/legacy/config.php" ]; }; then
    echo "✅ SuiteCRM silent installation completed successfully!"
    
    # Clear cache and sessions
    echo "Clearing cache and sessions..."
    rm -rf /bitnami/suitecrm/cache/* 2>/dev/null || true
    rm -rf /bitnami/suitecrm/public/legacy/cache/* 2>/dev/null || true
    find /bitnami/suitecrm -type d -name "cache" -exec rm -rf {}/* \; 2>/dev/null || true
    
    # Apply Twilio configuration from environment
    if [ -f "/bitnami/suitecrm/config_override.php.template" ]; then
        echo "Applying Twilio configuration..."
        cp /bitnami/suitecrm/config_override.php.template /bitnami/suitecrm/config_override.php
        chown daemon:daemon /bitnami/suitecrm/config_override.php
    fi
    
    # Set proper file permissions
    chown -R daemon:daemon /bitnami/suitecrm
    chmod -R 755 /bitnami/suitecrm
    chmod 777 /opt/bitnami/php/var/run/session 2>/dev/null || true
    
    exit 0
else
    echo "❌ SuiteCRM installation failed"
    echo "Installation output:"
    echo "$OUTPUT"
    exit 1
fi
