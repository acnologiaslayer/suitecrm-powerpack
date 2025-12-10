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

# Verify modules exist
for MODULE in TwilioIntegration LeadJourney FunnelDashboard SalesTargets Packages; do
    if [ ! -f "/bitnami/suitecrm/modules/$MODULE/$MODULE.php" ]; then
        echo "ERROR: Module $MODULE not found at /bitnami/suitecrm/modules/$MODULE/"
        exit 1
    fi
    echo "Found module: $MODULE"
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
for MODULE in TwilioIntegration LeadJourney FunnelDashboard SalesTargets Packages; do
    if [ -d "/bitnami/suitecrm/modules/$MODULE" ]; then
        echo "  Copying $MODULE..."
        cp -r "/bitnami/suitecrm/modules/$MODULE" "/bitnami/suitecrm/public/legacy/modules/"
        chown -R daemon:daemon "/bitnami/suitecrm/public/legacy/modules/$MODULE"
    fi
done

# Copy extension files for modules
echo "Installing module extensions..."
mkdir -p /bitnami/suitecrm/public/legacy/custom/Extension/application/Ext/Language

if [ -d "/bitnami/suitecrm/modules/LeadJourney/Extensions" ]; then
    cp -r /bitnami/suitecrm/modules/LeadJourney/Extensions/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
fi

if [ -d "/bitnami/suitecrm/modules/TwilioIntegration/Extensions" ]; then
    cp -r /bitnami/suitecrm/modules/TwilioIntegration/Extensions/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
fi

# Copy main custom Extensions (includes PowerPackModules.php for nav display)
if [ -d "/bitnami/suitecrm/custom/Extension" ]; then
    cp -r /bitnami/suitecrm/custom/Extension/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
fi

# Create ActionDefs directory and copy custom ACL action definitions
mkdir -p /bitnami/suitecrm/public/legacy/custom/Extension/application/Ext/ActionDefs
if [ -f "/bitnami/suitecrm/custom/Extension/application/Ext/ActionDefs/PowerPackActions.php" ]; then
    cp /bitnami/suitecrm/custom/Extension/application/Ext/ActionDefs/PowerPackActions.php /bitnami/suitecrm/public/legacy/custom/Extension/application/Ext/ActionDefs/
fi

# Create compiled language extension for dropdown lists
mkdir -p /bitnami/suitecrm/public/legacy/custom/application/Ext/Language
cat > /bitnami/suitecrm/public/legacy/custom/application/Ext/Language/en_us.lang.ext.php << 'PHPEOF'
<?php
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
PHPEOF

chown -R daemon:daemon /bitnami/suitecrm/public/legacy/custom/

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

# Check if tables already exist
TABLES_EXIST=$(mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SUITECRM_DATABASE_NAME' AND table_name IN ('twilio_integration', 'twilio_audit_log', 'lead_journey', 'funnel_dashboard', 'sales_targets', 'packages');" 2>/dev/null || echo "0")

if [ "$TABLES_EXIST" = "6" ]; then
    echo "All module tables already exist, skipping migration"
else
    echo "Creating database tables (found $TABLES_EXIST/6 tables)..."
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

    echo "Database tables and custom fields setup complete"
fi

# Register custom ACL actions for FunnelDashboard
echo ""
echo "Registering ACL actions for role-based permissions..."

# Check if custom actions exist
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
echo ""
echo "Role-based Dashboards:"
echo "  - CRO Dashboard: index.php?module=FunnelDashboard&action=crodashboard"
echo "  - Sales Ops Dashboard: index.php?module=FunnelDashboard&action=salesopsdashboard"
echo "  - BDM Dashboard: index.php?module=FunnelDashboard&action=bdmdashboard"
echo ""
echo "Access modules from the SuiteCRM navigation menu"
echo "or Admin > Display Modules and Subpanels"
echo ""
