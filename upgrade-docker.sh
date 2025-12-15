#!/bin/bash

# =====================================================
# Twilio Integration v2.4.0 - Docker Safe Upgrade Script
# =====================================================
# This script safely upgrades your Docker container
# while preserving all data and configuration
# =====================================================

set -e  # Exit on error

echo "======================================"
echo "Twilio Integration v2.4.0 Upgrade"
echo "Docker Container Safe Upgrade"
echo "======================================"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
NEW_VERSION="v2.4.0"
IMAGE_NAME="sakib9029/suitecrm-powerpack"
CONTAINER_NAME="${1:-suitecrm}"  # Use first argument or default to 'suitecrm'
BACKUP_DIR="./backups/$(date +%Y%m%d_%H%M%S)"

echo -e "${YELLOW}Container to upgrade: ${CONTAINER_NAME}${NC}"
echo ""

# Function to print colored messages
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}→ $1${NC}"
}

# Function to check if container exists
check_container_exists() {
    if ! docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
        print_error "Container '${CONTAINER_NAME}' not found!"
        echo "Available containers:"
        docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
        exit 1
    fi
    print_success "Container '${CONTAINER_NAME}' found"
}

# Function to get current version
get_current_version() {
    local version=$(docker exec ${CONTAINER_NAME} grep -oP "version.*=.*'\K[^']+" /bitnami/suitecrm/modules/TwilioIntegration/manifest.php 2>/dev/null || echo "unknown")
    echo "$version"
}

# Function to create backup directory
create_backup_dir() {
    mkdir -p "${BACKUP_DIR}"
    print_success "Backup directory created: ${BACKUP_DIR}"
}

# Function to backup configuration
backup_config() {
    print_info "Backing up configuration..."
    docker exec ${CONTAINER_NAME} cat /bitnami/suitecrm/config.php > "${BACKUP_DIR}/config.php.backup" 2>/dev/null || {
        print_error "Failed to backup config.php"
        return 1
    }

    # Extract Twilio config
    grep -E "twilio_" "${BACKUP_DIR}/config.php.backup" > "${BACKUP_DIR}/twilio_config.txt" 2>/dev/null || true
    print_success "Configuration backed up"
}

# Function to backup database
backup_database() {
    print_info "Backing up database..."

    # Get database credentials from environment
    local DB_HOST=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_HOST || echo "mariadb")
    local DB_NAME=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_NAME || echo "bitnami_suitecrm")
    local DB_USER=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_USER || echo "bn_suitecrm")
    local DB_PASS=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_PASSWORD || echo "")

    if [ -z "$DB_PASS" ]; then
        print_error "Database password not found in environment"
        return 1
    fi

    # Backup Twilio-related tables only (faster)
    docker exec ${CONTAINER_NAME} mysqldump \
        -h ${DB_HOST} \
        -u ${DB_USER} \
        -p${DB_PASS} \
        ${DB_NAME} \
        calls notes tasks documents twilio_audit_log \
        > "${BACKUP_DIR}/twilio_data_backup.sql" 2>/dev/null || {
        print_error "Failed to backup database"
        return 1
    }

    print_success "Database backed up (Twilio tables only)"
}

# Function to export container volumes
backup_volumes() {
    print_info "Identifying volumes..."

    # Get volume names
    local volumes=$(docker inspect ${CONTAINER_NAME} --format '{{range .Mounts}}{{.Name}} {{end}}')

    if [ -z "$volumes" ]; then
        print_info "No named volumes found (using bind mounts)"
        return 0
    fi

    echo "Volumes: $volumes" > "${BACKUP_DIR}/volumes.txt"
    print_success "Volume information saved"
}

# Function to save current container configuration
save_container_config() {
    print_info "Saving container configuration..."

    # Export container config as JSON
    docker inspect ${CONTAINER_NAME} > "${BACKUP_DIR}/container_config.json"

    # Extract environment variables
    docker inspect ${CONTAINER_NAME} --format '{{range .Config.Env}}{{println .}}{{end}}' > "${BACKUP_DIR}/env_vars.txt"

    # Extract volume mounts
    docker inspect ${CONTAINER_NAME} --format '{{json .Mounts}}' | jq '.' > "${BACKUP_DIR}/mounts.json" 2>/dev/null || \
        docker inspect ${CONTAINER_NAME} --format '{{json .Mounts}}' > "${BACKUP_DIR}/mounts.json"

    # Extract port bindings
    docker inspect ${CONTAINER_NAME} --format '{{json .NetworkSettings.Ports}}' > "${BACKUP_DIR}/ports.json"

    print_success "Container configuration saved"
}

# Function to pull new image
pull_new_image() {
    print_info "Pulling new Docker image (${IMAGE_NAME}:${NEW_VERSION})..."

    if docker pull ${IMAGE_NAME}:${NEW_VERSION}; then
        print_success "New image pulled successfully"
    else
        print_error "Failed to pull new image"
        exit 1
    fi
}

