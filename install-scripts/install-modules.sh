#!/bin/bash
set -e

echo "============================================"
echo "Installing SuiteCRM PowerPack Modules"
echo "============================================"

# Verify we're in the right place
if [ ! -f "/bitnami/suitecrm/public/legacy/config.php" ]; then
    echo "ERROR: SuiteCRM config.php not found. Is SuiteCRM installed?"
    echo "Expected: /bitnami/suitecrm/public/legacy/config.php"
    exit 1
fi

# Verify modules exist (check both image source and runtime locations)
for MODULE in TwilioIntegration LeadJourney FunnelDashboard SalesTargets Packages Webhooks NotificationHub VerbacallIntegration; do
    # Check image source location first (where modules are stored in Docker image)
    if [ -f "/opt/bitnami/suitecrm/modules/$MODULE/$MODULE.php" ]; then
        echo "Found module: $MODULE (from image)"
    # Fallback to runtime location (after first-run copy)
    elif [ -f "/bitnami/suitecrm/modules/$MODULE/$MODULE.php" ]; then
        echo "Found module: $MODULE (from volume)"
    # Also check if already installed in legacy
    elif [ -f "/bitnami/suitecrm/public/legacy/modules/$MODULE/$MODULE.php" ]; then
        echo "Found module: $MODULE (already installed)"
    else
        echo "ERROR: Module $MODULE not found"
        echo "  Checked: /opt/bitnami/suitecrm/modules/$MODULE/"
        echo "  Checked: /bitnami/suitecrm/modules/$MODULE/"
        echo "  Checked: /bitnami/suitecrm/public/legacy/modules/$MODULE/"
        exit 1
    fi
done

# Change to SuiteCRM directory
cd /bitnami/suitecrm/public/legacy || exit 1

echo ""
echo "Creating custom directories..."
mkdir -p /bitnami/suitecrm/public/legacy/custom/Extension/application/Ext/Include
mkdir -p /bitnami/suitecrm/public/legacy/custom/application/Ext/Include
mkdir -p /bitnami/suitecrm/public/legacy/custom/Extension/modules
mkdir -p /bitnami/suitecrm/public/legacy/custom/modules

# Copy module files to legacy directory
echo "Copying module files to legacy directory..."
for MODULE in TwilioIntegration LeadJourney FunnelDashboard SalesTargets Packages Webhooks NotificationHub VerbacallIntegration; do
    # Prefer image source location (for upgrades), fallback to volume location
    if [ -d "/opt/bitnami/suitecrm/modules/$MODULE" ]; then
        echo "  Copying $MODULE from image..."
        rm -rf "/bitnami/suitecrm/public/legacy/modules/$MODULE" 2>/dev/null || true
        cp -r "/opt/bitnami/suitecrm/modules/$MODULE" "/bitnami/suitecrm/public/legacy/modules/"
        chown -R daemon:daemon "/bitnami/suitecrm/public/legacy/modules/$MODULE"
    elif [ -d "/bitnami/suitecrm/modules/$MODULE" ]; then
        echo "  Copying $MODULE from volume..."
        rm -rf "/bitnami/suitecrm/public/legacy/modules/$MODULE" 2>/dev/null || true
        cp -r "/bitnami/suitecrm/modules/$MODULE" "/bitnami/suitecrm/public/legacy/modules/"
        chown -R daemon:daemon "/bitnami/suitecrm/public/legacy/modules/$MODULE"
    fi
done

# Copy extension files for modules (prefer image source for upgrades)
echo "Installing module extensions..."
mkdir -p /bitnami/suitecrm/public/legacy/custom/Extension/application/Ext/Language

# LeadJourney extensions
if [ -d "/opt/bitnami/suitecrm/modules/LeadJourney/Extensions" ]; then
    cp -r /opt/bitnami/suitecrm/modules/LeadJourney/Extensions/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
elif [ -d "/bitnami/suitecrm/modules/LeadJourney/Extensions" ]; then
    cp -r /bitnami/suitecrm/modules/LeadJourney/Extensions/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
fi

# TwilioIntegration extensions
if [ -d "/opt/bitnami/suitecrm/modules/TwilioIntegration/Extensions" ]; then
    cp -r /opt/bitnami/suitecrm/modules/TwilioIntegration/Extensions/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
elif [ -d "/bitnami/suitecrm/modules/TwilioIntegration/Extensions" ]; then
    cp -r /bitnami/suitecrm/modules/TwilioIntegration/Extensions/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
fi

# VerbacallIntegration extensions
if [ -d "/opt/bitnami/suitecrm/modules/VerbacallIntegration/Extensions" ]; then
    cp -r /opt/bitnami/suitecrm/modules/VerbacallIntegration/Extensions/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
elif [ -d "/bitnami/suitecrm/modules/VerbacallIntegration/Extensions" ]; then
    cp -r /bitnami/suitecrm/modules/VerbacallIntegration/Extensions/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
fi

# Copy main custom Extensions (includes PowerPackModules.php for nav display)
if [ -d "/opt/bitnami/suitecrm/custom/Extension" ]; then
    cp -r /opt/bitnami/suitecrm/custom/Extension/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
elif [ -d "/bitnami/suitecrm/custom/Extension" ]; then
    cp -r /bitnami/suitecrm/custom/Extension/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
fi

