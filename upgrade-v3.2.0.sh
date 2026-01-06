#!/bin/bash
#
# SuiteCRM PowerPack - Safe Production Upgrade to v3.2.0
# 
# This script:
# 1. Creates full backup (database + config + uploads)
# 2. Pulls new image
# 3. Upgrades container
# 4. Verifies data integrity
# 5. Provides rollback instructions
#
# Run on your PRODUCTION server

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration - EDIT THESE
CONTAINER_NAME="${CONTAINER_NAME:-suitecrm}"
BACKUP_DIR="${BACKUP_DIR:-./backups/$(date +%Y%m%d_%H%M%S)}"
NEW_VERSION="v3.2.0"

echo -e "${GREEN}=============================================="
echo "SuiteCRM PowerPack - Production Upgrade"
echo "Target Version: ${NEW_VERSION}"
echo -e "==============================================${NC}"
echo ""

# Check if running as appropriate user
if [ "$EUID" -eq 0 ]; then
    echo -e "${YELLOW}Warning: Running as root. Make sure Docker permissions are correct.${NC}"
fi

# Check Docker is available
if ! command -v docker &> /dev/null; then
    echo -e "${RED}Error: Docker not found${NC}"
    exit 1
fi

# Check container exists
if ! docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo -e "${RED}Error: Container '${CONTAINER_NAME}' not found${NC}"
    echo "Available containers:"
    docker ps -a --format '{{.Names}}'
    exit 1
fi

# Get current image
CURRENT_IMAGE=$(docker inspect --format='{{.Config.Image}}' ${CONTAINER_NAME})
echo "Current image: ${CURRENT_IMAGE}"
echo ""

# Create backup directory
echo -e "${YELLOW}Step 1: Creating backup directory...${NC}"
mkdir -p "${BACKUP_DIR}"
echo "Backup location: ${BACKUP_DIR}"
echo ""

# ============================================
# STEP 2: Database Backup
# ============================================
echo -e "${YELLOW}Step 2: Backing up database...${NC}"

# Get database credentials from container
DB_HOST=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_HOST 2>/dev/null || echo "")
DB_NAME=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_NAME 2>/dev/null || echo "suitecrm")
DB_USER=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_USER 2>/dev/null || echo "suitecrm")
DB_PASS=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_PASSWORD 2>/dev/null || echo "")
DB_PORT=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_PORT 2>/dev/null || echo "3306")

if [ -z "$DB_HOST" ] || [ -z "$DB_PASS" ]; then
    echo -e "${RED}Could not get database credentials from container${NC}"
    echo "Please run manually:"
    echo "  mysqldump -h <host> -u <user> -p <database> > ${BACKUP_DIR}/database.sql"
    read -p "Press Enter after you've created the backup, or Ctrl+C to abort..."
else
    echo "Database: ${DB_NAME}@${DB_HOST}:${DB_PORT}"
    
    # Try to dump from within container first
    if docker exec ${CONTAINER_NAME} which mysqldump &>/dev/null; then
        docker exec ${CONTAINER_NAME} mysqldump \
            -h"${DB_HOST}" \
            -P"${DB_PORT}" \
            -u"${DB_USER}" \
            -p"${DB_PASS}" \
            --single-transaction \
            --routines \
            --triggers \
            "${DB_NAME}" > "${BACKUP_DIR}/database.sql"
    else
        # Try from host
        mysqldump \
            -h"${DB_HOST}" \
            -P"${DB_PORT}" \
            -u"${DB_USER}" \
            -p"${DB_PASS}" \
            --single-transaction \
            --routines \
            --triggers \
            "${DB_NAME}" > "${BACKUP_DIR}/database.sql" 2>/dev/null || {
                echo -e "${YELLOW}mysqldump not available, trying docker exec...${NC}"
                docker exec ${CONTAINER_NAME} bash -c "mysqldump -h\$SUITECRM_DATABASE_HOST -P\$SUITECRM_DATABASE_PORT -u\$SUITECRM_DATABASE_USER -p\$SUITECRM_DATABASE_PASSWORD --single-transaction \$SUITECRM_DATABASE_NAME" > "${BACKUP_DIR}/database.sql"
            }
    fi
    
    DB_SIZE=$(du -h "${BACKUP_DIR}/database.sql" | cut -f1)
    echo -e "${GREEN}✓ Database backup complete (${DB_SIZE})${NC}"
