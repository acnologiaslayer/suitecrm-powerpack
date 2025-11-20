# SuiteCRM PowerPack

Production-ready SuiteCRM with three powerful custom modules for enhanced CRM capabilities.

## 🚀 Quick Start

### Option A: Fully Automated (Recommended)

```bash
# Create volume for persistent data
docker volume create suitecrm-data

# Run with all environment variables - SuiteCRM installs automatically!
docker run -d \
  --name suitecrm \
  -p 80:8080 \
  -v suitecrm-data:/bitnami/suitecrm \
  -e SUITECRM_DATABASE_HOST=your-db-host.com \
  -e SUITECRM_DATABASE_PORT_NUMBER=3306 \
  -e SUITECRM_DATABASE_USER=suitecrm \
  -e SUITECRM_DATABASE_PASSWORD=your-password \
  -e SUITECRM_DATABASE_NAME=suitecrm \
  -e SUITECRM_SITE_URL=http://your-domain.com \
  -e SUITECRM_USERNAME=admin \
  -e SUITECRM_PASSWORD=admin123 \
  -e SUITECRM_HOST=your-domain.com \
  mahir009/suitecrm-powerpack:latest
```

**That's it!** The container will:
1. ✅ Wait for database to be ready
2. ✅ Install SuiteCRM automatically (silent installation)
3. ✅ Install all 3 custom modules
4. ✅ Configure Twilio settings from environment variables
5. ✅ Be ready to use in ~60 seconds!

Access at: `http://your-domain.com` (login with credentials from env vars)

### Option B: Manual Web Installation

```bash
# Create volume for persistent data
docker volume create suitecrm-data

# Run with minimal environment variables
docker run -d \
  --name suitecrm \
  -p 80:8080 \
  -v suitecrm-data:/bitnami/suitecrm \
  -e SUITECRM_SKIP_INSTALL=yes \
  mahir009/suitecrm-powerpack:latest
```

Then:
1. Access `http://your-domain:8080/install.php`
2. Complete installation wizard manually
3. Restart container: `docker restart suitecrm`
4. Modules install automatically on restart

## 📦 What's Included

### 1. 🔔 Twilio Integration
- **Click-to-Call** from any phone number field
- **Click-to-Text (SMS)** with interactive dialog
- **Auto-Logging** of all calls and messages
- **Call Recordings** stored in SuiteCRM
- **UI Configuration** - Set up from admin panel

### 2. 📊 Lead Journey Timeline
- **Unified View** of all customer interactions
- **Multiple Touchpoints**: Calls, emails, meetings, site visits, LinkedIn clicks
- **Filterable Timeline** by touchpoint type
- **Engagement Metrics** and statistics
- **Site Visit Tracking** via JavaScript

### 3. 📈 Funnel Dashboards
- **Visual Sales Funnel** by category
- **Stage Tracking** with conversion rates
- **Funnel Velocity** - time in each stage
- **Top Categories** performance analysis
- **Date Range Filtering**

## 🏗️ Architecture

Built on **Bitnami SuiteCRM** base image for:
- ✅ Production-tested stability
- ✅ Non-root security (daemon user, UID 1001)
- ✅ Optimized Apache + PHP 8.4 configuration
- ✅ Automatic file permissions
- ✅ Volume persistence out of the box

## 📋 Prerequisites

- **Docker** 20.10+
- **MySQL 8.0+** database (external/managed recommended)
- **Twilio Account** (optional, for calling features)

## 🔧 Configuration

### Required Environment Variables (For Silent Install)

```bash
# Database Configuration (Required)
SUITECRM_DATABASE_HOST=your-db-host
SUITECRM_DATABASE_PORT_NUMBER=3306
SUITECRM_DATABASE_USER=suitecrm
SUITECRM_DATABASE_PASSWORD=your-password
SUITECRM_DATABASE_NAME=suitecrm

# Application Settings (Required for silent install)
SUITECRM_SITE_URL=http://your-domain.com
SUITECRM_USERNAME=admin
SUITECRM_PASSWORD=admin123
SUITECRM_HOST=your-domain.com
```

### Optional Environment Variables

```bash
# Skip automated installation (use manual web installer)
SUITECRM_SKIP_INSTALL=yes

# Twilio Integration
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=your-token
TWILIO_PHONE_NUMBER=+1234567890

# SSL for Managed Databases
MYSQL_CLIENT_ENABLE_SSL=yes
MYSQL_CLIENT_SSL_CA_FILE=/opt/bitnami/mysql/certs/ca-certificate.crt
```