# Create ActionDefs directory and copy custom ACL action definitions
mkdir -p /bitnami/suitecrm/public/legacy/custom/Extension/application/Ext/ActionDefs
if [ -f "/opt/bitnami/suitecrm/custom/Extension/application/Ext/ActionDefs/PowerPackActions.php" ]; then
    cp /opt/bitnami/suitecrm/custom/Extension/application/Ext/ActionDefs/PowerPackActions.php /bitnami/suitecrm/public/legacy/custom/Extension/application/Ext/ActionDefs/
elif [ -f "/bitnami/suitecrm/custom/Extension/application/Ext/ActionDefs/PowerPackActions.php" ]; then
    cp /bitnami/suitecrm/custom/Extension/application/Ext/ActionDefs/PowerPackActions.php /bitnami/suitecrm/public/legacy/custom/Extension/application/Ext/ActionDefs/
fi

# Create compiled language extension for dropdown lists
# Note: This OVERWRITES the file to prevent duplicate die() statements from compiled extensions
mkdir -p /bitnami/suitecrm/public/legacy/custom/application/Ext/Language
cat > /bitnami/suitecrm/public/legacy/custom/application/Ext/Language/en_us.lang.ext.php << 'PHPEOF'
<?php
// WARNING: The contents of this file are auto-generated by PowerPack
// Do not include die() statements - this breaks language loading

// Funnel Types - The three sales verticals
$app_list_strings['funnel_type_list'] = array(
    '' => '',
    'Realtors' => 'Realtors',
    'Senior_Living' => 'Senior Living',
    'Home_Care' => 'Home Care',
);

// Pipeline Stages - Custom sales pipeline
$app_list_strings['pipeline_stage_list'] = array(
    '' => '',
    'New' => 'New',
    'Contacting' => 'Contacting',
    'Contacted' => 'Contacted',
    'Qualified' => 'Qualified',
    'Interested' => 'Interested',
    'Opportunity' => 'Opportunity',
    'Demo_Visit' => 'Demo/Visit Scheduled',
    'Demo_Completed' => 'Demo/Visit Completed',
    'Proposal' => 'Proposal Sent',
    'Negotiation' => 'Negotiation',
    'Closed_Won' => 'Closed Won',
    'Closed_Lost' => 'Closed Lost',
    'Disqualified' => 'Disqualified',
);

// Sales Target Types
$app_list_strings['sales_target_type_list'] = array(
    'bdm' => 'BDM (Individual)',
    'team' => 'Team',
);

// Period Types
$app_list_strings['sales_period_type_list'] = array(
    'monthly' => 'Monthly',
    'quarterly' => 'Quarterly',
    'annual' => 'Annual',
);

// Billing Frequency
$app_list_strings['billing_frequency_list'] = array(
    '' => '',
    'one-time' => 'One-Time',
    'monthly' => 'Monthly',
    'quarterly' => 'Quarterly',
    'annual' => 'Annual',
);

// User Role Types (for dashboard access)
$app_list_strings['sales_role_type_list'] = array(
    '' => '',
    'cro' => 'CRO (Chief Revenue Officer)',
    'sales_ops' => 'Sales Operations Manager',
    'bdm' => 'Business Development Manager',
    'admin' => 'Administrator',
);

// Alert Types
$app_list_strings['sales_alert_type_list'] = array(
    'stalled_lead' => 'Stalled Lead',
    'missed_followup' => 'Missed Follow-up',
    'underperformance' => 'Underperformance',
    'target_at_risk' => 'Target at Risk',
    'high_value_idle' => 'High-Value Opportunity Idle',
);

// Alert Severity
$app_list_strings['sales_alert_severity_list'] = array(
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'critical' => 'Critical',
);

// Alert Status
$app_list_strings['sales_alert_status_list'] = array(
    'active' => 'Active',
    'acknowledged' => 'Acknowledged',
    'resolved' => 'Resolved',
    'dismissed' => 'Dismissed',
);

// Touchpoint types for Lead Journey module
$app_list_strings['touchpoint_type_list'] = array(
    '' => '',
    'call' => 'Call',
    'email' => 'Email',
    'meeting' => 'Meeting',
    'site_visit' => 'Site Visit',
    'linkedin_click' => 'LinkedIn Click',
    'campaign' => 'Campaign',
    'form_submission' => 'Form Submission',
    'download' => 'Download',
    'webinar' => 'Webinar',
    'trade_show' => 'Trade Show',
    'referral' => 'Referral',
    'other' => 'Other',
);

// Parent type options for Lead Journey
$app_list_strings['lead_journey_parent_type_list'] = array(
    'Leads' => 'Lead',
    'Contacts' => 'Contact',
    'Accounts' => 'Account',
    'Opportunities' => 'Opportunity',
);

// PowerPack module display names for navigation
$app_list_strings['moduleList']['FunnelDashboard'] = 'Funnel Dashboard';
$app_list_strings['moduleList']['SalesTargets'] = 'Sales Targets';
$app_list_strings['moduleList']['Packages'] = 'Packages';
$app_list_strings['moduleList']['TwilioIntegration'] = 'Twilio Integration';
$app_list_strings['moduleList']['LeadJourney'] = 'Lead Journey';
$app_list_strings['moduleList']['Webhooks'] = 'Webhooks';
$app_list_strings['moduleList']['NotificationHub'] = 'Notification Hub';
// VerbacallIntegration is intentionally hidden from nav - it's Lead-specific only