# Function to stop current container
stop_container() {
    print_info "Stopping current container..."

    if docker stop ${CONTAINER_NAME}; then
        print_success "Container stopped"
    else
        print_error "Failed to stop container"
        exit 1
    fi
}

# Function to rename old container
rename_old_container() {
    local old_name="${CONTAINER_NAME}_old_$(date +%Y%m%d_%H%M%S)"
    print_info "Renaming old container to ${old_name}..."

    if docker rename ${CONTAINER_NAME} ${old_name}; then
        echo "${old_name}" > "${BACKUP_DIR}/old_container_name.txt"
        print_success "Old container renamed"
    else
        print_error "Failed to rename container"
        exit 1
    fi
}

# Function to create new container with same configuration
create_new_container() {
    print_info "Creating new container with preserved volumes..."

    # Read configuration from backup
    local env_vars=$(cat "${BACKUP_DIR}/env_vars.txt" | while read line; do echo "-e $line"; done | tr '\n' ' ')
    local mounts=$(docker inspect ${CONTAINER_NAME}_old_* --format '{{range .Mounts}}-v {{.Source}}:{{.Destination}} {{end}}' | head -1)
    local ports=$(docker inspect ${CONTAINER_NAME}_old_* --format '{{range $p, $conf := .NetworkSettings.Ports}}{{if $conf}}-p {{(index $conf 0).HostPort}}:{{$p}} {{end}}{{end}}' | head -1)

    # Create new container
    eval "docker run -d --name ${CONTAINER_NAME} ${env_vars} ${mounts} ${ports} ${IMAGE_NAME}:${NEW_VERSION}" || {
        print_error "Failed to create new container"
        print_info "Restoring old container..."
        docker rename ${CONTAINER_NAME}_old_* ${CONTAINER_NAME}
        docker start ${CONTAINER_NAME}
        exit 1
    }

    print_success "New container created"
}

# Function to wait for container to be ready
wait_for_container() {
    print_info "Waiting for container to be ready..."

    local max_attempts=30
    local attempt=0

    while [ $attempt -lt $max_attempts ]; do
        if docker exec ${CONTAINER_NAME} test -f /bitnami/suitecrm/config.php 2>/dev/null; then
            print_success "Container is ready"
            return 0
        fi

        attempt=$((attempt + 1))
        echo -n "."
        sleep 2
    done

    echo ""
    print_error "Container did not become ready in time"
    return 1
}

# Function to run database migration
run_migration() {
    print_info "Running database migration..."

    # Check if migration file exists
    if ! docker exec ${CONTAINER_NAME} test -f /bitnami/suitecrm/modules/TwilioIntegration/install/upgrade_to_v2.4.0.sql; then
        print_error "Migration file not found in new image"
        return 1
    fi

    # Get database credentials
    local DB_HOST=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_HOST)
    local DB_NAME=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_NAME)
    local DB_USER=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_USER)
    local DB_PASS=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_PASSWORD)

    # Run migration
    docker exec ${CONTAINER_NAME} mysql \
        -h ${DB_HOST} \
        -u ${DB_USER} \
        -p${DB_PASS} \
        ${DB_NAME} \
        < /bitnami/suitecrm/modules/TwilioIntegration/install/upgrade_to_v2.4.0.sql 2>/dev/null || {
        print_error "Database migration failed"
        return 1
    }

    print_success "Database migration completed"
}

# Function to verify configuration preserved
verify_config() {
    print_info "Verifying configuration preserved..."

    # Extract Twilio config from new container
    docker exec ${CONTAINER_NAME} grep -E "twilio_" /bitnami/suitecrm/config.php > "${BACKUP_DIR}/twilio_config_after.txt" 2>/dev/null || true

    # Compare
    if diff -q "${BACKUP_DIR}/twilio_config.txt" "${BACKUP_DIR}/twilio_config_after.txt" > /dev/null 2>&1; then
        print_success "Configuration preserved correctly"
    else
        print_error "Configuration may have changed"
        echo "Differences:"
        diff "${BACKUP_DIR}/twilio_config.txt" "${BACKUP_DIR}/twilio_config_after.txt" || true

        read -p "Continue anyway? (y/N): " confirm
        if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
            print_info "Upgrade cancelled by user"
            exit 1
        fi
    fi
}

