#!/bin/bash
set -e

echo "Starting SuiteCRM PowerPack..."

# Copy SuiteCRM files to volume if empty (first run)
if [ ! -f "/bitnami/suitecrm/public/index.php" ]; then
    echo "Copying SuiteCRM files to persistent volume..."
    cp -a /opt/bitnami/suitecrm/. /bitnami/suitecrm/
    
    # Create required directories with proper permissions
    echo "Creating required directories..."
    mkdir -p /bitnami/suitecrm/custom/Extension
    mkdir -p /bitnami/suitecrm/cache/upload/import
    mkdir -p /bitnami/suitecrm/cache/images
    mkdir -p /bitnami/suitecrm/cache/modules
    mkdir -p /bitnami/suitecrm/cache/themes
    mkdir -p /bitnami/suitecrm/upload
    
    # Also create directories in legacy path for the installer
    mkdir -p /bitnami/suitecrm/public/legacy/cache/upload/import
    mkdir -p /bitnami/suitecrm/public/legacy/cache/images
    mkdir -p /bitnami/suitecrm/public/legacy/cache/modules
    mkdir -p /bitnami/suitecrm/public/legacy/cache/themes
    mkdir -p /bitnami/suitecrm/public/legacy/cache/layout
    mkdir -p /bitnami/suitecrm/public/legacy/cache/pdf
    mkdir -p /bitnami/suitecrm/public/legacy/cache/xml
    mkdir -p /bitnami/suitecrm/public/legacy/cache/api
    mkdir -p /bitnami/suitecrm/public/legacy/cache/import
    mkdir -p /bitnami/suitecrm/public/legacy/cache/dashlets
    mkdir -p /bitnami/suitecrm/public/legacy/cache/feeds
    mkdir -p /bitnami/suitecrm/public/legacy/cache/smarty/templates_c
    mkdir -p /bitnami/suitecrm/public/legacy/cache/smarty/cache
    mkdir -p /bitnami/suitecrm/public/legacy/upload
    mkdir -p /bitnami/suitecrm/public/legacy/custom
    mkdir -p /bitnami/suitecrm/public/legacy/custom/include
    mkdir -p /bitnami/suitecrm/public/legacy/custom/themes/suite8/tpls
    mkdir -p /bitnami/suitecrm/public/legacy/data
    mkdir -p /bitnami/suitecrm/public/legacy/modules
    
    # Copy custom extensions (click-to-call JS for Angular UI)
    echo "Copying custom extensions..."
    if [ -f "/opt/bitnami/suitecrm/dist/twilio-click-to-call.js" ]; then
        echo "Installing Twilio click-to-call script for Angular UI..."
        cp /opt/bitnami/suitecrm/dist/twilio-click-to-call.js /bitnami/suitecrm/public/dist/
        
        # Inject script tag into index.html if not already present
        if [ -f "/bitnami/suitecrm/public/dist/index.html" ]; then
            if ! grep -q "twilio-click-to-call.js" /bitnami/suitecrm/public/dist/index.html; then
                echo "Injecting click-to-call script into Angular UI..."
                sed -i 's|</body>|<script src="twilio-click-to-call.js"></script>\n</body>|' /bitnami/suitecrm/public/dist/index.html
            fi
        fi
    fi
    
    # Set ownership and permissions
    echo "Setting ownership and permissions..."
    chown -R daemon:daemon /bitnami/suitecrm
    chmod -R 775 /bitnami/suitecrm
    chmod -R 777 /bitnami/suitecrm/cache
    chmod -R 777 /bitnami/suitecrm/public/legacy/cache
    chmod -R 777 /bitnami/suitecrm/upload
    chmod -R 777 /bitnami/suitecrm/public/legacy/upload
    chmod -R 777 /bitnami/suitecrm/public/legacy/custom
    chmod -R 777 /bitnami/suitecrm/public/legacy/data
    chmod -R 777 /bitnami/suitecrm/public/legacy/modules
    
    echo "Files copied successfully"
    
    # Mark that modules need installation after SuiteCRM setup
    touch /bitnami/suitecrm/.modules_pending
fi

# Fix session directory permissions
echo "Setting session directory permissions..."
chmod 777 /opt/bitnami/php/var/run/session 2>/dev/null || true
chown -R daemon:daemon /opt/bitnami/php/var/run/session 2>/dev/null || true