// ACL Action Labels for Role Management UI
$app_strings['LBL_ACTION_CRO_DASHBOARD'] = 'CRO Dashboard';
$app_strings['LBL_ACTION_SALESOPS_DASHBOARD'] = 'Sales Ops Dashboard';
$app_strings['LBL_ACTION_BDM_DASHBOARD'] = 'BDM Dashboard';
$app_strings['LBL_ACTION_DASHBOARD'] = 'Dashboard';
$app_strings['LBL_ACTION_VIEW_RECORDINGS'] = 'View Call Recordings';
PHPEOF

# Create module registration files directly
echo ""
echo "Registering modules in SuiteCRM..."

# Create the source extension file
cat > /bitnami/suitecrm/public/legacy/custom/Extension/application/Ext/Include/powerpack_modules.php << 'PHPEOF'
<?php
// PowerPack Module Registration
$beanList['TwilioIntegration'] = 'TwilioIntegration';
$beanFiles['TwilioIntegration'] = 'modules/TwilioIntegration/TwilioIntegration.php';
$moduleList[] = 'TwilioIntegration';

$beanList['LeadJourney'] = 'LeadJourney';
$beanFiles['LeadJourney'] = 'modules/LeadJourney/LeadJourney.php';
$moduleList[] = 'LeadJourney';

$beanList['FunnelDashboard'] = 'FunnelDashboard';
$beanFiles['FunnelDashboard'] = 'modules/FunnelDashboard/FunnelDashboard.php';
$moduleList[] = 'FunnelDashboard';

$beanList['SalesTargets'] = 'SalesTargets';
$beanFiles['SalesTargets'] = 'modules/SalesTargets/SalesTargets.php';
$moduleList[] = 'SalesTargets';

$beanList['Packages'] = 'Packages';
$beanFiles['Packages'] = 'modules/Packages/Packages.php';
$moduleList[] = 'Packages';

$beanList['Webhooks'] = 'Webhooks';
$beanFiles['Webhooks'] = 'modules/Webhooks/Webhooks.php';
$moduleList[] = 'Webhooks';

$beanList['NotificationHub'] = 'NotificationHub';
$beanFiles['NotificationHub'] = 'modules/NotificationHub/NotificationHub.php';
$moduleList[] = 'NotificationHub';

$beanList['VerbacallIntegration'] = 'VerbacallIntegration';
$beanFiles['VerbacallIntegration'] = 'modules/VerbacallIntegration/VerbacallIntegration.php';
// VerbacallIntegration not added to moduleList - it's Lead-specific only
PHPEOF

# Create the compiled extension file - SuiteCRM loads modules.ext.php (not Include.ext.php)
cat > /bitnami/suitecrm/public/legacy/custom/application/Ext/Include/modules.ext.php << 'PHPEOF'
<?php
// PowerPack Module Registration - Compiled
$beanList['TwilioIntegration'] = 'TwilioIntegration';
$beanFiles['TwilioIntegration'] = 'modules/TwilioIntegration/TwilioIntegration.php';
$moduleList[] = 'TwilioIntegration';

$beanList['LeadJourney'] = 'LeadJourney';
$beanFiles['LeadJourney'] = 'modules/LeadJourney/LeadJourney.php';
$moduleList[] = 'LeadJourney';

$beanList['FunnelDashboard'] = 'FunnelDashboard';
$beanFiles['FunnelDashboard'] = 'modules/FunnelDashboard/FunnelDashboard.php';
$moduleList[] = 'FunnelDashboard';

$beanList['SalesTargets'] = 'SalesTargets';
$beanFiles['SalesTargets'] = 'modules/SalesTargets/SalesTargets.php';
$moduleList[] = 'SalesTargets';

$beanList['Packages'] = 'Packages';
$beanFiles['Packages'] = 'modules/Packages/Packages.php';
$moduleList[] = 'Packages';

$beanList['Webhooks'] = 'Webhooks';
$beanFiles['Webhooks'] = 'modules/Webhooks/Webhooks.php';
$moduleList[] = 'Webhooks';

$beanList['NotificationHub'] = 'NotificationHub';
$beanFiles['NotificationHub'] = 'modules/NotificationHub/NotificationHub.php';
$moduleList[] = 'NotificationHub';

$beanList['VerbacallIntegration'] = 'VerbacallIntegration';
$beanFiles['VerbacallIntegration'] = 'modules/VerbacallIntegration/VerbacallIntegration.php';
// VerbacallIntegration not added to moduleList - it's Lead-specific only
PHPEOF

chown -R daemon:daemon /bitnami/suitecrm/public/legacy/custom/

# Add PowerPack modules to SuiteCRM 8 module routing
echo "Adding modules to SuiteCRM 8 navigation..."
MODULE_ROUTING_FILE="/bitnami/suitecrm/config/services/module/module_routing.yaml"
if [ -f "$MODULE_ROUTING_FILE" ]; then
    # Check if our modules are already added
    if ! grep -q "funnel-dashboard:" "$MODULE_ROUTING_FILE"; then
        # Backup original file
        cp "$MODULE_ROUTING_FILE" "${MODULE_ROUTING_FILE}.backup"
        
        # Get the indentation used in the file (count leading spaces on a module line)
        INDENT=$(grep -E "^\s+[a-z]+-?[a-z]*:" "$MODULE_ROUTING_FILE" | head -1 | sed 's/[^ ].*//' | wc -c)
        INDENT=$((INDENT - 1))
        if [ "$INDENT" -lt 2 ]; then
            INDENT=4  # Default to 4 spaces if detection fails
        fi
        
        # Create indentation strings
        SPACES=$(printf '%*s' "$INDENT" '')
        SPACES2=$(printf '%*s' "$((INDENT + 2))" '')
        
        # Append module routing with detected indentation
        cat >> "$MODULE_ROUTING_FILE" << YAMLEOF

