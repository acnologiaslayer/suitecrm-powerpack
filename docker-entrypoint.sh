#!/bin/bash
set -e

echo "Starting SuiteCRM PowerPack (skipping auto-install)..."

# Set required environment variables
export BITNAMI_APP_NAME="suitecrm"

# Disable HTTPS by default (users can enable via reverse proxy)
export SUITECRM_ENABLE_HTTPS="no"

# Start Apache directly without running Bitnami's setup wizard
exec /opt/bitnami/scripts/apache/run.sh
