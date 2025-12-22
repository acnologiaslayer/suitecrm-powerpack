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

echo "Running console installer in background..."

# Run installer in background and monitor
su -s /bin/bash daemon -c "cd /bitnami/suitecrm && php bin/console suitecrm:app:install \
  --db_username=\"$DB_USER\" \
  --db_password=\"$DB_PASSWORD\" \
  --db_host=\"$DB_HOST\" \
  --db_port=\"$DB_PORT\" \
  --db_name=\"$DB_NAME\" \
  --site_username=\"$ADMIN_USER\" \
  --site_password=\"$ADMIN_PASSWORD\" \
  --site_host=\"$SITE_URL\" \
  --demoData=no" > /tmp/install.log 2>&1 &

INSTALLER_PID=$!
echo "Installer running with PID: $INSTALLER_PID"

# Wait up to 15 minutes for installation to complete
MAX_WAIT=900
ELAPSED=0
while [ $ELAPSED -lt $MAX_WAIT ]; do
    # Check if config exists and has database tables
    if [ -f "/bitnami/suitecrm/public/legacy/config.php" ]; then
        # Check if installer process is still running
        if ! kill -0 $INSTALLER_PID 2>/dev/null; then
            echo "Installer process completed"
            cat /tmp/install.log
            echo "✅ SuiteCRM silent installation completed successfully!"
            break
        fi
    fi
    
    sleep 10
    ELAPSED=$((ELAPSED + 10))
    echo "Waiting for installation... ($ELAPSED seconds elapsed)"
done

# If we hit the timeout, kill the installer
if [ $ELAPSED -ge $MAX_WAIT ]; then
    echo "⚠️ Installation taking longer than 15 minutes, continuing anyway..."
    kill $INSTALLER_PID 2>/dev/null || true
    cat /tmp/install.log
fi

# Final check for config file
if [ -f "/bitnami/suitecrm/config.php" ] || [ -f "/bitnami/suitecrm/public/legacy/config.php" ]; then
    echo "✅ SuiteCRM configuration file created!"
    
    # Clear cache and sessions
    echo "Clearing cache and sessions..."
    rm -rf /bitnami/suitecrm/cache/* 2>/dev/null || true
    rm -rf /bitnami/suitecrm/public/legacy/cache/* 2>/dev/null || true
    find /bitnami/suitecrm -type d -name "cache" -exec rm -rf {}/* \; 2>/dev/null || true
    
    # Apply Twilio configuration from environment (only on first install, don't overwrite existing)
    if [ -f "/bitnami/suitecrm/config_override.php.template" ] && [ ! -f "/bitnami/suitecrm/public/legacy/config_override.php" ]; then
        echo "Applying PowerPack configuration..."
        cp /bitnami/suitecrm/config_override.php.template /bitnami/suitecrm/public/legacy/config_override.php
        chown daemon:daemon /bitnami/suitecrm/public/legacy/config_override.php
    else
        echo "Config override already exists, skipping to preserve settings..."
    fi
    
    # Set proper file permissions
    chown -R daemon:daemon /bitnami/suitecrm
    chmod -R 755 /bitnami/suitecrm
    chmod 777 /opt/bitnami/php/var/run/session 2>/dev/null || true
    
    exit 0
else
    echo "⚠️ SuiteCRM configuration file not found, installation may have failed"
    cat /tmp/install.log 2>/dev/null || true
    exit 1
fi