# Auto-install SuiteCRM if database credentials are provided and not yet installed
if [ ! -f "/bitnami/suitecrm/config.php" ] && [ -n "$SUITECRM_DATABASE_HOST" ] && [ "$SUITECRM_SKIP_INSTALL" != "yes" ]; then
    echo "Database configured - running silent installation..."
    
    # Ensure all required directories exist with proper permissions before installation
    echo "Ensuring all required directories exist..."
    mkdir -p /bitnami/suitecrm/cache/upload/import
    mkdir -p /bitnami/suitecrm/cache/images
    mkdir -p /bitnami/suitecrm/cache/modules
    mkdir -p /bitnami/suitecrm/cache/themes
    mkdir -p /bitnami/suitecrm/cache/jsLanguage
    mkdir -p /bitnami/suitecrm/cache/smarty/templates_c
    mkdir -p /bitnami/suitecrm/upload
    mkdir -p /bitnami/suitecrm/custom
    
    # Critical: Create all directories in legacy path where the installer checks
    mkdir -p /bitnami/suitecrm/public/legacy/cache/upload/import
    mkdir -p /bitnami/suitecrm/public/legacy/cache/upload/upgrades
    mkdir -p /bitnami/suitecrm/public/legacy/cache/images
    mkdir -p /bitnami/suitecrm/public/legacy/cache/modules
    mkdir -p /bitnami/suitecrm/public/legacy/cache/themes
    mkdir -p /bitnami/suitecrm/public/legacy/cache/layout
    mkdir -p /bitnami/suitecrm/public/legacy/cache/pdf
    mkdir -p /bitnami/suitecrm/public/legacy/cache/xml
    mkdir -p /bitnami/suitecrm/public/legacy/cache/api
    mkdir -p /bitnami/suitecrm/public/legacy/cache/import
    mkdir -p /bitnami/suitecrm/public/legacy/cache/dashlets
    mkdir -p /bitnami/suitecrm/public/legacy/cache/feeds
    mkdir -p /bitnami/suitecrm/public/legacy/cache/jsLanguage
    mkdir -p /bitnami/suitecrm/public/legacy/cache/smarty/templates_c
    mkdir -p /bitnami/suitecrm/public/legacy/cache/smarty/cache
    mkdir -p /bitnami/suitecrm/public/legacy/upload
    mkdir -p /bitnami/suitecrm/public/legacy/custom
    mkdir -p /bitnami/suitecrm/public/legacy/data
    
    chown -R daemon:daemon /bitnami/suitecrm
    chmod -R 777 /bitnami/suitecrm/cache
    chmod -R 777 /bitnami/suitecrm/public/legacy/cache
    chmod -R 777 /bitnami/suitecrm/upload
    chmod -R 777 /bitnami/suitecrm/public/legacy/upload
    chmod -R 777 /bitnami/suitecrm/public/legacy/custom
    chmod -R 777 /bitnami/suitecrm/public/legacy/data
    chmod -R 775 /bitnami/suitecrm/custom
    
    # Wait for database to be ready (simple TCP check)
    echo "Waiting for database connection..."
    for i in {1..30}; do
        if timeout 2 bash -c "</dev/tcp/$SUITECRM_DATABASE_HOST/${SUITECRM_DATABASE_PORT_NUMBER:-3306}" 2>/dev/null; then
            echo "✅ Database is ready!"
            break
        fi
        echo "Waiting for database... ($i/30)"
        sleep 2
    done
    
    # Run silent installation
    if /opt/bitnami/scripts/suitecrm/silent-install.sh; then
        echo "✅ SuiteCRM installed successfully!"
        
        # Install modules immediately after successful SuiteCRM installation
        echo ""
        echo "Installing PowerPack modules..."
        sleep 2  # Brief pause for file system to settle
        
        if /opt/bitnami/scripts/suitecrm/install-modules.sh; then
            echo "✅ Custom modules installed successfully!"
            
            # Enable modules in SuiteCRM 8 interface
            echo "Enabling modules in SuiteCRM 8 interface..."
            /opt/bitnami/scripts/suitecrm/enable-modules-suite8.sh || echo "Module enablement completed with warnings"
        else
            echo "⚠️  Module installation failed. You can install manually later."
            touch /bitnami/suitecrm/.modules_pending
        fi
        
        # Remove pending flag after attempt
        rm -f /bitnami/suitecrm/.modules_pending
    else
        echo "⚠️  Silent installation failed. You can install manually at http://your-domain/install.php"
        echo "⚠️  Or use: docker exec <container> suitecrm:app:install"
    fi