${SPACES}# PowerPack Modules
${SPACES}funnel-dashboard:
${SPACES2}index: true
${SPACES2}list: true
${SPACES2}record: false
${SPACES}sales-targets:
${SPACES2}index: true
${SPACES2}list: true
${SPACES2}record: true
${SPACES}packages:
${SPACES2}index: true
${SPACES2}list: true
${SPACES2}record: true
${SPACES}twilio-integration:
${SPACES2}index: true
${SPACES2}list: true
${SPACES2}record: false
${SPACES}lead-journey:
${SPACES2}index: true
${SPACES2}list: true
${SPACES2}record: true
${SPACES}webhooks:
${SPACES2}index: true
${SPACES2}list: true
${SPACES2}record: false
${SPACES}notification-hub:
${SPACES2}index: true
${SPACES2}list: true
${SPACES2}record: false
${SPACES}verbacall-integration:
${SPACES2}index: true
${SPACES2}list: true
${SPACES2}record: false
YAMLEOF
        
        # Validate YAML syntax (if python3 available)
        if command -v python3 &> /dev/null; then
            if ! python3 -c "import yaml; yaml.safe_load(open('$MODULE_ROUTING_FILE'))" 2>/dev/null; then
                echo "  ⚠ YAML validation failed, restoring backup"
                cp "${MODULE_ROUTING_FILE}.backup" "$MODULE_ROUTING_FILE"
                echo "  Module routing NOT configured (YAML error)"
            else
                echo "  ✓ Module routing configured"
                rm -f "${MODULE_ROUTING_FILE}.backup"
            fi
        else
            echo "  ✓ Module routing configured (unvalidated)"
        fi
    else
        echo "  Module routing already configured"
    fi
fi

# Add PowerPack modules to SuiteCRM 8 module name map (required for frontend/legacy name mapping)
MODULE_NAME_MAP="/bitnami/suitecrm/public/legacy/include/portability/module_name_map.php"
if [ -f "$MODULE_NAME_MAP" ]; then
    if ! grep -q "FunnelDashboard" "$MODULE_NAME_MAP"; then
        cat >> "$MODULE_NAME_MAP" << 'MAPEOF'

// PowerPack Modules
$module_name_map["FunnelDashboard"] = [
    "frontend" => "funnel-dashboard",
    "core" => "FunnelDashboard"
];
$module_name_map["SalesTargets"] = [
    "frontend" => "sales-targets",
    "core" => "SalesTargets"
];
$module_name_map["Packages"] = [
    "frontend" => "packages",
    "core" => "Packages"
];
$module_name_map["TwilioIntegration"] = [
    "frontend" => "twilio-integration",
    "core" => "TwilioIntegration"
];
$module_name_map["LeadJourney"] = [
    "frontend" => "lead-journey",
    "core" => "LeadJourney"
];
$module_name_map["Webhooks"] = [
    "frontend" => "webhooks",
    "core" => "Webhooks"
];
$module_name_map["NotificationHub"] = [
    "frontend" => "notification-hub",
    "core" => "NotificationHub"
];
// VerbacallIntegration not added to module_name_map - it's Lead-specific only
MAPEOF
        echo "  ✓ Module name mappings added"
    else
        echo "  Module name mappings already configured"
    fi
fi

echo "✓ Module registration completed"

