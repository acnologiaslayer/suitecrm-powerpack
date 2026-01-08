# SuiteCRM PowerPack - AI Assistant Context

## Project Overview

**Repository**: `mahir009/suitecrm-powerpack`
**Docker Hub**: `mahir009/suitecrm-powerpack`
**Current Version**: v3.2.6
**Base Image**: Bitnami SuiteCRM (SuiteCRM 8 with Angular frontend + Legacy PHP)
**Production URL**: https://customer-relations.boomershub.com

This is a Docker-based SuiteCRM extension with seven custom modules for sales operations:

1. **TwilioIntegration** - Click-to-call, SMS, auto-logging
2. **LeadJourney** - Customer journey timeline tracking
3. **FunnelDashboard** - Sales funnel visualization with role-based dashboards
4. **SalesTargets** - BDM/Team target tracking with commissions
5. **Packages** - Service packages with pricing
6. **Webhooks** - Notification webhook API for external integrations
7. **NotificationHub** - Real-time WebSocket notification system
8. **VerbacallIntegration** - Signup and payment link generation for Leads

> **Note**: Custom InboundEmail module was removed in v3.2.0 - use native SuiteCRM InboundEmail + OAuth instead.

---

## Production Environment

**Server**: 5.161.183.69 (DigitalOcean)
**Docker Compose**: `/srv/docker-compose.yml`
**Container**: `suitecrm` (network_mode: host)
**Database**: DigitalOcean Managed MySQL (db-mysql-nyc3-92477-do-user-27688594-0.k.db.ondigitalocean.com:25060)
**SSL/Proxy**: nginx-proxy container

### SSH Access
```bash
sshpass -p '$SSH_PASSWORD' ssh root@5.161.183.69
# SSH_PASSWORD is stored in .env file
```

### Quick Commands
```bash
# View logs
docker logs suitecrm --tail 100

# Clear cache
docker exec suitecrm rm -rf /bitnami/suitecrm/cache/* /bitnami/suitecrm/public/legacy/cache/*

# Reset admin password
echo -n "NewPassword123!" | md5sum  # Get MD5 hash
docker exec suitecrm mysql --ssl-ca=/opt/bitnami/mysql/certs/ca-certificate.crt \
  -h $DB_HOST -P $DB_PORT -u $DB_USER -p'$DB_PASSWORD' $DB_NAME \
  -e "UPDATE users SET user_hash='<md5hash>' WHERE user_name='admin';"
# Database credentials are stored in .env file

# Upgrade production
cd /srv && docker-compose pull suitecrm && docker-compose up -d suitecrm
```

### OAuth Provider (Microsoft 365)

Already configured in database:
- **Provider ID**: `81e0b8f2-3a09-4e4f-85d6-c399767ee01b`
- **Client ID**: `8ff1f3c8-6ee4-4797-ac10-0401f4ce9a4d`
- **Tenant ID**: `97a925bf-827d-40e5-b59f-c7212634f437`
- **Authorize URL**: `https://login.microsoftonline.com/97a925bf-827d-40e5-b59f-c7212634f437/oauth2/v2.0/authorize`
- **Token URL**: `https://login.microsoftonline.com/97a925bf-827d-40e5-b59f-c7212634f437/oauth2/v2.0/token`
- **Scope**: `IMAP.AccessAsUser.All, offline_access`

---

## Email Integration (Office 365 / OAuth)

### Architecture (v3.2.0+)

Email sync now uses **native SuiteCRM InboundEmail** with OAuth, enhanced by a custom hook:

```
Office 365 Mailbox (or Gmail)
       │
       │ IMAP + OAuth2 (native SuiteCRM)
       ▼
SuiteCRM Native InboundEmail + Scheduler
       │
       │ after_save hook
       ▼
EmailLinkingService (custom/modules/EmailLinkingService.php)
  • Auto-links inbound emails to Leads/Contacts by email match
  • Logs to LeadJourney timeline
```

### Key Files

| File | Purpose |
|------|---------|
| `custom-modules/Extensions/modules/EmailLinkingService.php` | Auto-links emails to Leads/Contacts, logs to LeadJourney |
| `install-modules.sh` (creates hooks) | Installs `after_save` hook for Emails module |

### Office 365 OAuth Setup