fi

# Auto-install custom modules if SuiteCRM is installed and modules are pending
if [ -f "/bitnami/suitecrm/config.php" ] && [ -f "/bitnami/suitecrm/.modules_pending" ]; then
    echo "Detected pending module installation..."
    echo "Installing PowerPack modules..."
    
    # Wait a moment for everything to settle
    sleep 3
    
    # Run module installation script
    if /opt/bitnami/scripts/suitecrm/install-modules.sh; then
        rm -f /bitnami/suitecrm/.modules_pending
        echo "✅ Custom modules installed successfully!"
        
        # Enable modules in SuiteCRM 8 interface
        echo "Enabling modules in SuiteCRM 8 interface..."
        /opt/bitnami/scripts/suitecrm/enable-modules-suite8.sh || echo "Module enablement completed with warnings"
    else
        echo "⚠️  Module installation failed. Will retry on next start."
        echo "⚠️  Or run manually: docker exec <container> /opt/bitnami/scripts/suitecrm/install-modules.sh"
    fi
fi

# UPGRADE SUPPORT: Always sync critical module files from image to volume
# This ensures upgrades apply new module configurations
if [ -f "/bitnami/suitecrm/config.php" ]; then
    echo "Syncing PowerPack module files..."

    # Copy updated module files from image
    for MODULE in TwilioIntegration LeadJourney FunnelDashboard SalesTargets Packages; do
        if [ -d "/opt/bitnami/suitecrm/modules/$MODULE" ]; then
            cp -r "/opt/bitnami/suitecrm/modules/$MODULE" "/bitnami/suitecrm/public/legacy/modules/" 2>/dev/null || true
        fi
    done

    # Copy updated extension files
    if [ -d "/opt/bitnami/suitecrm/custom/Extension" ]; then
        cp -r /opt/bitnami/suitecrm/custom/Extension/* /bitnami/suitecrm/public/legacy/custom/Extension/ 2>/dev/null || true
    fi

    # Re-run install script to update language files, module mappings, etc.
    echo "Updating module configurations..."
    /opt/bitnami/scripts/suitecrm/install-modules.sh 2>/dev/null || true

    # Fix permissions
    chown -R daemon:daemon /bitnami/suitecrm/public/legacy/modules/ 2>/dev/null || true
    chown -R daemon:daemon /bitnami/suitecrm/public/legacy/custom/ 2>/dev/null || true

    echo "Module sync complete"
fi

# Disable HTTPS vhost since SSL is handled by reverse proxy
if [ -f "/opt/bitnami/apache/conf/vhosts/suitecrm-https-vhost.conf" ]; then
    echo "Disabling HTTPS vhost (SSL handled by reverse proxy)..."
    mv /opt/bitnami/apache/conf/vhosts/suitecrm-https-vhost.conf \
       /opt/bitnami/apache/conf/vhosts/suitecrm-https-vhost.conf.disabled
fi

# Update Apache DocumentRoot to use the persistent volume with correct public directory
sed -i 's|DocumentRoot "/opt/bitnami/suitecrm"|DocumentRoot "/bitnami/suitecrm/public"|g' /opt/bitnami/apache/conf/httpd.conf
sed -i 's|DocumentRoot /opt/bitnami/suitecrm|DocumentRoot /bitnami/suitecrm/public|g' /opt/bitnami/apache/conf/vhosts/*.conf 2>/dev/null || true
sed -i 's|<Directory "/opt/bitnami/suitecrm">|<Directory "/bitnami/suitecrm/public">|g' /opt/bitnami/apache/conf/vhosts/*.conf 2>/dev/null || true

# Set ServerName to localhost since Nginx reverse proxy handles domain routing
sed -i 's|ServerName www.example.com|ServerName localhost|g' /opt/bitnami/apache/conf/vhosts/*.conf 2>/dev/null || true

# Set required environment variables
export BITNAMI_APP_NAME="suitecrm"

# Start Apache
exec /opt/bitnami/scripts/apache/run.sh