### DigitalOcean Managed MySQL

```bash
docker run -d \
  --name suitecrm \
  -p 80:8080 \
  -v suitecrm-data:/bitnami/suitecrm \
  -e SUITECRM_DATABASE_HOST=db-mysql-xxx.db.ondigitalocean.com \
  -e SUITECRM_DATABASE_PORT_NUMBER=25060 \
  -e SUITECRM_DATABASE_USER=doadmin \
  -e SUITECRM_DATABASE_PASSWORD=xxxxx \
  -e SUITECRM_DATABASE_NAME=defaultdb \
  -e MYSQL_CLIENT_ENABLE_SSL=yes \
  -e SUITECRM_HOST=crm.example.com \
  mahir009/suitecrm-powerpack:latest
```

## 🐳 Docker Compose

```yaml
version: '3.8'

services:
  suitecrm:
    image: mahir009/suitecrm-powerpack:latest
    ports:
      - "80:8080"
    volumes:
      - suitecrm-data:/bitnami/suitecrm
    environment:
      # Database
      SUITECRM_DATABASE_HOST: your-db-host.com
      SUITECRM_DATABASE_PORT_NUMBER: 3306
      SUITECRM_DATABASE_USER: suitecrm
      SUITECRM_DATABASE_PASSWORD: your-password
      SUITECRM_DATABASE_NAME: suitecrm
      
      # Application
      SUITECRM_HOST: crm.example.com
      SUITECRM_USERNAME: admin
      SUITECRM_PASSWORD: admin
      
      # Twilio (Optional)
      TWILIO_ACCOUNT_SID: ACxxxxxxxxxxxx
      TWILIO_AUTH_TOKEN: your-token
      TWILIO_PHONE_NUMBER: +1234567890
    restart: unless-stopped

volumes:
  suitecrm-data:
```

## 🌐 Ports

- **8080** - HTTP (Apache)
- **8443** - HTTPS (disabled by default, use reverse proxy)

## 📂 Volume Paths

- **`/bitnami/suitecrm`** - Persistent SuiteCRM files, uploads, and configurations

## 🔒 Security Features

- **Non-root execution** - Runs as daemon user (UID 1001)
- **HTTPS disabled** - Use Nginx/Traefik reverse proxy for SSL
- **No default passwords** - Set via environment variables
- **Managed database support** - SSL-ready for cloud databases
- **Apache security hardening** - Bitnami optimized configuration

## 🚀 Production Deployment

### With Nginx Reverse Proxy

```nginx
server {
    listen 443 ssl http2;
    server_name crm.example.com;

    ssl_certificate /etc/letsencrypt/live/crm.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crm.example.com/privkey.pem;

    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Recommended Settings

1. **Use external database** - DigitalOcean, AWS RDS, Google Cloud SQL
2. **Enable SSL** via reverse proxy (Nginx, Traefik, Caddy)
3. **Set strong passwords** in environment variables
4. **Regular backups** of database and `/bitnami/suitecrm` volume
5. **Monitor logs**: `docker logs -f suitecrm`

## 📖 Initial Setup

### Automated Setup (Zero Configuration!)

With all environment variables set, the container automatically:

1. **Waits for database** - Checks every 2 seconds (up to 60 seconds)
2. **Installs SuiteCRM silently** - No web installer needed!
3. **Installs custom modules** - All 3 modules configured automatically
4. **Applies Twilio config** - From environment variables
5. **Ready to use** - Login with your admin credentials

**Total time:** ~60 seconds from start to fully functional CRM!

### Manual Setup (Optional)

If you prefer manual installation or set `SUITECRM_SKIP_INSTALL=yes`:

1. **Start container** with database credentials
2. **Access web installer** at `http://your-domain:8080/install.php`
3. **Complete installation** wizard
4. **Restart container**: `docker restart suitecrm`
5. **Modules install automatically** on restart
6. **Log in** and start using!

### Configure Twilio (Optional)

1. Go to **Admin Panel** > **Twilio Integration**
2. Click **Configuration**
3. Enter your Twilio credentials:
   - Account SID
   - Auth Token  
   - Phone Number
4. **Enable Click-to-Call** checkbox ✅
5. **Save Configuration**

Now when you view a Contact or Lead:
- 📞 **Call** button appears next to phone numbers
- 💬 **SMS** button appears next to phone numbers

### Step 4: Verify All Features

**Twilio Integration (Call + SMS):**
1. Open any Contact or Lead record
2. Look for phone number fields
3. You should see:
   - **📞 Call** button - Click to initiate call via Twilio
   - **💬 SMS** button - Click to send text message