1. **Register Azure AD App** at [portal.azure.com](https://portal.azure.com):
   - Redirect URI: `https://your-domain.com/legacy/index.php?module=ExternalOAuthConnection&action=callback`
   - API Permissions: `IMAP.AccessAsUser.All`, `Mail.Read`, `offline_access`, `User.Read`

2. **Environment Variables** (in `.env.local`):
   ```bash
   AZURE_CLIENT_ID=your-client-id
   AZURE_CLIENT_SECRET=your-client-secret
   AZURE_TENANT_ID=your-tenant-id
   ```

3. **SuiteCRM Configuration**:
   - Admin → Email → External OAuth Providers → Create "Microsoft 365"
   - Admin → Email → External OAuth Connections → Authorize
   - Admin → Email → Inbound Email → Create with OAuth connection
   - Admin → Schedulers → Enable "Check Inbound Mailboxes"

### OAuth Provider Settings

| Field | Value |
|-------|-------|
| Authorize URL | `https://login.microsoftonline.com/{tenant-id}/oauth2/v2.0/authorize` |
| Token URL | `https://login.microsoftonline.com/{tenant-id}/oauth2/v2.0/token` |
| Scope | `https://outlook.office365.com/IMAP.AccessAsUser.All offline_access` |

---

## Critical Architecture Knowledge

### SuiteCRM 8 Dual Architecture

SuiteCRM 8 has TWO interfaces that must be configured separately:

1. **Angular Frontend** (new) - Uses kebab-case module names
2. **Legacy PHP** (old) - Uses CamelCase module names

**CRITICAL FILES for module registration:**

| File | Purpose | Format |
|------|---------|--------|
| `/bitnami/suitecrm/public/legacy/include/portability/module_name_map.php` | Maps module names between Angular and Legacy | `$module_name_map["FunnelDashboard"] = ["frontend" => "funnel-dashboard", "core" => "FunnelDashboard"];` |
| `/bitnami/suitecrm/config/services/module/module_routing.yaml` | Angular routing configuration | YAML with kebab-case keys |
| `/bitnami/suitecrm/public/legacy/custom/application/Ext/Include/modules.ext.php` | Legacy module registration | `$beanList`, `$beanFiles`, `$moduleList` |
| `/bitnami/suitecrm/public/legacy/custom/application/Ext/Language/en_us.lang.ext.php` | Display names & dropdowns | `$app_list_strings['moduleList']['FunnelDashboard'] = 'Funnel Dashboard';` |

### Module Name Mappings

```php
// module_name_map.php entries (REQUIRED for Angular nav to work)
$module_name_map["FunnelDashboard"] = ["frontend" => "funnel-dashboard", "core" => "FunnelDashboard"];
$module_name_map["SalesTargets"] = ["frontend" => "sales-targets", "core" => "SalesTargets"];
$module_name_map["Packages"] = ["frontend" => "packages", "core" => "Packages"];
$module_name_map["TwilioIntegration"] = ["frontend" => "twilio-integration", "core" => "TwilioIntegration"];
$module_name_map["LeadJourney"] = ["frontend" => "lead-journey", "core" => "LeadJourney"];
```

### Module Routing (YAML)

```yaml
# module_routing.yaml entries
funnel-dashboard:
  index: true
  list: true
  record: false
sales-targets:
  index: true
  list: true
  record: true
packages:
  index: true
  list: true
  record: true
twilio-integration:
  index: true
  list: true
  record: false
lead-journey:
  index: true
  list: true
  record: true
```

---

## Directory Structure

```
/home/mahir/Projects/suitecrm/
├── Dockerfile                    # Docker build instructions
├── docker-compose.yml            # Local development
├── docker-compose.production.yml # Production template (external DB)
├── docker-entrypoint.sh          # Container startup script
├── .env.example                  # Environment template
│
├── custom-modules/               # SOURCE modules (copied during build)
│   ├── FunnelDashboard/
│   │   ├── FunnelDashboard.php   # Bean class
│   │   ├── Menu.php              # Module menu items
│   │   ├── metadata/             # SuiteCRM metadata
│   │   ├── views/                # Dashboard views
│   │   │   ├── view.dashboard.php
│   │   │   ├── view.crodashboard.php
│   │   │   ├── view.salesopsdashboard.php
│   │   │   └── view.bdmdashboard.php
│   │   ├── language/
│   │   │   └── en_us.lang.php    # Labels and translations
│   │   └── acl/
│   │       └── SugarACLFunnelDashboard.php
│   │
│   ├── SalesTargets/             # Target tracking module
│   ├── Packages/                 # Service packages module
│   ├── TwilioIntegration/        # Twilio calling module
│   ├── LeadJourney/              # Journey timeline module
│   ├── Webhooks/                 # Notification webhook API
│   ├── NotificationHub/          # Real-time notifications
│   ├── VerbacallIntegration/     # Verbacall signup/payment links
│   └── Extensions/               # App-level extensions
│       └── application/
│           └── Ext/
│               ├── Include/
│               ├── Language/
│               └── ActionDefs/
│
├── install-scripts/
│   ├── install-modules.sh        # MAIN installation script (runs in container)
│   ├── enable-modules-suite8.sh  # Enable modules in user preferences
│   └── silent-install.sh         # Automated SuiteCRM installation
│
├── config/
│   ├── custom-extensions/
│   │   └── dist/
│   │       ├── twilio-click-to-call.js   # Click-to-call for Angular UI
│   │       ├── notification-ws.js        # WebSocket notification client
│   │       └── verbacall-integration.js  # Verbacall buttons for Leads
│   └── notification-websocket/           # Node.js WebSocket server
│       ├── server.js
│       └── package.json
│
└── docs/
    ├── UPGRADE_GUIDE.md              # Upgrade instructions
    └── NOTIFICATION_WEBHOOK.md       # Webhook API documentation
```

### Container Paths (Bitnami)

```
/opt/bitnami/suitecrm/           # Source modules (build-time copy)
/bitnami/suitecrm/               # Runtime SuiteCRM root
/bitnami/suitecrm/public/legacy/ # Legacy PHP interface
/bitnami/suitecrm/cache/         # Angular cache
/bitnami/suitecrm/config/        # Angular configuration
```

---

## Database Schema

### Custom Tables

```sql
-- PowerPack module tables
twilio_integration         -- Twilio configuration
twilio_audit_log           -- Call/SMS audit trail
lead_journey               -- Touchpoint tracking
funnel_dashboard           -- Dashboard configurations
sales_targets              -- BDM/Team targets
packages                   -- Service packages
notification_queue         -- WebSocket notification queue
notification_api_keys      -- Webhook authentication keys
notification_rate_limit    -- Rate limiting for webhook API

-- Custom fields added to standard tables
leads.funnel_type_c           -- VARCHAR(100) - Funnel category
leads.pipeline_stage_c        -- VARCHAR(100) - Current stage
leads.demo_scheduled_c        -- TINYINT(1) - Demo flag
leads.expected_revenue_c      -- DECIMAL(26,6)
leads.verbacall_signup_c      -- TINYINT(1) - Verbacall signup status
leads.verbacall_link_sent_c   -- DATETIME - When signup link was sent
opportunities.funnel_type_c   -- VARCHAR(100)
opportunities.package_id_c    -- VARCHAR(36) - FK to packages
opportunities.commission_amount_c -- DECIMAL(26,6)
```

### ACL Actions (Role Management)

Custom ACL actions in `acl_actions` table for role-based dashboard access:

```sql
-- FunnelDashboard custom actions
category='FunnelDashboard', name='crodashboard'        -- CRO Dashboard access
category='FunnelDashboard', name='salesopsdashboard'   -- Sales Ops Dashboard access
category='FunnelDashboard', name='bdmdashboard'        -- BDM Dashboard access
category='FunnelDashboard', name='dashboard'           -- Main dashboard access
```

---

## Role-Based Dashboards

| Dashboard | URL | Target Role |
|-----------|-----|-------------|
| CRO Dashboard | `?module=FunnelDashboard&action=crodashboard` | Chief Revenue Officer |
| Sales Ops Dashboard | `?module=FunnelDashboard&action=salesopsdashboard` | Sales Operations |
| BDM Dashboard | `?module=FunnelDashboard&action=bdmdashboard` | Business Development Managers |
| Main Dashboard | `?module=FunnelDashboard&action=dashboard` | All users |

### Funnel Types (Sales Verticals)

```php
$app_list_strings['funnel_type_list'] = [
    'Realtors' => 'Realtors',
    'Senior_Living' => 'Senior Living',
    'Home_Care' => 'Home Care',
];
```

### Pipeline Stages

```php
$app_list_strings['pipeline_stage_list'] = [
    'New', 'Contacting', 'Contacted', 'Qualified', 'Interested',
    'Opportunity', 'Demo_Visit', 'Demo_Completed', 'Proposal',
    'Negotiation', 'Closed_Won', 'Closed_Lost', 'Disqualified'
];
```

---

## Common Issues & Fixes

### Issue: Module shows "Not Authorized" in Angular nav
**Cause**: Missing entry in `module_name_map.php`
**Fix**: Add mapping in `install-modules.sh` and rebuild

### Issue: Module shows CamelCase name (e.g., "FunnelDashboard")
**Cause**: Missing `$app_list_strings['moduleList']` entry
**Fix**: Add display name in language extension file

### Issue: Module not visible after installation
**Cause**: Not in system tabs or user hidden tabs
**Fix**: Run `enable-modules-suite8.sh` or check TabController

### Issue: Cache permission errors
**Fix**: `chmod -R 777 /bitnami/suitecrm/cache /bitnami/suitecrm/public/legacy/cache`

### Issue: ACL blocking admin access
**Cause**: Complex ACL checks failing before `isAdmin()` check
**Fix**: Simplify `SugarACL*.php` to `return true;` and use Role Management UI

### Issue: Database connection timeout with external database
**Cause**: Docker bridge network cannot reach managed databases on non-standard ports
**Fix**: Use `network_mode: host` in docker-compose.yml (see `docker-compose.production.yml`)

### Issue: JS files not loading in Angular UI
**Cause**: Files copied to wrong location or missing from index.html
**Fix**: Files must be in `/bitnami/suitecrm/public/dist/` and injected into index.html

### Issue: Modules not appearing after upgrade
**Cause**: install-modules.sh checking wrong source path
**Fix**: Script now checks `/opt/bitnami/suitecrm/modules/` (image source) first

---

## Production Deployment

### External Database Requirements

When connecting to managed databases (DigitalOcean, AWS RDS, Azure), you **MUST** use `network_mode: host`:

```yaml
# docker-compose.production.yml
services:
  suitecrm:
    image: mahir009/suitecrm-powerpack:latest
    network_mode: host  # CRITICAL for external DB access
    env_file:
      - ./suitecrm/.env
```

**Why?** Docker bridge networks cannot reliably reach external IPs on non-standard ports (like DigitalOcean's 25060).

### Nginx Reverse Proxy with Host Network

When suitecrm uses `network_mode: host`, nginx must proxy to `host.docker.internal`:

```nginx
location / {
    proxy_pass http://host.docker.internal:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

### WebSocket Port Configuration

The WebSocket server runs on port 3001. For SSL:

```nginx
server {
    listen 3001 ssl;
    server_name your-domain.com;

    ssl_certificate /etc/nginx/certs/your-domain.crt;
    ssl_certificate_key /etc/nginx/certs/your-domain.key;

    location / {
        proxy_pass http://host.docker.internal:3001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 86400;
    }
}
```

### Upgrade Checklist

1. **Backup database** before upgrading
2. **Pull new image**: `docker pull mahir009/suitecrm-powerpack:vX.Y.Z`
3. **Update docker-compose.yml** with new image tag
4. **Restart container**: `docker-compose down && docker-compose up -d`
5. **Verify**: Check logs for "Module sync complete" message
6. **Clear browser cache** if UI elements don't appear

See `docs/UPGRADE_GUIDE.md` for detailed upgrade instructions.

---

## Development Workflow

### Local Testing

```bash
# Build and test locally
docker build -t suitecrm-test .
docker run -d --name suitecrm-test -p 8080:8080 \
  -e SUITECRM_DATABASE_HOST=host.docker.internal \
  -e SUITECRM_DATABASE_USER=root \
  -e SUITECRM_DATABASE_PASSWORD=password \
  -e SUITECRM_DATABASE_NAME=suitecrm \
  suitecrm-test

# Check logs
docker logs -f suitecrm-test

# Execute commands in container
docker exec -it suitecrm-test bash
```

### Deploying Updates

```bash
# Build and push to Docker Hub
docker build -t mahir009/suitecrm-powerpack:X.Y.Z -t mahir009/suitecrm-powerpack:latest .
docker login -u mahir009
docker push mahir009/suitecrm-powerpack:X.Y.Z
docker push mahir009/suitecrm-powerpack:latest
```

### Clearing Caches

```bash
# Inside container
rm -rf /bitnami/suitecrm/cache/*
rm -rf /bitnami/suitecrm/public/legacy/cache/*
```

---

## Key Files to Edit

| Task | File(s) |
|------|---------|
| Add new module | `custom-modules/NewModule/`, `install-scripts/install-modules.sh` |
| Change module display name | `custom-modules/*/language/en_us.lang.php`, `install-scripts/install-modules.sh` (moduleList) |
| Add dropdown options | `install-scripts/install-modules.sh` (app_list_strings) |
| Add database table | `install-scripts/install-modules.sh` (CREATE TABLE) |
| Add custom field | `install-scripts/install-modules.sh` (ALTER TABLE) |
| Configure ACL | `custom-modules/*/acl/`, `install-scripts/install-modules.sh` (acl_actions INSERT) |
| Add menu item | `custom-modules/*/Menu.php` |
| Add view/action | `custom-modules/*/views/view.*.php` |

---

## Environment Variables

```bash
# Database (required)
SUITECRM_DATABASE_HOST=
SUITECRM_DATABASE_PORT_NUMBER=3306
SUITECRM_DATABASE_NAME=suitecrm
SUITECRM_DATABASE_USER=
SUITECRM_DATABASE_PASSWORD=

# SSL for managed databases (DigitalOcean, AWS RDS, etc.)
MYSQL_SSL_CA=/opt/bitnami/mysql/certs/ca-certificate.crt
MYSQL_CLIENT_ENABLE_SSL=yes

# SuiteCRM
SUITECRM_USERNAME=admin
SUITECRM_PASSWORD=
SUITECRM_EMAIL=admin@example.com
SUITECRM_SITE_URL=https://your-domain.com

# Twilio Integration (optional)
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_PHONE_NUMBER=
TWILIO_FALLBACK_PHONE=

# Verbacall Integration (optional)
VERBACALL_API_URL=https://demo-ai.verbacall.com    # API endpoint
VERBACALL_APP_URL=https://app.verbacall.com        # Frontend URL

# WebSocket Notifications (optional)
NOTIFICATION_JWT_SECRET=your-secure-random-string
NOTIFICATION_WS_URL=wss://your-domain.com:3001
NOTIFICATION_WS_PORT=3001
```

---

## Git Workflow

```bash
# After making changes
git add -A
git commit -m "vX.Y.Z: Description of changes"
git push origin main

# Build and deploy
docker build -t mahir009/suitecrm-powerpack:X.Y.Z -t mahir009/suitecrm-powerpack:latest .
docker push mahir009/suitecrm-powerpack:X.Y.Z
docker push mahir009/suitecrm-powerpack:latest
```

---

## Version History (Recent)

- **v3.2.6** - Notifications for SMS/Email, cron setup, email sync fixes:
  - Add real-time notifications for inbound SMS via NotificationHub
  - Add real-time notifications for inbound emails linked to leads
  - Set up cron daemon in docker-entrypoint.sh for SuiteCRM Scheduler
  - Register `processInboundEmails` and `refreshOAuthTokens` schedulers
  - Fix email sync: set `assigned_user_id`, `unread` status, `emails_text` storage
  - Add `storeEmailText()` method for proper email display in SuiteCRM
  - Cleanup notification queue daily at 2 AM via cron
- **v3.2.1** - Fix InboundEmail OAuth configuration "Empty user" error:
  - Fixed bug where OAuth email address field was not being read correctly
  - Added InboundEmailClient.php for IMAP connection with OAuth support
  - Added view.config.php for custom InboundEmail OAuth configuration UI
  - Updated install-modules.sh to copy InboundEmail views during installation
- **v3.2.0** - Email architecture refactoring (January 2026):
  - **BREAKING**: Removed custom `InboundEmail` module - use native SuiteCRM InboundEmail + OAuth
  - Created `EmailLinkingService` for auto-linking emails to Leads/Contacts via `after_save` hook
  - Auto-logs inbound emails to LeadJourney timeline
  - Removed SES webhook (view.ses_email.php) - not needed with IMAP sync
  - Added Azure OAuth environment variables: `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`, `AZURE_TENANT_ID`
  - Updated Dockerfile, install-modules.sh, docker-compose.yml
  - Simplified email setup: just configure OAuth in SuiteCRM Admin panel
  - **Deployed to production 2026-01-06** (customer-relations.boomershub.com)
  - Added InboundEmail controller.php on production for legacy action routing
  - Admin password reset to value in production .env (`SecurePassword123!`)
- **v3.1.19** - Fix NotifyWS auth with standalone token endpoint:
  - Renamed to `notification_token.php` to avoid conflict with existing notification_webhook.php
  - Updated `notification-ws.js` to use `/legacy/notification_token.php` endpoint
  - Fixes WebSocket authentication by bypassing SuiteCRM's auth redirect
- **v3.1.18** - (superseded by v3.1.19)
- **v3.1.17** - Fix NotifyWS auth token endpoint:
  - Fixed `action_getToken()` in NotificationHub controller returning mixed output
  - Added `exit;` after JSON response to prevent extra output
  - Added output buffer cleanup (`ob_clean()`) for clean JSON responses
  - Set `$this->view = 'ajax'` to prevent view loading
  - Added try/catch for proper error handling
  - Fixes WebSocket authentication token parsing errors
- **v3.1.16** - Fix JS 404 errors for custom scripts:
  - Fixed script injection paths in docker-entrypoint.sh and install-modules.sh
  - Scripts now correctly reference `dist/` prefix (e.g., `src="dist/twilio-click-to-call.js"`)
  - Added missing `recording_url` and `thread_id` columns to `lead_journey` table
  - Fixes Timeline API 500 error
- **v3.1.15** - Add Webhooks module submenu and views:
  - Added Menu.php for Webhooks module (was missing)
  - Created view.index.php for API Keys management
  - Created view.docs.php for API documentation
- **v3.1.14** - Fix SMS filter and Twilio call direction detection:
  - Fixed SMS filter in Angular timeline to include `inbound_sms` type (was only matching `sms_inbound`)
  - Fixed bug where browser outgoing calls were incorrectly detected as incoming
  - Twilio marks browser-to-TwiML-App calls as Direction=inbound
  - Detection now checks From field FIRST (client:xxx = outgoing, phone = incoming)
  - Fixes "Bad data passed in" error and empty To number issues
- **v3.1.13** - Messenger-style compose bar in timeline modal
- **v3.1.12** - Add communication action buttons (Call, SMS, Email) to timeline
- **v3.1.11** - Unified timeline with SMS, calls, and emails from all sources
- **v3.1.10** - Fix InboundEmail OAuth and timeline email display:
  - Fixed InboundEmail module to use core `inbound_email` table instead of custom table
  - Added missing helper methods: `setStatus()`, `updateLastPoll()`, `getStoredOptions()`
  - Fixed OAuth connection loading in InboundEmailClient using `newBean()->retrieve()` pattern
  - Fixed LeadJourney timeline email query: use `date_sent_received` column (not `date_sent`)
  - Added `emails_text` table join for email content in timeline
  - Fixed SQL string quoting with `$db->quoted()` for proper query matching
- **v3.1.9** - Include caller/recipient names in call logs and recordings
- **v2.5.28** - Fix production upgrade issues:
  - Fix JS files copied to wrong location (now `/bitnami/suitecrm/public/dist/`)
  - Fix module path check in install-modules.sh (checks image source first)
  - Add JS injection during upgrade (not just first run)
  - Add docker-compose.production.yml for external database deployments
  - Add production deployment documentation
- **v2.5.19** - Hybrid notification system (REST API + WebSocket)
- **v2.5.18** - Twilio browser-based calling enhancements
- **v2.5.17** - VerbacallIntegration module, NotificationHub, Webhooks
- **v2.5.8** - Fix FunnelDashboard not showing in production:
  - Add view.index.php to redirect index action to dashboard
  - Add action_index() to controller for SuiteCRM 8 Angular navigation
  - Fix MYSQL_FLAGS undefined variable in install-modules.sh
  - Add standard ACL actions (access, view, list) for all PowerPack modules
  - Fix module_routing.yaml indentation for proper YAML append
  - Fix bean_implements() to properly enable ACL
- **v2.5.7** - Fix module display names in navigation (moduleList entries)
- **v2.5.6** - Fix SuiteCRM 8 Angular navigation (module_name_map.php)
- **v2.5.5** - Add SuiteCRM 8 module routing (module_routing.yaml)
- **v2.5.4** - Auto-enable modules in system navigation
- **v2.4.0** - Complete Twilio Integration with Security & Automation

---

## Useful Commands

```bash
# Test database connection from container
docker exec suitecrm-test mysql -h$SUITECRM_DATABASE_HOST -u$SUITECRM_DATABASE_USER -p$SUITECRM_DATABASE_PASSWORD -e "SELECT 1"

# Check installed modules
docker exec suitecrm-test cat /bitnami/suitecrm/public/legacy/custom/application/Ext/Include/modules.ext.php

# Check module name mappings
docker exec suitecrm-test grep -A5 "FunnelDashboard" /bitnami/suitecrm/public/legacy/include/portability/module_name_map.php

# Repair and rebuild (legacy)
docker exec suitecrm-test php /bitnami/suitecrm/public/legacy/bin/console suitecrm:app:repair
```

---

## Notes for AI Assistants

1. **Always check `install-modules.sh`** - This is the main installation script that runs during container startup
2. **Module changes need Docker rebuild** - Changes to `custom-modules/` require rebuilding the Docker image
3. **SuiteCRM 8 = Angular + Legacy** - Both systems must be configured for modules to work fully
4. **Bitnami paths differ from standard** - Use `/bitnami/suitecrm/` not `/var/www/html/`
5. **Cache clearing is often needed** - After config changes, clear both Angular and Legacy caches
6. **Test in container first** - Use `docker exec` to test changes before committing
7. **JS files go in `/public/dist/`** - Angular UI JS files must be in `/bitnami/suitecrm/public/dist/` and injected into index.html
8. **Module source is `/opt/bitnami/`** - During upgrades, modules are sourced from image at `/opt/bitnami/suitecrm/modules/`
9. **External DB requires host network** - Use `network_mode: host` for managed databases (DigitalOcean, AWS RDS)
10. **WebSocket runs on port 3001** - Separate SSL nginx block needed for WSS
11. **Email uses native SuiteCRM OAuth** - No custom InboundEmail module; use Admin → Email → Inbound Email with OAuth connection
12. **EmailLinkingService** - Custom hook in `custom/modules/EmailLinkingService.php` auto-links emails to Leads/Contacts
13. **SuiteCRM 8 URL routing** - Angular URLs use `#/module-name/action`, legacy uses `index.php?module=X&action=Y`. Custom legacy actions need controller.php with action methods.
14. **OAuth Connection URLs**:
    - Create OAuth Connection: `#/external-oauth-connection/edit`
    - List OAuth Providers: `#/external-oauth-provider/index`
    - Inbound Email List: `#/inbound-email/index`
15. **Production secrets in `/srv/suitecrm/.env`** - Database and Twilio credentials stored here

---

## Twilio Integration Details

### Twilio Configuration (Production)
```php
// In config_override.php (values from environment variables)
$sugar_config['twilio_account_sid'] = getenv('TWILIO_ACCOUNT_SID');
$sugar_config['twilio_auth_token'] = getenv('TWILIO_AUTH_TOKEN');
$sugar_config['twilio_phone_number'] = getenv('TWILIO_PHONE_NUMBER');
$sugar_config['twilio_twiml_app_sid'] = getenv('TWILIO_TWIML_APP_SID');
$sugar_config['twilio_api_key'] = getenv('TWILIO_API_KEY');
$sugar_config['twilio_api_secret'] = getenv('TWILIO_API_SECRET');
$sugar_config['twilio_enable_click_to_call'] = true;
$sugar_config['twilio_enable_auto_logging'] = true;
$sugar_config['twilio_enable_recordings'] = true;
// All Twilio credentials stored in .env file
```

### Webhook URLs (Configure in Twilio Console)
| Setting | URL |
|---------|-----|
| TwiML App Voice URL | `https://customer-relations.boomershub.com/legacy/twilio_webhook.php?action=twiml` |
| Phone Number Voice URL | `https://customer-relations.boomershub.com/legacy/twilio_webhook.php?action=inbound` |
| Phone Number Status Callback | `https://customer-relations.boomershub.com/legacy/twilio_webhook.php?action=status` |
| Phone Number SMS URL | `https://customer-relations.boomershub.com/legacy/twilio_webhook.php?action=sms` |
| Recording Status Callback | `https://customer-relations.boomershub.com/legacy/twilio_webhook.php?action=recording` |

### Phone Assignment (Required for Inbound Routing)
The `twilio_integration` table maps phone numbers to users for inbound call routing:
```sql
-- Current assignment
Phone: +18456713651 -> User ID: 1 (admin)
```

### Call/SMS Logging Flow
```
Twilio Call/SMS
      │
      │ Webhook POST to twilio_webhook.php
      ▼
handleStatus() / handleSms()
      │
      │ lookupCallerByPhone() - finds Lead/Contact by phone
      ▼
logCallToCRM() / handleSms()
      │
      ├── INSERT INTO calls (for calls)
      └── INSERT INTO lead_journey (for timeline)
```

---

## Current Session Context (January 2026)

### Completed Tasks
- ✅ Deployed v3.2.0 to production (removed custom InboundEmail, added EmailLinkingService)
- ✅ Reset admin password to `SecurePassword123!` (from production .env)
- ✅ Microsoft 365 OAuth Provider already configured in database
- ✅ Fixed database schema - added missing columns:
  - `lead_journey`: `assigned_user_id`, `summary`, `thread_id`, `recording_url`
  - `calls`: `recording_url`, `recording_sid`, `twilio_call_sid`
  - `twilio_audit_log`: `log_type`, `direction`, `from_number`, `to_number`, `status`
- ✅ Created phone assignment in `twilio_integration` table (+18456713651 → admin)
- ✅ Verified webhook logging works (simulated calls/SMS log to lead_journey)
- ✅ Inbound calls working and showing on timeline
- ✅ Inbound SMS working and showing on timeline
- ✅ Updated twilio_webhook.php with statusCallback on `<Number>` elements
- ✅ Added `record="record-from-answer-dual"` for call recordings

### Pending Tasks - Twilio
- ⏳ **Outbound calls not showing on timeline** - Status callbacks may not be reaching webhook
  - TwiML App may need Status Callback URL configured in Twilio Console
  - Check Twilio Console → TwiML Apps → [TWIML_APP_SID from .env] → Voice Status Callback URL
- ⏳ **Call recordings not showing** - Need to verify:
  - Recording Status Callback URL configured in Twilio Console
  - `handleRecording()` function storing recording_url in calls/lead_journey tables
  - `twilio_enable_recordings` is set to true (confirmed)

### Completed Tasks - Email
- ✅ **Fixed outbound email** - Updated system outbound email to use Amazon SES
  - Server: email-smtp.us-east-1.amazonaws.com:587
  - User: (stored in database outbound_email table)
  - Auth: TLS with basic auth
  - From: no-reply@verbacall.com

### Pending Tasks - Email
- ⏳ **Inbound email configuration** - Use native SuiteCRM InboundEmail + OAuth

### Inbound Email Setup (Office 365 OAuth)

**Step 1: Create OAuth Connection**
1. Go to: `https://customer-relations.boomershub.com/#/external-oauth-connection/edit`
2. Fill in:
   - **Name**: Office 365 - [Your Name]
   - **Type**: Microsoft
   - **External OAuth Provider**: Select "Microsoft 365" (already configured)
3. Click **Authorize** → Login with your Office 365 account → Grant permissions
4. Save

**Step 2: Create Inbound Email Account**
1. Go to: `https://customer-relations.boomershub.com/#/inbound-email/edit`
2. Fill in:
   - **Name**: [Your Name] Inbox
   - **Email Address**: your-email@boomershub.com
   - **Server URL**: outlook.office365.com
   - **Port**: 993
   - **Protocol**: IMAP
   - **SSL**: Yes
   - **Auth Type**: OAuth
   - **External OAuth Connection**: Select the connection created in Step 1
   - **Mailbox**: INBOX
   - **Status**: Active
3. Save

**Step 3: Enable Scheduler**
1. Go to Admin → Schedulers
2. Find "Check Inbound Mailboxes"
3. Set to Active with desired frequency (e.g., every 5 minutes)

**How Email Linking Works**
When emails arrive, the EmailLinkingService hook automatically:
1. Looks up sender/recipient in Leads and Contacts by email address
2. Links the email to the matching record
3. Creates a LeadJourney entry for the timeline

### Known Issues
- None currently - all issues resolved

### Twilio Console Configuration Needed

**For TwiML App (TWIML_APP_SID from .env):**
1. Go to Twilio Console → Develop → Voice → TwiML Apps
2. Select the app (TWIML_APP_SID from .env)
3. Set **Status Callback URL**: `https://customer-relations.boomershub.com/legacy/twilio_webhook.php?action=status`

**For Phone Number (+18456713651):**
1. Go to Twilio Console → Phone Numbers → Manage → Active Numbers
2. Click on +18456713651
3. Under Voice & Fax:
   - **A CALL COMES IN**: Webhook → `https://customer-relations.boomershub.com/legacy/twilio_webhook.php?action=inbound`
   - **CALL STATUS CHANGES**: `https://customer-relations.boomershub.com/legacy/twilio_webhook.php?action=status`
4. Under Messaging:
   - **A MESSAGE COMES IN**: Webhook → `https://customer-relations.boomershub.com/legacy/twilio_webhook.php?action=sms`

### Debug Commands
```bash
# Check recent lead_journey entries
docker exec suitecrm mariadb -h $DB_HOST -P $DB_PORT -u $DB_USER -p'$DB_PASSWORD' \
  --ssl-ca=/opt/bitnami/mysql/certs/ca-certificate.crt $DB_NAME \
  -e "SELECT touchpoint_type, name, date_entered FROM lead_journey ORDER BY date_entered DESC LIMIT 10;"
# Database credentials are stored in .env file

# Check recent calls
docker exec suitecrm mariadb -h $DB_HOST -P $DB_PORT -u $DB_USER -p'$DB_PASSWORD' --ssl-ca=... $DB_NAME \
  -e "SELECT name, direction, status, recording_url, date_start FROM calls ORDER BY date_start DESC LIMIT 10;"

# Check SuiteCRM logs for Twilio
docker exec suitecrm grep -i twilio /bitnami/suitecrm/logs/legacy/suitecrm.log | tail -30

# Test webhook endpoint
curl -s -X POST 'https://customer-relations.boomershub.com/legacy/twilio_webhook.php?action=status' \
  -d 'CallSid=test&CallStatus=completed&From=+1234567890&To=+18456713651'
```

