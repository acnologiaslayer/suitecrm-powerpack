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
for MODULE in TwilioIntegration LeadJourney FunnelDashboard; do
    if [ ! -f "/bitnami/suitecrm/modules/$MODULE/$MODULE.php" ]; then
        echo "ERROR: Module $MODULE not found at /bitnami/suitecrm/modules/$MODULE/"
        exit 1
    fi
    echo "✓ Found module: $MODULE"
done

# Change to SuiteCRM directory
cd /bitnami/suitecrm/public/legacy || exit 1

echo ""
echo "Creating custom directories..."
mkdir -p /bitnami/suitecrm/custom/Extension/application/Ext/Include
mkdir -p /bitnami/suitecrm/custom/Extension/modules
mkdir -p /bitnami/suitecrm/custom/modules

# Copy extension files
echo "Installing module extensions..."
if [ -d "/bitnami/suitecrm/modules/LeadJourney/Extensions" ]; then
    cp -r /bitnami/suitecrm/modules/LeadJourney/Extensions/* /bitnami/suitecrm/custom/Extension/ 2>/dev/null || true
fi

if [ -d "/bitnami/suitecrm/modules/TwilioIntegration/Extensions" ]; then
    cp -r /bitnami/suitecrm/modules/TwilioIntegration/Extensions/* /bitnami/suitecrm/custom/Extension/ 2>/dev/null || true
fi

# Register modules in SuiteCRM
echo ""
echo "Registering modules in SuiteCRM..."

php -r "
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('sugarEntry', true);
\$_SERVER['REQUEST_METHOD'] = 'GET';

if (!file_exists('include/entryPoint.php')) {
    echo \"ERROR: Cannot find include/entryPoint.php\n\";
    exit(1);
}

require_once('include/entryPoint.php');

global \$beanList, \$beanFiles, \$moduleList;

// Define modules to install
\$modules = array('TwilioIntegration', 'LeadJourney', 'FunnelDashboard');

// Load current configuration
if (!file_exists('config.php')) {
    echo \"ERROR: config.php not found\n\";
    exit(1);
}

require_once('config.php');
global \$sugar_config;

// Initialize arrays if needed
if (!isset(\$sugar_config['moduleList'])) {
    \$sugar_config['moduleList'] = array();
    echo \"Initializing module list\n\";
}

echo \"Current module count: \" . count(\$sugar_config['moduleList']) . \"\\n\";

// Register each module
foreach (\$modules as \$module) {
    echo \"\\nProcessing module: \$module\\n\";
    
    // Add to module list
    if (!in_array(\$module, \$sugar_config['moduleList'])) {
        \$sugar_config['moduleList'][] = \$module;
        echo \"  Added to moduleList\n\";
    }
    
    // Register in bean list
    if (!isset(\$beanList[\$module])) {
        \$beanList[\$module] = \$module;
        \$beanFiles[\$module] = 'modules/' . \$module . '/' . \$module . '.php';
        echo \"  Added to beanList\n\";
    }
}

// Save configuration using Administration
require_once('modules/Administration/Administration.php');
\$admin = new Administration();

// Update display modules
\$admin->saveSetting('system', 'display_modules', base64_encode(serialize(\$sugar_config['moduleList'])));
echo \"Display modules updated\\n\";

// Enable modules in tab controller - use safer method
try {
    require_once('include/tabConfig.php');
    \$tabs = new TabController();
    
    // Get existing system tabs
    \$systemTabs = \$tabs->get_system_tabs();
    if (!is_array(\$systemTabs)) {
        \$systemTabs = array();
    }
    
    foreach (\$modules as \$module) {
        if (!isset(\$systemTabs[\$module])) {
            \$systemTabs[\$module] = \$module;
            echo \"Module \$module added to system tabs\\n\";
        } else {
            echo \"Module \$module already in system tabs\\n\";
        }
    }
    
    \$tabs->set_system_tabs(\$systemTabs);
    echo \"System tabs updated\\n\";
} catch (Exception \$e) {
    echo \"Warning: TabController update failed: \" . \$e->getMessage() . \"\\n\";
    echo \"Modules registered but may need manual tab configuration\\n\";
}

// Write to custom module registry
\$customInclude = '../../custom/Extension/application/Ext/Include/powerpack_modules.php';
\$includeContent = \"<?php\\n// PowerPack Module Registration\\n\";
foreach (\$modules as \$module) {
    \$includeContent .= \"\\\$beanList['\$module'] = '\$module';\\n\";
    \$includeContent .= \"\\\$beanFiles['\$module'] = 'modules/\$module/\$module.php';\\n\";
    \$includeContent .= \"\\\$moduleList[] = '\$module';\\n\";
}
file_put_contents(\$customInclude, \$includeContent);
echo \"Custom module registry created\n\";

echo \"\\nAll modules registered successfully!\\n\";
echo \"Total modules now: \" . count(\$sugar_config['moduleList']) . \"\\n\";
exit(0);
" 

if [ $? -ne 0 ]; then
    echo "ERROR: Module registration failed!"
    exit 1
fi

echo "✓ Module registration completed"

# Run Quick Repair and Rebuild
echo ""
echo "Running Quick Repair and Rebuild..."
php -r "
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('sugarEntry', true);
\$_SERVER['REQUEST_METHOD'] = 'GET';
require_once('include/entryPoint.php');

try {
    require_once('modules/Administration/QuickRepairAndRebuild.php');
    
    echo \"Starting repair process...\\n\";
    
    \$repair = new RepairAndClear();
    \$repair->show_output = false;
    
    // Call repair methods individually to avoid parameter issues
    \$repair->clearVardefs();
    \$repair->clearJsLangFiles();
    \$repair->clearDashlets();
    \$repair->clearSugarFeedCache();
    \$repair->clearThemeCache();
    \$repair->clearJsFiles();
    \$repair->rebuildExtensions();
    
    echo \"Quick Repair completed successfully\\n\";
    exit(0);
} catch (Exception \$e) {
    echo \"Warning: Quick Repair error: \" . \$e->getMessage() . \"\\n\";
    echo \"Continuing anyway...\\n\";
    exit(0);
}
"

if [ $? -ne 0 ]; then
    echo "WARNING: Quick Repair had errors but continuing..."
else
    echo "✓ Quick Repair completed"
fi

# Create database tables
echo ""
echo "Checking database tables..."
DB_PORT="${SUITECRM_DATABASE_PORT_NUMBER:-3306}"

# Check if SSL certificate exists and database host requires SSL
SSL_CA_PATH="${MYSQL_CLIENT_SSL_CA_FILE:-/opt/bitnami/mysql/certs/ca-certificate.crt}"

if [ -f "$SSL_CA_PATH" ] && [[ "$SUITECRM_DATABASE_HOST" == *"digitalocean.com"* ]]; then
    SSL_OPTS="--ssl-ca=$SSL_CA_PATH --ssl-verify-server-cert"
    echo "Using SSL connection to database with CA: $SSL_CA_PATH"
elif [ -f "$SSL_CA_PATH" ]; then
    # Try SSL but don't require it
    SSL_OPTS="--ssl-ca=$SSL_CA_PATH"
    echo "SSL certificate available at $SSL_CA_PATH, attempting SSL connection..."
else
    SSL_OPTS=""
    echo "Using standard database connection (no SSL cert found at $SSL_CA_PATH)..."
fi

# Check if tables already exist
TABLES_EXIST=$(mysql -h"$SUITECRM_DATABASE_HOST" -P"$DB_PORT" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" $SSL_OPTS "$SUITECRM_DATABASE_NAME" -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SUITECRM_DATABASE_NAME' AND table_name IN ('twilio_integration', 'lead_journey', 'funnel_dashboard');" 2>/dev/null || echo "0")

if [ "$TABLES_EXIST" = "3" ]; then
    echo "✓ All module tables already exist, skipping migration"
else
    echo "Creating database tables (found $TABLES_EXIST/3 tables)..."
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

EOF

    if [ $? -eq 0 ]; then
        echo "✓ Database tables created successfully"
    else
        echo "✗ Warning: Database table creation had issues"
    fi
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
echo "✓ Module installation completed!"
echo "============================================"
echo ""
echo "Installed modules:"
echo "  • TwilioIntegration - Click-to-call and SMS"
echo "  • LeadJourney - Customer journey tracking"  
echo "  • FunnelDashboard - Sales funnel visualization"
echo ""
echo "Access them from the SuiteCRM navigation menu"
echo "or Admin > Display Modules and Subpanels"
echo ""