4. Click SMS button → Opens dialog to compose message
5. Click Call button → Initiates phone call

**Lead Journey Timeline:**
1. Open any Contact or Lead record
2. Click **"View Journey Timeline"** button at top
3. Opens new window showing:
   - All interactions (calls, emails, meetings)
   - Site visits and LinkedIn clicks  
   - Campaign touchpoints
   - Filterable timeline
4. Timeline updates automatically as new interactions occur

**Funnel Dashboard:**
1. Go to main menu → **Funnel Dashboard**
2. Select category (lead source)
3. Choose date range
4. View:
   - Visual funnel with all stages
   - Conversion rates between stages
   - Average time in each stage
   - Top performing categories

## 🔍 Monitoring

### View Logs
```bash
docker logs -f suitecrm
```

### Check Health
```bash
docker ps
curl http://localhost:8080/
```

### Database Connection Test
```bash
docker exec suitecrm mysql -h $SUITECRM_DATABASE_HOST \
  -P $SUITECRM_DATABASE_PORT_NUMBER \
  -u $SUITECRM_DATABASE_USER \
  -p$SUITECRM_DATABASE_PASSWORD \
  $SUITECRM_DATABASE_NAME -e "SELECT 1;"
```

## 🐛 Troubleshooting

### Container Won't Start
```bash
# Check logs
docker logs suitecrm

# Verify environment variables
docker inspect suitecrm | grep -A 20 "Env"
```

### Database Connection Failed
- Verify credentials match your database
- Check database allows connections from Docker host
- For SSL databases, ensure `MYSQL_CLIENT_ENABLE_SSL=yes`
- Test connection: `telnet your-db-host 3306`

### File Permission Issues
The container automatically sets permissions on first run. If issues persist:
```bash
docker exec -u root suitecrm chown -R daemon:daemon /bitnami/suitecrm
```

### Module Not Appearing
Modules are pre-installed. If not visible:
1. Log in as admin
2. Go to Admin > Repair > Quick Repair and Rebuild
3. Execute repairs

## 📦 Backup & Restore

### Backup Volume
```bash
docker run --rm \
  -v suitecrm-data:/data \
  -v $(pwd):/backup \
  ubuntu tar czf /backup/suitecrm-backup.tar.gz /data
```

### Restore Volume
```bash
docker run --rm \
  -v suitecrm-data:/data \
  -v $(pwd):/backup \
  ubuntu tar xzf /backup/suitecrm-backup.tar.gz -C /
```

### Backup Database
```bash
docker exec suitecrm mysqldump \
  -h $SUITECRM_DATABASE_HOST \
  -P $SUITECRM_DATABASE_PORT_NUMBER \
  -u $SUITECRM_DATABASE_USER \
  -p$SUITECRM_DATABASE_PASSWORD \
  $SUITECRM_DATABASE_NAME > backup.sql
```

## 🏷️ Tags

- `latest`, `2`, `2.0`, `v2.0.0` - Current stable release (Bitnami-based)
- `1`, `1.1`, `v1.1.0` - Previous release (custom PHP 8.1 build)

## 📝 Version 2.0.0 Changes

- ✅ Migrated to **Bitnami SuiteCRM base** for production stability
- ✅ **Volume persistence** with automatic file copying on first run
- ✅ **DigitalOcean SSL** support with pre-installed CA certificate
- ✅ **Apache path fixes** - correct DocumentRoot (`/bitnami/suitecrm/public`)
- ✅ **Localhost ServerName** - ready for Nginx reverse proxy
- ✅ **Non-root security** - runs as daemon user (UID 1001)
- ✅ **Bitnami bug fixes** - patched readonly variable issue

## 🔗 Links

- **GitHub**: [acnologiaslayer/suitecrm-powerpack](https://github.com/acnologiaslayer/suitecrm-powerpack)
- **Issues**: [Report bugs](https://github.com/acnologiaslayer/suitecrm-powerpack/issues)
- **SuiteCRM Docs**: [docs.suitecrm.com](https://docs.suitecrm.com/)
- **Twilio Docs**: [twilio.com/docs](https://www.twilio.com/docs/)

## 📄 License

Based on SuiteCRM (AGPLv3). Custom modules provided as-is.

## 💬 Support

- Check logs: `docker logs suitecrm`
- Review [GitHub Issues](https://github.com/acnologiaslayer/suitecrm-powerpack/issues)
- Consult [SuiteCRM Documentation](https://docs.suitecrm.com/)

---

**Built with ❤️ on Bitnami SuiteCRM**