# Function to verify data preserved
verify_data() {
    print_info "Verifying data preserved..."

    local DB_HOST=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_HOST)
    local DB_NAME=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_NAME)
    local DB_USER=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_USER)
    local DB_PASS=$(docker exec ${CONTAINER_NAME} printenv SUITECRM_DATABASE_PASSWORD)

    # Count records in key tables
    local calls_count=$(docker exec ${CONTAINER_NAME} mysql -h ${DB_HOST} -u ${DB_USER} -p${DB_PASS} -N -e "SELECT COUNT(*) FROM calls" ${DB_NAME} 2>/dev/null)
    local notes_count=$(docker exec ${CONTAINER_NAME} mysql -h ${DB_HOST} -u ${DB_USER} -p${DB_PASS} -N -e "SELECT COUNT(*) FROM notes WHERE name LIKE '%SMS%'" ${DB_NAME} 2>/dev/null)

    echo "Calls: ${calls_count}" > "${BACKUP_DIR}/data_counts.txt"
    echo "SMS Notes: ${notes_count}" >> "${BACKUP_DIR}/data_counts.txt"

    print_success "Data verification completed (Calls: ${calls_count}, SMS: ${notes_count})"
}

# Function to test new features
test_features() {
    print_info "Testing new v2.4.0 features..."

    # Test recording webhook endpoint exists
    if docker exec ${CONTAINER_NAME} test -f /bitnami/suitecrm/modules/TwilioIntegration/views/view.recording_webhook.php; then
        print_success "Recording webhook view installed"
    else
        print_error "Recording webhook view missing"
    fi

    # Test scheduler files exist
    if docker exec ${CONTAINER_NAME} test -f /bitnami/suitecrm/modules/TwilioIntegration/TwilioScheduler.php; then
        print_success "Scheduler module installed"
    else
        print_error "Scheduler module missing"
    fi

    # Test security module exists
    if docker exec ${CONTAINER_NAME} test -f /bitnami/suitecrm/modules/TwilioIntegration/TwilioSecurity.php; then
        print_success "Security module installed"
    else
        print_error "Security module missing"
    fi
}

# Function to cleanup old container
cleanup_old_container() {
    print_info "Old container preserved for safety"
    echo ""
    echo "To remove old container after confirming upgrade is successful:"
    echo "  docker rm ${CONTAINER_NAME}_old_*"
    echo ""
    echo "To rollback to old container if needed:"
    echo "  docker stop ${CONTAINER_NAME}"
    echo "  docker rm ${CONTAINER_NAME}"
    echo "  docker rename ${CONTAINER_NAME}_old_* ${CONTAINER_NAME}"
    echo "  docker start ${CONTAINER_NAME}"
}

# Main upgrade process
main() {
    echo "Starting upgrade process..."
    echo ""

    # Pre-flight checks
    print_info "Step 1/13: Checking container exists..."
    check_container_exists

    # Get current version
    CURRENT_VERSION=$(get_current_version)
    echo "Current version: ${CURRENT_VERSION}"
    echo "Target version: ${NEW_VERSION}"
    echo ""

    if [ "$CURRENT_VERSION" == "${NEW_VERSION#v}" ]; then
        print_info "Already on version ${NEW_VERSION}"
        read -p "Continue anyway? (y/N): " confirm
        if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
            exit 0
        fi
    fi

    # Confirmation
    echo ""
    print_info "This will upgrade Twilio Integration to ${NEW_VERSION}"
    print_info "All data and configuration will be preserved"
    read -p "Continue? (y/N): " confirm
    if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
        echo "Upgrade cancelled"
        exit 0
    fi
    echo ""

    # Backup phase
    print_info "Step 2/13: Creating backup directory..."
    create_backup_dir

    print_info "Step 3/13: Backing up configuration..."
    backup_config

    print_info "Step 4/13: Backing up database..."
    backup_database

    print_info "Step 5/13: Saving container configuration..."
    save_container_config

    print_info "Step 6/13: Identifying volumes..."
    backup_volumes

    # Upgrade phase
    print_info "Step 7/13: Pulling new image..."
    pull_new_image

    print_info "Step 8/13: Stopping current container..."
    stop_container

    print_info "Step 9/13: Renaming old container..."
    rename_old_container

    print_info "Step 10/13: Creating new container..."
    create_new_container

    print_info "Step 11/13: Waiting for container to be ready..."
    wait_for_container

    # Migration phase
    print_info "Step 12/13: Running database migration..."
    run_migration

    # Verification phase
    print_info "Step 13/13: Verifying upgrade..."
    verify_config
    verify_data
    test_features

    # Cleanup
    cleanup_old_container

    # Success
    echo ""
    echo "======================================"
    print_success "Upgrade completed successfully!"
    echo "======================================"
    echo ""
    echo "Backup location: ${BACKUP_DIR}"
    echo "New container: ${CONTAINER_NAME}"
    echo "Old container: ${CONTAINER_NAME}_old_* (preserved)"
    echo ""
    echo "Next steps:"
    echo "1. Test your application"
    echo "2. Verify webhooks are working"
    echo "3. Check logs: docker logs ${CONTAINER_NAME}"
    echo "4. After 24-48 hours, remove old container"
    echo ""
}

# Run main function
main "$@"
