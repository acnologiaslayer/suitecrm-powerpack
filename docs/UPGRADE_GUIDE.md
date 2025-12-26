# SuiteCRM PowerPack Upgrade Guide

## Upgrading from v2.5.17 to v2.5.28

This guide covers a safe upgrade path that preserves all your data.

---

## What's New Since v2.5.17

| Version | Changes |
|---------|---------|
| v2.5.18 | Twilio browser-based calling enhancements |
| v2.5.19 | Hybrid notification system (REST API + WebSocket) |
| v2.5.20-27 | Verbacall Integration module, email composer, bug fixes |
| v2.5.28 | Notification webhook documentation |

**New Features:**
- Verbacall Integration (Sign-up Link & Payment Link generation for Leads)
- Enhanced notification webhook system
- WebSocket real-time notifications
- Twilio browser-based calling improvements

---

## Pre-Upgrade Checklist

- [ ] Database backup completed
- [ ] Current docker-compose.yml backed up
- [ ] Note current environment variables
- [ ] Test backup restore procedure (recommended)

---

## Step 1: Backup Your Database

### Option A: Using mysqldump (Recommended)

```bash
# From your server
mysqldump -h <DB_HOST> -u <DB_USER> -p<DB_PASSWORD> <DB_NAME> > suitecrm_backup_$(date +%Y%m%d_%H%M%S).sql

# Or if using Docker MySQL container
docker exec <mysql_container> mysqldump -u root -p<password> suitecrm > suitecrm_backup_$(date +%Y%m%d_%H%M%S).sql
```

### Option B: Using Docker volume backup

```bash
# If using named volume for database
docker run --rm -v suitecrm_db_data:/data -v $(pwd):/backup alpine tar cvzf /backup/db_backup.tar.gz /data
```

---

## Step 2: Backup Uploaded Files (if using persistent storage)

```bash
# If you have a volume for uploads
docker cp <container_name>:/bitnami/suitecrm/public/legacy/upload ./upload_backup

# Or if using named volume
docker run --rm -v suitecrm_data:/data -v $(pwd):/backup alpine tar cvzf /backup/files_backup.tar.gz /data
```

---

## Step 3: Update docker-compose.yml

### Current (v2.5.17):
```yaml
services:
  suitecrm:
    image: mahir009/suitecrm-powerpack:v2.5.17
    # ... rest of config
```

### Updated (v2.5.28):
```yaml
services:
  suitecrm:
    image: mahir009/suitecrm-powerpack:v2.5.28
    # ... rest of config
    environment:
      # Existing variables...

      # NEW: Optional Verbacall Integration (add if needed)
      - VERBACALL_API_URL=https://app.verbacall.com

      # NEW: Optional WebSocket notifications (add if needed)
      - NOTIFICATION_JWT_SECRET=your-secure-random-string
      - NOTIFICATION_WS_URL=ws://localhost:3001
```

---

## Step 4: Pull New Image and Restart

```bash
# Pull the new image
docker pull mahir009/suitecrm-powerpack:v2.5.28

# Stop current container
docker-compose down

# Start with new image
docker-compose up -d

# Watch logs for any issues
docker-compose logs -f suitecrm
```

---

## Step 5: Verify Database Schema Updates

The entrypoint script automatically runs `install-modules.sh` which handles schema migrations. Check logs for:

```
[PowerPack] Running module installation...
[PowerPack] Adding Verbacall fields to leads table...
[PowerPack] Creating notification tables...
```

### Manual Verification (if needed)

```bash
# Connect to your database and verify new tables/columns exist
docker exec -it <container> mysql -h$SUITECRM_DATABASE_HOST -u$SUITECRM_DATABASE_USER -p$SUITECRM_DATABASE_PASSWORD $SUITECRM_DATABASE_NAME

# Check for new Verbacall fields on leads
DESCRIBE leads;
# Should see: verbacall_signup_c, verbacall_link_sent_c

# Check for notification tables
SHOW TABLES LIKE 'notification%';
# Should see: notification_api_keys, notification_queue, notification_rate_limit
```

---

## Step 6: Clear Caches

```bash
docker exec -it <container> bash -c "rm -rf /bitnami/suitecrm/cache/* /bitnami/suitecrm/public/legacy/cache/*"
```

---

## Step 7: Verify Upgrade

1. **Login to SuiteCRM** - Verify you can login with existing credentials
2. **Check Leads** - Open a Lead record, verify Verbacall panel appears
3. **Check Notifications** - Click bell icon, verify it works
4. **Check Modules** - Verify FunnelDashboard, SalesTargets, etc. still work

---

## Rollback Procedure (if needed)

If something goes wrong:

```bash
# Stop the new container
docker-compose down

# Restore database from backup
mysql -h <DB_HOST> -u <DB_USER> -p<DB_PASSWORD> <DB_NAME> < suitecrm_backup_YYYYMMDD_HHMMSS.sql

# Edit docker-compose.yml to use old version
# image: mahir009/suitecrm-powerpack:v2.5.17

# Start with old image
docker-compose up -d
```

---

## New Environment Variables (Optional)

These are new in v2.5.28 but optional:

```bash
# Verbacall Integration
VERBACALL_API_URL=https://app.verbacall.com    # Verbacall API endpoint

# WebSocket Notifications
NOTIFICATION_JWT_SECRET=<random-string>         # JWT signing secret
NOTIFICATION_WS_URL=ws://localhost:3001         # WebSocket server URL
```

---

## Database Schema Changes (v2.5.17 → v2.5.28)

The following are automatically applied by `install-modules.sh`:

### New Columns on `leads` table:
```sql
ALTER TABLE leads ADD COLUMN IF NOT EXISTS verbacall_signup_c TINYINT(1) DEFAULT 0;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS verbacall_link_sent_c DATETIME DEFAULT NULL;
```

### New Tables:
```sql
-- API keys for webhook authentication
CREATE TABLE IF NOT EXISTS notification_api_keys (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    last_used_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted TINYINT(1) DEFAULT 0
);

-- WebSocket notification queue
CREATE TABLE IF NOT EXISTS notification_queue (
    id VARCHAR(36) PRIMARY KEY,
    alert_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    payload TEXT,
    status ENUM('pending','sent','acknowledged') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL
);

-- Rate limiting
CREATE TABLE IF NOT EXISTS notification_rate_limit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## Troubleshooting

### Issue: Container won't start
```bash
# Check logs
docker-compose logs suitecrm

# Common fix: database connection
# Verify SUITECRM_DATABASE_* environment variables
```

### Issue: Verbacall buttons not showing on Leads
```bash
# Clear browser cache and reload
# Or check browser console for JavaScript errors
```

### Issue: 500 errors after upgrade
```bash
# Clear all caches
docker exec -it <container> bash -c "rm -rf /bitnami/suitecrm/cache/* /bitnami/suitecrm/public/legacy/cache/*"

# Check file permissions
docker exec -it <container> bash -c "chown -R daemon:daemon /bitnami/suitecrm"
```

### Issue: Module not appearing in navigation
```bash
# Run repair
docker exec -it <container> php /bitnami/suitecrm/public/legacy/bin/console suitecrm:app:repair
```

---

## Support

If you encounter issues:
1. Check container logs: `docker-compose logs -f suitecrm`
2. Check SuiteCRM logs: `docker exec <container> tail -f /bitnami/suitecrm/public/legacy/suitecrm.log`
3. Report issues at: https://github.com/anthropics/claude-code/issues
