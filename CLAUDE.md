# SuiteCRM PowerPack - AI Assistant Context

## Project Overview

**Repository**: `mahir009/suitecrm-powerpack`
**Docker Hub**: `mahir009/suitecrm-powerpack`
**Current Version**: v2.5.10
**Base Image**: Bitnami SuiteCRM (SuiteCRM 8 with Angular frontend + Legacy PHP)

This is a Docker-based SuiteCRM extension with five custom modules for sales operations:

1. **TwilioIntegration** - Click-to-call, SMS, auto-logging
2. **LeadJourney** - Customer journey timeline tracking
3. **FunnelDashboard** - Sales funnel visualization with role-based dashboards
4. **SalesTargets** - BDM/Team target tracking with commissions
5. **Packages** - Service packages with pricing

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
└── config/
    └── custom-extensions/
        └── dist/
            └── twilio-click-to-call.js
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
twilio_integration      -- Twilio configuration
twilio_audit_log        -- Call/SMS audit trail
lead_journey           -- Touchpoint tracking
funnel_dashboard       -- Dashboard configurations
sales_targets          -- BDM/Team targets
packages               -- Service packages

-- Custom fields added to standard tables
leads.funnel_type_c           -- VARCHAR(100) - Funnel category
leads.pipeline_stage_c        -- VARCHAR(100) - Current stage
leads.demo_scheduled_c        -- TINYINT(1) - Demo flag
leads.expected_revenue_c      -- DECIMAL(26,6)
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
SUITECRM_DATABASE_PORT=3306
SUITECRM_DATABASE_NAME=suitecrm
SUITECRM_DATABASE_USER=
SUITECRM_DATABASE_PASSWORD=

# SSL for managed databases (optional)
MYSQL_SSL_CA=/path/to/ca-certificate.crt
MYSQL_CLIENT_ENABLE_SSL=yes

# Twilio (optional)
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_PHONE_NUMBER=
TWILIO_FALLBACK_PHONE=

# SuiteCRM
SUITECRM_USERNAME=admin
SUITECRM_PASSWORD=
SUITECRM_EMAIL=admin@example.com
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