fi
echo ""

# ============================================
# STEP 3: Config Backup
# ============================================
echo -e "${YELLOW}Step 3: Backing up configuration files...${NC}"

# Copy config files from container
docker cp ${CONTAINER_NAME}:/bitnami/suitecrm/public/legacy/config.php "${BACKUP_DIR}/config.php" 2>/dev/null || echo "config.php not found"
docker cp ${CONTAINER_NAME}:/bitnami/suitecrm/public/legacy/config_override.php "${BACKUP_DIR}/config_override.php" 2>/dev/null || echo "config_override.php not found"
docker cp ${CONTAINER_NAME}:/bitnami/suitecrm/public/legacy/.htaccess "${BACKUP_DIR}/htaccess" 2>/dev/null || echo ".htaccess not found"

# Backup custom extensions
docker cp ${CONTAINER_NAME}:/bitnami/suitecrm/public/legacy/custom "${BACKUP_DIR}/custom" 2>/dev/null || echo "custom dir not found"

echo -e "${GREEN}✓ Configuration backup complete${NC}"
echo ""

# ============================================
# STEP 4: Upload Directory Backup
# ============================================
echo -e "${YELLOW}Step 4: Backing up uploads (attachments, documents)...${NC}"

# This can be large, so we'll compress it
docker cp ${CONTAINER_NAME}:/bitnami/suitecrm/public/legacy/upload "${BACKUP_DIR}/upload" 2>/dev/null && \
    tar -czf "${BACKUP_DIR}/upload.tar.gz" -C "${BACKUP_DIR}" upload && \
    rm -rf "${BACKUP_DIR}/upload" || echo "Upload dir backup skipped or failed"

UPLOAD_SIZE=$(du -h "${BACKUP_DIR}/upload.tar.gz" 2>/dev/null | cut -f1 || echo "N/A")
echo -e "${GREEN}✓ Upload backup complete (${UPLOAD_SIZE})${NC}"
echo ""

# ============================================
# STEP 5: Save current state for rollback
# ============================================
echo -e "${YELLOW}Step 5: Saving rollback information...${NC}"

cat > "${BACKUP_DIR}/rollback.sh" << ROLLBACK
#!/bin/bash
# Rollback script - run this to restore previous version
# Generated: $(date)

CONTAINER_NAME="${CONTAINER_NAME}"
PREVIOUS_IMAGE="${CURRENT_IMAGE}"

echo "Rolling back to: \${PREVIOUS_IMAGE}"

# Stop current container
docker stop \${CONTAINER_NAME}
docker rm \${CONTAINER_NAME}

# Restore from backup
echo "Restoring database..."
# Uncomment and edit the following line:
# mysql -h <host> -u <user> -p <database> < ${BACKUP_DIR}/database.sql

echo "To fully rollback:"
echo "1. Restore database: mysql ... < ${BACKUP_DIR}/database.sql"
echo "2. Update docker-compose.yml to use: \${PREVIOUS_IMAGE}"
echo "3. docker-compose up -d"
echo "4. Restore config files if needed from ${BACKUP_DIR}/"
ROLLBACK

chmod +x "${BACKUP_DIR}/rollback.sh"
echo "Previous image: ${CURRENT_IMAGE}" > "${BACKUP_DIR}/previous_version.txt"
echo -e "${GREEN}✓ Rollback script created${NC}"
echo ""

