# Module Installation & Verification Guide

## What Was Fixed

### 1. Missing Essential Module Files
Added the following critical files that were missing:

**Menu.php files** - Define module navigation entries:
- `TwilioIntegration/Menu.php`
- `LeadJourney/Menu.php`
- `FunnelDashboard/Menu.php`

**Language files** - Provide module labels and text:
- `TwilioIntegration/language/en_us.lang.php`
- `LeadJourney/language/en_us.lang.php`
- `FunnelDashboard/language/en_us.lang.php`

### 2. Complete Rewrite of Installation Script
The `install-modules.sh` script was completely rewritten to:
- Properly register modules in SuiteCRM's module registry
- Use TabController to add modules to system tabs
- Create custom module registry file
- Set proper display_modules configuration
- Enable modules for all users by default
- Better error handling and user feedback

### 3. Simplified Suite 8 Enablement
The `enable-modules-suite8.sh` script was simplified to:
- Focus on TabController for proper module registration
- Set modules as non-user-editable (always visible)
- Work reliably across SuiteCRM 8.x versions

## How to Verify Modules Are Working

### Method 1: Check Admin Panel
1. Log in to SuiteCRM as administrator
2. Go to **Admin** > **Display Modules and Subpanels**
3. Look for these modules in the list:
   - **TwilioIntegration**
   - **LeadJourney**
   - **FunnelDashboard**
4. They should be checked/enabled by default

### Method 2: Check Navigation Menu
1. Look at the main navigation bar
2. You should see the modules listed:
   - **Twilio Integration**
   - **Lead Journey**
   - **Funnel Dashboard**

### Method 3: Direct URL Access
Access the modules directly via these URLs:

```
https://your-domain.com/legacy/index.php?module=TwilioIntegration&action=index
https://your-domain.com/legacy/index.php?module=LeadJourney&action=index
https://your-domain.com/legacy/index.php?module=FunnelDashboard&action=dashboard
```

### Method 4: Check Lead/Contact Detail Pages
1. Go to any **Lead** or **Contact** record
2. In the detail view, you should see:
   - **Call** button (from TwilioIntegration)
   - **SMS** button (from TwilioIntegration)
   - **View Journey** button (from LeadJourney)

### Method 5: Database Verification
Connect to your database and run:

```sql
-- Check if tables exist
SHOW TABLES LIKE '%twilio%';
SHOW TABLES LIKE '%journey%';
SHOW TABLES LIKE '%funnel%';

-- Should show:
-- twilio_integration
-- lead_journey
-- funnel_dashboard
```

## Troubleshooting

### Modules Not Showing Up

**Problem**: Modules don't appear in navigation or admin panel

**Solutions**:
1. Clear cache:
   ```bash
   docker exec -it <container-name> bash
   cd /bitnami/suitecrm
   rm -rf cache/* public/legacy/cache/*
   ```

2. Run Quick Repair:
   - Go to **Admin** > **Repair** > **Quick Repair and Rebuild**
   - Execute all recommended SQL queries

3. Manually re-run module installation:
   ```bash
   docker exec -it <container-name> bash
   /opt/bitnami/scripts/suitecrm/install-modules.sh
   /opt/bitnami/scripts/suitecrm/enable-modules-suite8.sh
   ```

### Buttons Not Showing on Lead Pages

**Problem**: Call/SMS/Journey buttons missing on Lead/Contact detail views

**Solutions**:
1. Verify extensions are copied:
   ```bash
   docker exec -it <container-name> bash
   ls -la /bitnami/suitecrm/custom/Extension/modules/Leads/Ext/Vardefs/
   ls -la /bitnami/suitecrm/custom/Extension/modules/Contacts/Ext/Vardefs/
   ```

2. Run Quick Repair and Rebuild to regenerate metadata

3. Check browser console for JavaScript errors

### Funnel Dashboard Shows Empty

**Problem**: Funnel Dashboard loads but shows no data

**Solution**: This is expected on fresh install. The dashboard will populate with data as you:
- Create and convert leads
- Log activities
- Move opportunities through stages

## Module Features

### TwilioIntegration
- **Configuration**: Set up Twilio Account SID, Auth Token, and Phone Number
- **Click-to-Call**: Call leads/contacts directly from SuiteCRM
- **SMS**: Send text messages from lead/contact records
- **Auto-logging**: Automatically log calls and SMS in SuiteCRM

### LeadJourney
- **Timeline View**: See complete customer journey for any lead/contact
- **Touchpoint Tracking**: Automatically track emails, calls, meetings, website visits
- **Visual Timeline**: Interactive timeline showing all interactions
- **Campaign Attribution**: Link touchpoints to marketing campaigns

### FunnelDashboard
- **Pipeline Visualization**: Visual funnel showing leads at each stage
- **Conversion Metrics**: Track conversion rates between stages
- **Stage Analysis**: See where leads are getting stuck
- **Forecast Reports**: Project future revenue based on pipeline

## Docker Image Information

**Latest Version**: `mahir009/suitecrm-powerpack:v2.0.1`
**Also Tagged As**: `mahir009/suitecrm-powerpack:latest`

**Image Digest**: `sha256:52b0884ec08bdf1a56d41616e7b9cc83221c46656a5204a356766cca303c2b20`

**What's Included**:
- SuiteCRM 8.9.1 (Bitnami base)
- 3 custom PowerPack modules with all necessary files
- Automated installation scripts
- Pre-configured for DigitalOcean Managed MySQL with SSL
- Proper file permissions and ownership

## Environment Variables

The modules work with your existing environment variables:
- `SUITECRM_DATABASE_HOST`
- `SUITECRM_DATABASE_USER`
- `SUITECRM_DATABASE_PASSWORD`
- `SUITECRM_DATABASE_NAME`
- `SUITECRM_DATABASE_PORT_NUMBER` (default: 3306)

For Twilio configuration, add these (optional):
- `TWILIO_ACCOUNT_SID`
- `TWILIO_AUTH_TOKEN`
- `TWILIO_PHONE_NUMBER`

## Getting Help

If modules still don't work after following this guide:

1. Check container logs:
   ```bash
   docker logs <container-name>
   ```

2. Check installation script output:
   ```bash
   docker logs <container-name> | grep -A 50 "Installing SuiteCRM PowerPack"
   ```

3. Verify database connection:
   ```bash
   docker exec -it <container-name> bash
   mysql -h"$SUITECRM_DATABASE_HOST" -u"$SUITECRM_DATABASE_USER" -p"$SUITECRM_DATABASE_PASSWORD" "$SUITECRM_DATABASE_NAME"
   ```

4. Check file permissions:
   ```bash
   docker exec -it <container-name> bash
   ls -la /bitnami/suitecrm/modules/
   ```
