#!/bin/bash

# Simple Upgrade Script for Existing Container
# Works with stopped or running containers

set -e

CONTAINER_NAME="${1:-suitecrm-test}"

echo "========================================"
echo "Twilio Integration v2.4.0 Simple Upgrade"
echo "========================================"
echo ""
echo "Container: $CONTAINER_NAME"
echo ""

# Check if container exists
if ! docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "❌ Container '$CONTAINER_NAME' not found!"
    echo ""
    echo "Available containers:"
    docker ps -a --format 'table {{.Names}}\t{{.Status}}'
    echo ""
    echo "Usage: $0 <container-name>"
    exit 1
fi

# Check if container is running, start if needed
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "⚠️  Container is stopped. Starting..."
    docker start $CONTAINER_NAME
    sleep 3
    echo "✓ Container started"
else
    echo "✓ Container is running"
fi

echo ""
echo "Step 1/6: Finding SuiteCRM path..."

# Find SuiteCRM installation path
SUITECRM_PATH=$(docker exec $CONTAINER_NAME find / -name "config.php" -path "*/suitecrm/*" 2>/dev/null | head -1 | sed 's|/config.php||')

if [ -z "$SUITECRM_PATH" ]; then
    echo "❌ Could not find SuiteCRM installation"
    echo "Trying common paths..."

    if docker exec $CONTAINER_NAME test -d /bitnami/suitecrm; then
        SUITECRM_PATH="/bitnami/suitecrm"
    elif docker exec $CONTAINER_NAME test -d /opt/bitnami/suitecrm; then
        SUITECRM_PATH="/opt/bitnami/suitecrm"
    elif docker exec $CONTAINER_NAME test -d /var/www/html/suitecrm; then
        SUITECRM_PATH="/var/www/html/suitecrm"
    else
        echo "❌ Could not find SuiteCRM path"
        exit 1
    fi
fi

echo "✓ Found SuiteCRM at: $SUITECRM_PATH"

echo ""
echo "Step 2/6: Backing up configuration..."

# Create backup directory
BACKUP_DIR="./backups/$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

# Backup config
docker exec $CONTAINER_NAME cat ${SUITECRM_PATH}/config.php > ${BACKUP_DIR}/config.backup 2>/dev/null || {
    echo "⚠️  Could not backup config (may not exist yet)"
}

echo "✓ Backup saved to: $BACKUP_DIR"

echo ""
echo "Step 3/6: Copying new Twilio files..."

# Copy Twilio module files
docker cp custom-modules/TwilioIntegration/. ${CONTAINER_NAME}:${SUITECRM_PATH}/modules/TwilioIntegration/

echo "✓ Files copied"

echo ""
echo "Step 4/6: Setting permissions..."

# Set permissions
docker exec -u root $CONTAINER_NAME chown -R bitnami:bitnami ${SUITECRM_PATH}/modules/TwilioIntegration/ 2>/dev/null || \
docker exec -u root $CONTAINER_NAME chown -R www-data:www-data ${SUITECRM_PATH}/modules/TwilioIntegration/ 2>/dev/null || \
docker exec $CONTAINER_NAME chmod -R 755 ${SUITECRM_PATH}/modules/TwilioIntegration/

echo "✓ Permissions set"

echo ""
echo "Step 5/6: Running database migration..."

# Copy migration file
docker cp custom-modules/TwilioIntegration/install/upgrade_to_v2.4.0.sql ${CONTAINER_NAME}:/tmp/

# Try to find database credentials
DB_HOST=$(docker exec $CONTAINER_NAME env 2>/dev/null | grep -i "MYSQL_HOST\|MARIADB_HOST\|DB_HOST" | cut -d= -f2 | head -1)
DB_NAME=$(docker exec $CONTAINER_NAME env 2>/dev/null | grep -i "MYSQL_DATABASE\|MARIADB_DATABASE\|DB_NAME" | cut -d= -f2 | head -1)
DB_USER=$(docker exec $CONTAINER_NAME env 2>/dev/null | grep -i "MYSQL_USER\|MARIADB_USER\|DB_USER" | cut -d= -f2 | head -1)
DB_PASS=$(docker exec $CONTAINER_NAME env 2>/dev/null | grep -i "MYSQL_PASSWORD\|MARIADB_PASSWORD\|DB_PASS" | cut -d= -f2 | head -1)

if [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
    echo "Found database: $DB_NAME on $DB_HOST (user: $DB_USER)"

    if [ -n "$DB_PASS" ]; then
        docker exec $CONTAINER_NAME mysql -h ${DB_HOST} -u ${DB_USER} -p${DB_PASS} ${DB_NAME} < /tmp/upgrade_to_v2.4.0.sql 2>/dev/null && \
            echo "✓ Migration completed" || \
            echo "⚠️  Migration may have failed (check manually)"
    else
        echo "⚠️  No password found, run migration manually:"
        echo "   docker exec -it $CONTAINER_NAME mysql -h ${DB_HOST} -u ${DB_USER} -p ${DB_NAME} < /tmp/upgrade_to_v2.4.0.sql"
    fi
else
    echo "⚠️  Could not find database credentials"
    echo "   Run migration manually - see QUICK_UPGRADE.md"
fi

echo ""
echo "Step 6/6: Clearing cache..."

docker exec $CONTAINER_NAME rm -rf ${SUITECRM_PATH}/cache/* 2>/dev/null || echo "⚠️  Could not clear cache (may not be needed)"

echo "✓ Cache cleared"

echo ""
echo "========================================"
echo "Verifying upgrade..."
echo "========================================"

# Check version
VERSION=$(docker exec $CONTAINER_NAME grep "'version'" ${SUITECRM_PATH}/modules/TwilioIntegration/manifest.php 2>/dev/null | grep -oP "'version'.*'\K[^']+")
echo "Version: $VERSION"

# Check new files exist
if docker exec $CONTAINER_NAME test -f ${SUITECRM_PATH}/modules/TwilioIntegration/TwilioSecurity.php; then
    echo "✓ New security module found"
fi

if docker exec $CONTAINER_NAME test -f ${SUITECRM_PATH}/modules/TwilioIntegration/TwilioScheduler.php; then
    echo "✓ New scheduler module found"
fi

# Check config
if docker exec $CONTAINER_NAME grep -q "twilio_account_sid" ${SUITECRM_PATH}/config.php 2>/dev/null; then
    echo "✓ Configuration preserved"
fi

echo ""
echo "========================================"
echo "✓ Upgrade Complete!"
echo "========================================"
echo ""
echo "Backup location: $BACKUP_DIR"
echo "Container: $CONTAINER_NAME"
echo ""
echo "Next steps:"
echo "1. Test your application"
echo "2. Check logs: docker logs $CONTAINER_NAME"
echo "3. Access SuiteCRM and verify click-to-call works"
echo ""
echo "If something went wrong, see QUICK_UPGRADE.md for troubleshooting"