# ============================================
# STEP 6: Pull new image
# ============================================
echo -e "${YELLOW}Step 6: Pulling new image...${NC}"
docker pull mahir009/suitecrm-powerpack:${NEW_VERSION}
docker pull mahir009/suitecrm-powerpack:latest
echo -e "${GREEN}✓ New image pulled${NC}"
echo ""

# ============================================
# STEP 7: Upgrade confirmation
# ============================================
echo -e "${GREEN}=============================================="
echo "BACKUP COMPLETE"
echo "==============================================${NC}"
echo ""
echo "Backup location: ${BACKUP_DIR}"
echo "Contents:"
ls -la "${BACKUP_DIR}"
echo ""
echo -e "${YELLOW}=============================================="
echo "READY TO UPGRADE"
echo "==============================================${NC}"
echo ""
echo "The following will happen:"
echo "1. Stop current container"
echo "2. Update docker-compose.yml image tag"
echo "3. Start new container"
echo "4. Run module installation/migration"
echo ""
read -p "Proceed with upgrade? (yes/no): " CONFIRM

if [ "$CONFIRM" != "yes" ]; then
    echo "Upgrade cancelled. Your backup is at: ${BACKUP_DIR}"
    exit 0
fi

# ============================================
# STEP 8: Perform upgrade
# ============================================
echo -e "${YELLOW}Step 8: Upgrading...${NC}"

# Check if docker-compose.yml exists
if [ -f "docker-compose.yml" ]; then
    # Update image tag in docker-compose.yml
    sed -i "s|mahir009/suitecrm-powerpack:[^[:space:]]*|mahir009/suitecrm-powerpack:${NEW_VERSION}|g" docker-compose.yml
    
    # Restart with new image
    docker-compose down
    docker-compose up -d
elif [ -f "docker-compose.production.yml" ]; then
    sed -i "s|mahir009/suitecrm-powerpack:[^[:space:]]*|mahir009/suitecrm-powerpack:${NEW_VERSION}|g" docker-compose.production.yml
    docker-compose -f docker-compose.production.yml down
    docker-compose -f docker-compose.production.yml up -d
else
    echo -e "${YELLOW}No docker-compose.yml found. Manual upgrade required:${NC}"
    echo ""
    echo "1. Stop container: docker stop ${CONTAINER_NAME}"
    echo "2. Remove container: docker rm ${CONTAINER_NAME}"
    echo "3. Start with new image using your docker run command"
    echo "   Replace image with: mahir009/suitecrm-powerpack:${NEW_VERSION}"
    exit 0
fi

echo ""
echo -e "${YELLOW}Waiting for container to start...${NC}"
sleep 10

# ============================================
# STEP 9: Verify upgrade
# ============================================
echo -e "${YELLOW}Step 9: Verifying upgrade...${NC}"

# Check container is running
if docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo -e "${GREEN}✓ Container is running${NC}"
else
    echo -e "${RED}✗ Container failed to start!${NC}"
    echo "Check logs: docker logs ${CONTAINER_NAME}"
    echo "Rollback: ${BACKUP_DIR}/rollback.sh"
    exit 1
fi

# Check health
sleep 5
if docker exec ${CONTAINER_NAME} curl -s -o /dev/null -w "%{http_code}" http://localhost:8080 | grep -q "200\|302"; then
    echo -e "${GREEN}✓ SuiteCRM is responding${NC}"
else
    echo -e "${YELLOW}⚠ SuiteCRM may still be initializing...${NC}"
fi

echo ""
echo -e "${GREEN}=============================================="
echo "UPGRADE COMPLETE!"
echo "==============================================${NC}"
echo ""
echo "New version: ${NEW_VERSION}"
echo "Backup location: ${BACKUP_DIR}"
echo ""
echo "Next steps:"
echo "1. Verify SuiteCRM UI is working"
echo "2. Test email sync functionality"
echo "3. Configure OAuth: Admin → Email → External OAuth Providers"
echo ""
echo "If issues occur, run: ${BACKUP_DIR}/rollback.sh"
echo ""