# Clear cache to ensure new modules are loaded
echo ""
echo "Clearing cache..."
rm -rf /bitnami/suitecrm/public/legacy/cache/* 2>/dev/null || true

# Create database tables
echo ""
echo "Checking database tables..."
DB_PORT="${SUITECRM_DATABASE_PORT_NUMBER:-3306}"

# SSL Configuration via environment variables:
# - MYSQL_SSL_CA: Path to CA certificate file (e.g., /path/to/ca-certificate.crt)
# - MYSQL_CLIENT_ENABLE_SSL: Set to "yes" to enable SSL (used with MYSQL_SSL_CA)
#
# For DigitalOcean managed MySQL:
#   -e MYSQL_SSL_CA=/opt/bitnami/mysql/certs/ca-certificate.crt
#   -e MYSQL_CLIENT_ENABLE_SSL=yes
#
# For local MySQL without SSL: Don't set MYSQL_SSL_CA or set MYSQL_CLIENT_ENABLE_SSL=no

SSL_CA_PATH="${MYSQL_SSL_CA:-${MYSQL_CLIENT_SSL_CA_FILE:-}}"
SSL_ENABLED="${MYSQL_CLIENT_ENABLE_SSL:-no}"

if [ "$SSL_ENABLED" = "yes" ] && [ -n "$SSL_CA_PATH" ] && [ -f "$SSL_CA_PATH" ]; then
    SSL_OPTS="--ssl-ca=$SSL_CA_PATH"
    echo "Using SSL connection to database with CA: $SSL_CA_PATH"
else
    # Default: disable SSL for MariaDB client
    SSL_OPTS="--skip-ssl"
    echo "Using standard database connection (SSL disabled)..."
fi

# Define MYSQL_FLAGS for use throughout the script
MYSQL_FLAGS="-h$SUITECRM_DATABASE_HOST -P$DB_PORT -u$SUITECRM_DATABASE_USER -p$SUITECRM_DATABASE_PASSWORD $SSL_OPTS $SUITECRM_DATABASE_NAME"

# Check if tables already exist
TABLES_EXIST=$(mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SUITECRM_DATABASE_NAME' AND table_name IN ('twilio_integration', 'twilio_audit_log', 'lead_journey', 'funnel_dashboard', 'sales_targets', 'packages', 'notification_queue', 'notification_api_keys', 'notification_rate_limit');" 2>/dev/null || echo "0")

if [ "$TABLES_EXIST" = "9" ]; then
    echo "All module tables already exist, skipping migration"
else
    echo "Creating database tables (found $TABLES_EXIST/9 tables)..."
    mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" <<'EOF'

-- Twilio Integration table
CREATE TABLE IF NOT EXISTS twilio_integration (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(255),
    date_entered DATETIME,
    date_modified DATETIME,
    modified_user_id VARCHAR(36),
    created_by VARCHAR(36),
    description TEXT,
    deleted TINYINT(1) DEFAULT 0,
    assigned_user_id VARCHAR(36),
    account_sid VARCHAR(255),
    auth_token VARCHAR(255),
    phone_number VARCHAR(50),
    enable_click_to_call TINYINT(1) DEFAULT 1,
    enable_auto_logging TINYINT(1) DEFAULT 1,
    enable_recordings TINYINT(1) DEFAULT 1,
    webhook_url VARCHAR(255),
    INDEX idx_assigned (assigned_user_id),
    INDEX idx_deleted (deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Twilio Audit Log table
CREATE TABLE IF NOT EXISTS twilio_audit_log (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    data TEXT,
    user_id VARCHAR(36),
    date_created DATETIME NOT NULL,
    INDEX idx_action (action),
    INDEX idx_user_id (user_id),
    INDEX idx_date_created (date_created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lead Journey table
CREATE TABLE IF NOT EXISTS lead_journey (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(255),
    date_entered DATETIME,
    date_modified DATETIME,
    modified_user_id VARCHAR(36),
    created_by VARCHAR(36),
    description TEXT,
    deleted TINYINT(1) DEFAULT 0,
    parent_type VARCHAR(255),
    parent_id VARCHAR(36),
    touchpoint_type VARCHAR(50),
    touchpoint_date DATETIME,
    touchpoint_data TEXT,
    source VARCHAR(255),
    campaign_id VARCHAR(36),
    INDEX idx_parent (parent_type, parent_id),
    INDEX idx_touchpoint_date (touchpoint_date),
    INDEX idx_deleted (deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Funnel Dashboard table
CREATE TABLE IF NOT EXISTS funnel_dashboard (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(255),
    date_entered DATETIME,
    date_modified DATETIME,
    deleted TINYINT(1) DEFAULT 0,
    category VARCHAR(255),
    funnel_config TEXT,
    INDEX idx_category (category),
    INDEX idx_deleted (deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales Targets table
CREATE TABLE IF NOT EXISTS sales_targets (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(255),
    date_entered DATETIME,
    date_modified DATETIME,
    modified_user_id VARCHAR(36),
    created_by VARCHAR(36),
    description TEXT,
    deleted TINYINT(1) DEFAULT 0,
    assigned_user_id VARCHAR(36),
    target_type VARCHAR(50) DEFAULT 'bdm',
    target_user_id VARCHAR(36),
    team_id VARCHAR(36),
    funnel_type VARCHAR(100),
    period_type VARCHAR(50) DEFAULT 'monthly',
    period_year INT(4),
    period_month INT(2),
    period_quarter INT(1),
    revenue_target DECIMAL(26,6) DEFAULT 0,
    revenue_actual DECIMAL(26,6) DEFAULT 0,
    demos_target INT(11) DEFAULT 0,
    demos_actual INT(11) DEFAULT 0,
    leads_target INT(11) DEFAULT 0,
    leads_actual INT(11) DEFAULT 0,
    calls_target INT(11) DEFAULT 0,
    calls_actual INT(11) DEFAULT 0,
    commission_rate DECIMAL(5,2) DEFAULT 5.00,
    commission_earned DECIMAL(26,6) DEFAULT 0,
    commission_paid TINYINT(1) DEFAULT 0,
    INDEX idx_target_user (target_user_id),
    INDEX idx_team (team_id),
    INDEX idx_period (period_year, period_month, period_quarter),
    INDEX idx_funnel (funnel_type),
    INDEX idx_deleted (deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Packages table
CREATE TABLE IF NOT EXISTS packages (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(255),
    date_entered DATETIME,
    date_modified DATETIME,
    modified_user_id VARCHAR(36),
    created_by VARCHAR(36),
    description TEXT,
    deleted TINYINT(1) DEFAULT 0,
    assigned_user_id VARCHAR(36),
    package_code VARCHAR(50),
    package_type VARCHAR(100),
    price DECIMAL(26,6) DEFAULT 0,
    billing_frequency VARCHAR(50) DEFAULT 'one-time',
    commission_rate DECIMAL(5,2) DEFAULT 5.00,
    commission_flat DECIMAL(26,6) DEFAULT 0,
    features TEXT,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_package_type (package_type),
    INDEX idx_package_code (package_code),
    INDEX idx_is_active (is_active, deleted),
    INDEX idx_deleted (deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification Queue table (for WebSocket real-time delivery)
CREATE TABLE IF NOT EXISTS notification_queue (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    alert_id VARCHAR(36),
    user_id VARCHAR(36) NOT NULL,
    payload TEXT NOT NULL,
    status ENUM('pending', 'sent', 'acknowledged', 'failed') DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    sent_at DATETIME,
    acknowledged_at DATETIME,
    error_message VARCHAR(255),
    INDEX idx_user_status (user_id, status),
    INDEX idx_created (created_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification API Keys table (for webhook authentication)
CREATE TABLE IF NOT EXISTS notification_api_keys (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    description TEXT,
    created_by VARCHAR(36),
    created_at DATETIME NOT NULL,
    last_used_at DATETIME,
    is_active TINYINT(1) DEFAULT 1,
    deleted TINYINT(1) DEFAULT 0,
    INDEX idx_api_key (api_key),
    INDEX idx_active (is_active, deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification Rate Limiting table
CREATE TABLE IF NOT EXISTS notification_rate_limit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ip_time (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

EOF

    # Add custom fields to leads table (MySQL 8 compatible - check before adding)
    echo "Adding custom fields to leads table..."
    mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -sN -e "
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema='$SUITECRM_DATABASE_NAME' AND table_name='leads' AND column_name='funnel_type_c'
    " | grep -q '^0$' && mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -e "
        ALTER TABLE leads
            ADD COLUMN funnel_type_c VARCHAR(100) DEFAULT NULL,
            ADD COLUMN pipeline_stage_c VARCHAR(100) DEFAULT 'New',
            ADD COLUMN stage_entry_date_c DATETIME DEFAULT NULL,
            ADD COLUMN last_activity_date_c DATETIME DEFAULT NULL,
            ADD COLUMN follow_up_due_date_c DATE DEFAULT NULL,
            ADD COLUMN expected_revenue_c DECIMAL(26,6) DEFAULT 0,
            ADD COLUMN qualification_score_c INT(11) DEFAULT 0,
            ADD COLUMN demo_scheduled_c TINYINT(1) DEFAULT 0,
            ADD COLUMN demo_date_c DATETIME DEFAULT NULL,
            ADD COLUMN demo_completed_c TINYINT(1) DEFAULT 0,
            ADD INDEX idx_funnel_type_c (funnel_type_c),
            ADD INDEX idx_pipeline_stage_c (pipeline_stage_c),
            ADD INDEX idx_last_activity_c (last_activity_date_c),
            ADD INDEX idx_follow_up_due_c (follow_up_due_date_c);
    " 2>/dev/null && echo "  Leads custom fields added" || echo "  Leads custom fields already exist"

    # Add custom fields to opportunities table
    echo "Adding custom fields to opportunities table..."
    mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -sN -e "
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema='$SUITECRM_DATABASE_NAME' AND table_name='opportunities' AND column_name='funnel_type_c'
    " | grep -q '^0$' && mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -e "
        ALTER TABLE opportunities
            ADD COLUMN funnel_type_c VARCHAR(100) DEFAULT NULL,
            ADD COLUMN package_id_c VARCHAR(36) DEFAULT NULL,
            ADD COLUMN commission_amount_c DECIMAL(26,6) DEFAULT 0,
            ADD COLUMN commission_paid_c TINYINT(1) DEFAULT 0,
            ADD COLUMN commission_paid_date_c DATE DEFAULT NULL,
            ADD COLUMN demo_scheduled_c TINYINT(1) DEFAULT 0,
            ADD COLUMN demo_scheduled_date_c DATETIME DEFAULT NULL,
            ADD COLUMN demo_completed_c TINYINT(1) DEFAULT 0,
            ADD COLUMN demo_completed_date_c DATETIME DEFAULT NULL,
            ADD COLUMN proposal_sent_c TINYINT(1) DEFAULT 0,
            ADD COLUMN proposal_sent_date_c DATETIME DEFAULT NULL,
            ADD COLUMN source_lead_id_c VARCHAR(36) DEFAULT NULL,
            ADD INDEX idx_opp_funnel_type_c (funnel_type_c),
            ADD INDEX idx_opp_package_c (package_id_c);
    " 2>/dev/null && echo "  Opportunities custom fields added" || echo "  Opportunities custom fields already exist"

    # Add Verbacall custom fields to leads table
    echo "Adding Verbacall custom fields to leads table..."
    mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -sN -e "
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema='$SUITECRM_DATABASE_NAME' AND table_name='leads' AND column_name='verbacall_signup_c'
    " | grep -q '^0$' && mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -e "
        ALTER TABLE leads
            ADD COLUMN verbacall_signup_c TINYINT(1) DEFAULT 0,
            ADD COLUMN verbacall_last_login_c DATETIME DEFAULT NULL,
            ADD COLUMN verbacall_minutes_used_c DECIMAL(10,2) DEFAULT 0,
            ADD COLUMN verbacall_link_sent_c DATETIME DEFAULT NULL,
            ADD INDEX idx_verbacall_signup_c (verbacall_signup_c);
    " 2>/dev/null && echo "  Verbacall fields added to leads" || echo "  Verbacall fields already exist in leads"

    echo "Database tables and custom fields setup complete"
fi

# Register standard and custom ACL actions for PowerPack modules
echo ""
echo "Registering ACL actions for PowerPack modules..."

# Add standard ACL actions for all PowerPack modules (required for SuiteCRM 8 navigation)
STANDARD_ACL_EXISTS=$(mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -sN -e "
    SELECT COUNT(*) FROM acl_actions
    WHERE category='FunnelDashboard' AND name='access' AND deleted=0
" 2>/dev/null || echo "0")

if [ "$STANDARD_ACL_EXISTS" = "0" ]; then
    echo "  Adding standard ACL actions for all PowerPack modules..."
    mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" <<'EOF'
-- Standard ACL actions for PowerPack modules (required for SuiteCRM 8 to show them in navigation)
-- These actions allow admins to control access via Role Management

-- FunnelDashboard standard actions
INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'access', 'FunnelDashboard', 'module', 89, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='FunnelDashboard' AND name='access' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'view', 'FunnelDashboard', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='FunnelDashboard' AND name='view' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'list', 'FunnelDashboard', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='FunnelDashboard' AND name='list' AND deleted=0);

-- SalesTargets standard actions
INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'access', 'SalesTargets', 'module', 89, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='SalesTargets' AND name='access' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'view', 'SalesTargets', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='SalesTargets' AND name='view' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'list', 'SalesTargets', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='SalesTargets' AND name='list' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'edit', 'SalesTargets', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='SalesTargets' AND name='edit' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'delete', 'SalesTargets', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='SalesTargets' AND name='delete' AND deleted=0);

-- Packages standard actions
INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'access', 'Packages', 'module', 89, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='Packages' AND name='access' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'view', 'Packages', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='Packages' AND name='view' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'list', 'Packages', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='Packages' AND name='list' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'edit', 'Packages', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='Packages' AND name='edit' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'delete', 'Packages', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='Packages' AND name='delete' AND deleted=0);

-- TwilioIntegration standard actions
INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'access', 'TwilioIntegration', 'module', 89, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='TwilioIntegration' AND name='access' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'view', 'TwilioIntegration', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='TwilioIntegration' AND name='view' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'list', 'TwilioIntegration', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='TwilioIntegration' AND name='list' AND deleted=0);

-- TwilioIntegration custom action for recording permissions
INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'view_recordings', 'TwilioIntegration', 'module', -99, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='TwilioIntegration' AND name='view_recordings' AND deleted=0);

-- LeadJourney standard actions
INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'access', 'LeadJourney', 'module', 89, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='LeadJourney' AND name='access' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'view', 'LeadJourney', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='LeadJourney' AND name='view' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'list', 'LeadJourney', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='LeadJourney' AND name='list' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'edit', 'LeadJourney', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='LeadJourney' AND name='edit' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'delete', 'LeadJourney', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='LeadJourney' AND name='delete' AND deleted=0);

-- VerbacallIntegration standard actions
INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'access', 'VerbacallIntegration', 'module', 89, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='VerbacallIntegration' AND name='access' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'view', 'VerbacallIntegration', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='VerbacallIntegration' AND name='view' AND deleted=0);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'list', 'VerbacallIntegration', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM acl_actions WHERE category='VerbacallIntegration' AND name='list' AND deleted=0);

EOF
    echo "  ✓ Standard ACL actions registered"
else
    echo "  Standard ACL actions already exist"
fi

# Check if custom FunnelDashboard dashboard actions exist
ACTION_EXISTS=$(mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -sN -e "
    SELECT COUNT(*) FROM acl_actions
    WHERE category='FunnelDashboard' AND name='crodashboard' AND deleted=0
" 2>/dev/null || echo "0")

if [ "$ACTION_EXISTS" = "0" ]; then
    echo "  Adding custom dashboard ACL actions..."
    mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" <<'EOF'
-- Custom ACL actions for FunnelDashboard role-based dashboards
-- These will appear in Admin -> Role Management

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'crodashboard', 'FunnelDashboard', 'module', -99, 0
FROM DUAL WHERE NOT EXISTS (
    SELECT 1 FROM acl_actions WHERE category='FunnelDashboard' AND name='crodashboard' AND deleted=0
);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'salesopsdashboard', 'FunnelDashboard', 'module', -99, 0
FROM DUAL WHERE NOT EXISTS (
    SELECT 1 FROM acl_actions WHERE category='FunnelDashboard' AND name='salesopsdashboard' AND deleted=0
);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'bdmdashboard', 'FunnelDashboard', 'module', -99, 0
FROM DUAL WHERE NOT EXISTS (
    SELECT 1 FROM acl_actions WHERE category='FunnelDashboard' AND name='bdmdashboard' AND deleted=0
);

INSERT INTO acl_actions (id, date_entered, date_modified, modified_user_id, created_by, name, category, acltype, aclaccess, deleted)
SELECT UUID(), NOW(), NOW(), '1', '1', 'dashboard', 'FunnelDashboard', 'module', 90, 0
FROM DUAL WHERE NOT EXISTS (
    SELECT 1 FROM acl_actions WHERE category='FunnelDashboard' AND name='dashboard' AND deleted=0
);

EOF
    echo "  ✓ ACL actions registered"
else
    echo "  ACL actions already exist"
fi

echo ""
echo "Permissions can be managed in Admin -> Role Management -> FunnelDashboard"

# Enable modules in system display tabs
echo ""
echo "Enabling modules in navigation..."

# Add modules to system tabs via direct database manipulation
# Use subshell to prevent set -e from stopping script on errors
(
    set +e
    CURRENT_TABS=$(mysql $MYSQL_FLAGS -sN -e "SELECT value FROM config WHERE category='MySettings' AND name='tab';" 2>/dev/null | grep -v "Deprecated")

    if [ -n "$CURRENT_TABS" ]; then
        # Write PHP script to temp file to avoid escaping issues
        cat > /tmp/add_tabs.php << 'PHPEOF'
<?php
$currentTabs = unserialize(base64_decode($argv[1]));
if (!is_array($currentTabs)) {
    $currentTabs = array();
}

$powerPackModules = array('FunnelDashboard', 'SalesTargets', 'Packages', 'TwilioIntegration', 'LeadJourney');
$changed = false;

foreach ($powerPackModules as $module) {
    if (!in_array($module, $currentTabs)) {
        $currentTabs[] = $module;
        $changed = true;
    }
}

if ($changed) {
    echo base64_encode(serialize($currentTabs));
} else {
    echo 'UNCHANGED';
}
PHPEOF

        NEW_TABS=$(php /tmp/add_tabs.php "$CURRENT_TABS" 2>/dev/null)
        rm -f /tmp/add_tabs.php

        if [ "$NEW_TABS" != "UNCHANGED" ] && [ -n "$NEW_TABS" ]; then
            mysql $MYSQL_FLAGS -e "UPDATE config SET value='$NEW_TABS' WHERE category='MySettings' AND name='tab';" 2>/dev/null
            echo "  Modules added to system navigation"
        else
            echo "  Modules already in system navigation"
        fi
    else
        echo "  Note: System tabs not configured yet, modules will be available after first login"
    fi
) || true

echo "  ✓ Module navigation configured"

# Install Twilio click-to-call script for SuiteCRM 8 Angular UI
echo ""
echo "Installing Twilio click-to-call for Angular UI..."
if [ -f "/opt/bitnami/suitecrm/dist/twilio-click-to-call.js" ]; then
    cp /opt/bitnami/suitecrm/dist/twilio-click-to-call.js /bitnami/suitecrm/public/dist/
    
    # Inject script tag into index.html if not already present
    if [ -f "/bitnami/suitecrm/public/dist/index.html" ]; then
        if ! grep -q "twilio-click-to-call.js" /bitnami/suitecrm/public/dist/index.html; then
            sed -i 's|</body>|<script src="twilio-click-to-call.js"></script>\n</body>|' /bitnami/suitecrm/public/dist/index.html
            echo "  ✓ Click-to-call script injected into Angular UI"
        else
            echo "  Click-to-call script already present"
        fi
    else
        echo "  ⚠ Angular index.html not found - click-to-call may not work"
    fi
elif [ -f "/bitnami/suitecrm/modules/TwilioIntegration/click-to-call.js" ]; then
    # Fallback: copy from module directory
    cp /bitnami/suitecrm/modules/TwilioIntegration/click-to-call.js /bitnami/suitecrm/public/dist/twilio-click-to-call.js
    
    if [ -f "/bitnami/suitecrm/public/dist/index.html" ]; then
        if ! grep -q "twilio-click-to-call.js" /bitnami/suitecrm/public/dist/index.html; then
            sed -i 's|</body>|<script src="twilio-click-to-call.js"></script>\n</body>|' /bitnami/suitecrm/public/dist/index.html
            echo "  ✓ Click-to-call script injected (from module)"
        fi
    fi
else
    echo "  ⚠ Click-to-call script source not found"
fi

# Install Verbacall integration script for SuiteCRM 8 Angular UI
echo ""
echo "Installing Verbacall integration for Angular UI..."
if [ -f "/opt/bitnami/suitecrm/dist/verbacall-integration.js" ]; then
    cp /opt/bitnami/suitecrm/dist/verbacall-integration.js /bitnami/suitecrm/public/dist/

    # Inject script tag into index.html if not already present
    if [ -f "/bitnami/suitecrm/public/dist/index.html" ]; then
        if ! grep -q "verbacall-integration.js" /bitnami/suitecrm/public/dist/index.html; then
            sed -i 's|</body>|<script src="verbacall-integration.js"></script>\n</body>|' /bitnami/suitecrm/public/dist/index.html
            echo "  ✓ Verbacall integration script injected into Angular UI"
        else
            echo "  Verbacall integration script already present"
        fi
    else
        echo "  ⚠ Angular index.html not found - Verbacall buttons may not work"
    fi
else
    echo "  ⚠ Verbacall integration script source not found"
fi

# Clear all caches
echo ""
echo "Clearing caches..."
rm -rf /bitnami/suitecrm/cache/* 2>/dev/null || true
rm -rf /bitnami/suitecrm/public/legacy/cache/* 2>/dev/null || true
rm -rf /bitnami/suitecrm/public/legacy/themes/*/cache/* 2>/dev/null || true

# Set proper permissions
echo "Setting proper permissions..."
chown -R daemon:daemon /bitnami/suitecrm 2>/dev/null || true
chmod -R 755 /bitnami/suitecrm 2>/dev/null || true
chmod -R 777 /bitnami/suitecrm/cache 2>/dev/null || true
chmod 777 /opt/bitnami/php/var/run/session 2>/dev/null || true

echo ""
echo "============================================"
echo "Module installation completed!"
echo "============================================"
echo ""
echo "Installed modules:"
echo "  - TwilioIntegration - Click-to-call and SMS"
echo "  - LeadJourney - Customer journey tracking"
echo "  - FunnelDashboard - Sales funnel visualization"
echo "  - SalesTargets - BDM/Team target tracking with commissions"
echo "  - Packages - Service packages with pricing"
echo "  - VerbacallIntegration - Signup and payment link generation"
echo ""
echo "Role-based Dashboards:"
echo "  - CRO Dashboard: index.php?module=FunnelDashboard&action=crodashboard"
echo "  - Sales Ops Dashboard: index.php?module=FunnelDashboard&action=salesopsdashboard"
echo "  - BDM Dashboard: index.php?module=FunnelDashboard&action=bdmdashboard"
echo ""
echo "Access modules from the SuiteCRM navigation menu"
echo "or Admin > Display Modules and Subpanels"
echo ""
