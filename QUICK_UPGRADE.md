# Quick Production Upgrade - Step by Step

**For your current setup with stopped container**

---

## Your Current Situation

Container: `suitecrm-test` (currently stopped)
Image: Custom build (9c6253d44732)

---

## Simple Upgrade Steps

### Step 1: Start Your Current Container
```bash
docker start suitecrm-test
```

### Step 2: Backup Configuration & Data
```bash
# Create backup directory
mkdir -p backups/$(date +%Y%m%d_%H%M%S)
cd backups/$(date +%Y%m%d_%H%M%S)

# Backup config
docker exec suitecrm-test cat /bitnami/suitecrm/config.php > config.backup 2>/dev/null || \
  docker exec suitecrm-test cat /opt/bitnami/suitecrm/config.php > config.backup

# Backup database (if using external DB)
# Replace with your actual DB credentials
docker exec suitecrm-test mysqldump -u root -p \
  suitecrm_db calls notes tasks documents twilio_audit_log \
  > database.backup.sql

cd ../..
```

### Step 3: Copy New Files into Running Container
```bash
# Copy updated Twilio module files
docker cp custom-modules/TwilioIntegration/. suitecrm-test:/bitnami/suitecrm/modules/TwilioIntegration/

# Or if path is different:
docker cp custom-modules/TwilioIntegration/. suitecrm-test:/opt/bitnami/suitecrm/modules/TwilioIntegration/
```

### Step 4: Run Database Migration
```bash
# Copy migration file to container
docker cp custom-modules/TwilioIntegration/install/upgrade_to_v2.4.0.sql suitecrm-test:/tmp/

# Run migration (adjust credentials)
docker exec suitecrm-test mysql -u root -p \
  suitecrm_db < /tmp/upgrade_to_v2.4.0.sql
```

### Step 5: Verify Upgrade
```bash
# Check version
docker exec suitecrm-test grep version /bitnami/suitecrm/modules/TwilioIntegration/manifest.php

# Should show: 2.4.0

# Check config preserved
docker exec suitecrm-test grep twilio_account_sid /bitnami/suitecrm/config.php
```

### Step 6: Clear Cache
```bash
docker exec suitecrm-test rm -rf /bitnami/suitecrm/cache/*
# Or
docker exec suitecrm-test rm -rf /opt/bitnami/suitecrm/cache/*
```

---

## Alternative: Fresh Container with Data Volumes

If you want to use the new Docker image with your existing data:

### Step 1: Identify Your Volumes
```bash
# Check what volumes suitecrm-test is using
docker inspect suitecrm-test | grep -A 10 "Mounts"
```

### Step 2: Stop Old Container
```bash
docker stop suitecrm-test
docker rename suitecrm-test suitecrm-test-backup
```

### Step 3: Start New Container with Same Volumes
```bash
# Find your volume names from Step 1, then run:
docker run -d \
  --name suitecrm-test \
  -v VOLUME_NAME:/bitnami/suitecrm \
  -p 8080:8080 -p 8443:8443 \
  mahir009/suitecrm-powerpack:v2.4.0
```

### Step 4: Run Migration
```bash
docker exec suitecrm-test mysql -u root -p \
  suitecrm_db < /bitnami/suitecrm/modules/TwilioIntegration/install/upgrade_to_v2.4.0.sql
```

---

## Even Simpler: Manual File Copy (No Docker)

If you're testing locally without needing Docker:

### Step 1: Copy Files Directly
```bash
cd /home/mahir/Projects/suitecrm

# If you have SuiteCRM installed locally
sudo cp -r custom-modules/TwilioIntegration/* /var/www/html/suitecrm/modules/TwilioIntegration/

# Set permissions
sudo chown -R www-data:www-data /var/www/html/suitecrm/modules/TwilioIntegration/
sudo chmod -R 755 /var/www/html/suitecrm/modules/TwilioIntegration/
```

### Step 2: Run Migration
```bash
mysql -u root -p suitecrm_db < custom-modules/TwilioIntegration/install/upgrade_to_v2.4.0.sql
```

### Step 3: Clear Cache
```bash
sudo rm -rf /var/www/html/suitecrm/cache/*
```

---

## Troubleshooting

### Issue: "Can't find config.php path"

**Solution**: Find the correct path
```bash
docker exec suitecrm-test find / -name config.php 2>/dev/null | grep suitecrm
```

### Issue: "Permission denied copying files"

**Solution**: Run with appropriate permissions
```bash
docker exec -u root suitecrm-test bash -c "chown -R bitnami:bitnami /bitnami/suitecrm/modules/TwilioIntegration/"
```

### Issue: "Database migration fails"

**Solution**: Check database credentials
```bash
# Find DB credentials
docker exec suitecrm-test env | grep -i database

# Or check config
docker exec suitecrm-test grep -i db /bitnami/suitecrm/config.php
```

### Issue: Container won't start

**Solution**: Check logs
```bash
docker logs suitecrm-test
docker logs --tail 100 suitecrm-test
```

---

## Quick Command Reference

### Check if upgrade worked
```bash
# Version check
docker exec suitecrm-test grep "'version'" /bitnami/suitecrm/modules/TwilioIntegration/manifest.php

# Config preserved
docker exec suitecrm-test grep "twilio_" /bitnami/suitecrm/config.php | head -5

# New files exist
docker exec suitecrm-test ls -la /bitnami/suitecrm/modules/TwilioIntegration/ | grep -E "Security|Scheduler"
```

### Rollback if needed
```bash
# If you made backup
docker stop suitecrm-test
docker rm suitecrm-test
docker rename suitecrm-test-backup suitecrm-test
docker start suitecrm-test
```

---

## What Should Happen

After successful upgrade:
- ✅ Version shows 2.4.0
- ✅ Config shows your twilio_account_sid
- ✅ New files appear (TwilioSecurity.php, TwilioScheduler.php, etc.)
- ✅ Metrics API works: `curl http://localhost:8080/index.php?module=TwilioIntegration&action=metrics`
- ✅ No errors in logs: `docker logs suitecrm-test | grep -i error | tail -20`

---

## Need Help?

**What error are you seeing?**

Common errors:
1. "Container not found" → Use correct container name: `suitecrm-test`
2. "Path not found" → Find paths with: `docker exec suitecrm-test find / -name "*.php" | grep TwilioIntegration`
3. "Permission denied" → Run as root: `docker exec -u root suitecrm-test ...`
4. "Database error" → Check credentials in config.php

**Share the specific error and I'll help you fix it!**
