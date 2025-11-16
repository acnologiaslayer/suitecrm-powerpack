# SuiteCRM PowerPack

[![Docker Hub](https://img.shields.io/docker/v/mahir009/suitecrm-powerpack?label=Docker%20Hub)](https://hub.docker.com/r/mahir009/suitecrm-powerpack)
[![Docker Image Size](https://img.shields.io/docker/image-size/mahir009/suitecrm-powerpack)](https://hub.docker.com/r/mahir009/suitecrm-powerpack)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A production-ready Docker image for SuiteCRM with three powerful extensions:

1. **🔔 Twilio Integration** - Click-to-call, auto logging, and call recordings
2. **📊 Lead Journey Timeline** - Unified view of all touchpoints (calls, emails, site visits, LinkedIn clicks)
3. **📈 Funnel Dashboards** - Segmented by category with comprehensive stage-tracking

All features are **configurable from the UI** and **ready to use out of the box**.

---

## 🚀 Features

### 1. Twilio Integration
- **Click-to-Call**: Click any phone number in SuiteCRM to initiate calls
- **Auto-Logging**: Automatically log all calls to the CRM
- **Call Recordings**: Record calls and store them in SuiteCRM
- **UI Configuration**: Configure Twilio settings from the admin panel
- **Real-time Status**: Track call status and duration

### 2. Lead Journey Timeline
- **Unified Timeline**: View all interactions in a single chronological view
- **Multiple Touchpoints**: Calls, emails, meetings, site visits, LinkedIn clicks, campaigns
- **Filterable View**: Filter by touchpoint type
- **Engagement Metrics**: Total touchpoints and breakdown by type
- **Site Visit Tracking**: JavaScript tracking for website visits
- **LinkedIn Integration**: Track LinkedIn engagement

### 3. Funnel Dashboards
- **Visual Funnel**: Beautiful visualization of your sales funnel
- **Category Segmentation**: Filter by lead source/category
- **Stage Tracking**: Track leads through each sales stage
- **Conversion Rates**: Calculate conversion rates between stages
- **Funnel Velocity**: Average time spent in each stage
- **Top Categories**: See which lead sources perform best
- **Date Filtering**: Analyze performance over custom date ranges

---

## 📋 Prerequisites

- Docker (version 20.10 or higher)
- Docker Compose (version 1.29 or higher)
- MySQL 8.0+ Database (external or containerized)
- Twilio Account (for calling features)

---

## 🛠️ Installation

### Step 1: Clone or Download

```bash
cd /home/mahir/Projects/suitecrm
```

### Step 2: Setup MySQL 8 Database

**Option A: Use External MySQL 8** (Recommended)

Create database and user:
```sql
CREATE DATABASE suitecrm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'suitecrm'@'%' IDENTIFIED WITH mysql_native_password BY 'your_password';
GRANT ALL PRIVILEGES ON suitecrm.* TO 'suitecrm'@'%';
FLUSH PRIVILEGES;
```

**Option B: Use Containerized MySQL 8**

Uncomment the `db` service in `docker-compose.yml`

### Step 3: Configure Environment

Copy and edit the environment file:

```bash
cp .env.example .env
nano .env
```

Configure your settings:

```bash
# External MySQL 8 Database
SUITECRM_DATABASE_HOST=host.docker.internal  # or your DB host
SUITECRM_DATABASE_PORT=3306
SUITECRM_DATABASE_NAME=suitecrm
SUITECRM_DATABASE_USER=suitecrm
SUITECRM_DATABASE_PASSWORD=your_secure_password

# Twilio Configuration
TWILIO_ACCOUNT_SID=your_account_sid_here
TWILIO_AUTH_TOKEN=your_auth_token_here
TWILIO_PHONE_NUMBER=+1234567890
```

### Step 4: Test Database Connection (Optional)

```bash
./test-db-connection.sh
```

### Step 5: Build and Start

Make scripts executable:

```bash
chmod +x build.sh start.sh stop.sh
```

Build the Docker image:

```bash
./build.sh
```

Start the containers:

```bash
./start.sh
```

Or use Docker Compose directly:

```bash
docker-compose up -d
```

---

## 🌐 Access

After starting the containers:

- **SuiteCRM**: http://localhost:8080

### Database Connection

The application connects to your external MySQL 8 database using the credentials in `.env`.

For detailed database setup instructions, see [EXTERNAL_DATABASE.md](EXTERNAL_DATABASE.md)

---

## 📖 Usage Guide

### Twilio Integration Setup

1. Navigate to **Admin** > **Twilio Integration** > **Configuration**
2. Enter your Twilio credentials:
   - Account SID
   - Auth Token
   - Phone Number
3. Enable desired features:
   - ✅ Click-to-Call
   - ✅ Auto Logging
   - ✅ Call Recordings
4. Click **Save Configuration**

### Using Click-to-Call

1. Open any Lead or Contact record
2. Click on any phone number field
3. The system will initiate a call via Twilio
4. Call will be automatically logged when completed

### Viewing Lead Journey Timeline

1. Open a Lead or Contact record
2. Navigate to **Lead Journey** > **Timeline**
3. View all touchpoints in chronological order
4. Filter by type (Calls, Emails, Meetings, etc.)
5. See engagement metrics at the top

### Using Funnel Dashboards

1. Navigate to **Funnel Dashboard** module
2. Select a category (lead source) from the dropdown
3. Choose date range for analysis
4. Click **Apply Filters**
5. View:
   - Visual funnel with all stages
   - Conversion rates between stages
   - Funnel velocity metrics
   - Top performing categories

---

## 🏗️ Architecture

### Docker Services

- **suitecrm**: PHP 8.1 with Apache, SuiteCRM, and custom modules
- **External MySQL 8**: Your database server (external or containerized)

### Custom Modules

```
custom-modules/
├── TwilioIntegration/          # Twilio calling features
│   ├── TwilioIntegration.php   # Main module logic
│   ├── TwilioClient.php        # Twilio API wrapper
│   ├── views/
│   │   └── view.config.php     # Configuration UI
│   └── click-to-call.js        # Frontend click-to-call
│
├── LeadJourney/                # Journey timeline
│   ├── LeadJourney.php         # Timeline aggregation
│   ├── views/
│   │   └── view.timeline.php   # Timeline visualization
│   └── tracking.js             # Site visit tracking
│
└── FunnelDashboard/            # Funnel analytics
    ├── FunnelDashboard.php     # Funnel calculations
    └── views/
        └── view.dashboard.php  # Dashboard UI
```

---

## 🔧 Configuration

### Environment Variables

All configuration can be done via environment variables in `.env`:

```bash
# External MySQL 8 Database
SUITECRM_DATABASE_HOST=host.docker.internal  # For local MySQL
SUITECRM_DATABASE_PORT=3306
SUITECRM_DATABASE_NAME=suitecrm
SUITECRM_DATABASE_USER=suitecrm
SUITECRM_DATABASE_PASSWORD=your_secure_password

# Site URL
SUITECRM_SITE_URL=http://localhost:8080

# Twilio
TWILIO_ACCOUNT_SID=your_sid
TWILIO_AUTH_TOKEN=your_token
TWILIO_PHONE_NUMBER=+1234567890
```

For cloud databases (AWS RDS, Google Cloud SQL, etc.), use the provided endpoint as the host.

See [EXTERNAL_DATABASE.md](EXTERNAL_DATABASE.md) for detailed configuration options.

### Persistent Data

Data is persisted in:

- `suitecrm-data` volume: SuiteCRM files and uploads
- External MySQL database: All CRM data

---

## 🔍 Monitoring

### View Logs

```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f suitecrm
docker-compose logs -f db
```

### Container Status

```bash
docker-compose ps
```

---

## 🛑 Managing Containers

### Stop Containers

```bash
./stop.sh
# or
docker-compose down
```

### Restart Containers

```bash
docker-compose restart
```

### Rebuild After Changes

```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

---

## 📊 Database Management

### Access Database via CLI

```bash
# From host machine
mysql -h localhost -P 3306 -u suitecrm -p suitecrm

# Or from container
docker-compose exec suitecrm mysql -h $SUITECRM_DATABASE_HOST -P $SUITECRM_DATABASE_PORT -u $SUITECRM_DATABASE_USER -p$SUITECRM_DATABASE_PASSWORD $SUITECRM_DATABASE_NAME
```

### Backup Database

```bash
# Load environment variables
source .env

# Create backup
mysqldump -h $SUITECRM_DATABASE_HOST -P $SUITECRM_DATABASE_PORT -u $SUITECRM_DATABASE_USER -p$SUITECRM_DATABASE_PASSWORD $SUITECRM_DATABASE_NAME > backup.sql
```

### Restore Database

```bash
# Load environment variables
source .env

# Restore backup
mysql -h $SUITECRM_DATABASE_HOST -P $SUITECRM_DATABASE_PORT -u $SUITECRM_DATABASE_USER -p$SUITECRM_DATABASE_PASSWORD $SUITECRM_DATABASE_NAME < backup.sql
```

### Test Database Connection

```bash
./test-db-connection.sh
```

---

## 🔐 Security Considerations

### Production Deployment

For production use, make sure to:

1. **Change default passwords** in `.env` file
2. **Use HTTPS** - Configure SSL certificates
3. **Secure Twilio credentials** - Use secrets management
4. **Restrict database access** - Don't expose port 3306
5. **Update regularly** - Keep SuiteCRM and modules updated
6. **Configure firewall** - Restrict access to necessary ports only

### Recommended .env for Production

```bash
MYSQL_ROOT_PASSWORD=strong_random_password
MYSQL_PASSWORD=strong_random_password
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=secure_token_here
```

---

## 🐛 Troubleshooting

### Module Not Showing

```bash
# Rebuild and clear cache
docker-compose exec suitecrm php -r "
require_once('include/entryPoint.php');
require_once('modules/Administration/QuickRepairAndRebuild.php');
\$repair = new RepairAndClear();
\$repair->repairAndClearAll(['clearAll'], ['All'], false, false);
"
```

### Permission Issues

```bash
docker-compose exec suitecrm chown -R www-data:www-data /var/www/html
docker-compose exec suitecrm chmod -R 755 /var/www/html
```

### Database Connection Failed

1. Test connection: `./test-db-connection.sh`
2. Check database is running on your host/server
3. Verify credentials in `.env`
4. Check firewall allows port 3306
5. For local MySQL, ensure using `host.docker.internal`
6. Verify user has permissions: `GRANT ALL PRIVILEGES ON suitecrm.* TO 'suitecrm'@'%';`

See [EXTERNAL_DATABASE.md](EXTERNAL_DATABASE.md) for detailed troubleshooting.

### Twilio Calls Not Working

1. Verify Twilio credentials in Admin > Twilio Integration
2. Check Twilio account has sufficient balance
3. Verify phone number is verified in Twilio
4. Check browser console for JavaScript errors

---

## 📝 API Endpoints

### Twilio Webhooks

- **TwiML Handler**: `/index.php?module=TwilioIntegration&action=twiml`
- **Status Webhook**: `/index.php?module=TwilioIntegration&action=webhook`
- **Make Call**: `/index.php?module=TwilioIntegration&action=makeCall`

### Lead Journey Tracking

- **Track Visit**: `/index.php?module=LeadJourney&action=trackVisit`
- **View Timeline**: `/index.php?module=LeadJourney&action=timeline&parent_type=Leads&parent_id={id}`

### Funnel Dashboard

- **View Dashboard**: `/index.php?module=FunnelDashboard&action=dashboard`

---

## 🚀 Advanced Features

### Site Visit Tracking

Include the tracking script on your website:

```html
<script src="http://your-suitecrm-url/custom/modules/LeadJourney/tracking.js"></script>
```

Set a cookie with the lead/contact ID:

```javascript
document.cookie = "lead_id=your_lead_id; path=/";
```

### LinkedIn Click Tracking

Log LinkedIn clicks programmatically:

```php
LeadJourney::logTouchpoint('Leads', $leadId, 'linkedin_click', [
    'action' => 'profile_view',
    'url' => 'https://linkedin.com/in/username',
    'description' => 'Viewed profile'
]);
```

### Custom Touchpoint Types

Add custom touchpoints:

```php
LeadJourney::logTouchpoint('Contacts', $contactId, 'custom_event', [
    'event_type' => 'webinar_attended',
    'source' => 'Zoom',
    'duration' => 60
]);
```

---

## 📦 Volumes and Data

### Backing Up Everything

```bash
# Create backup directory
mkdir -p backups

# Backup database
docker-compose exec db mysqldump -usuitecrm -psuitecrm suitecrm > backups/db_backup.sql

# Backup files
docker-compose exec suitecrm tar -czf /tmp/files.tar.gz /var/www/html
docker cp suitecrm-extended:/tmp/files.tar.gz backups/files.tar.gz
```

---

## 🤝 Contributing

Feel free to submit issues, fork the repository, and create pull requests for any improvements.

---

## 📄 License

This project extends SuiteCRM which is licensed under AGPLv3. Custom modules are provided as-is for use with SuiteCRM.

---

## 🔗 Resources

- [SuiteCRM Documentation](https://docs.suitecrm.com/)
- [Twilio PHP SDK](https://www.twilio.com/docs/libraries/php)
- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Documentation](https://docs.docker.com/compose/)

---

## 💡 Tips

1. **Performance**: For large datasets, consider adding database indexes
2. **Scaling**: Use Redis for caching in production
3. **Backups**: Set up automated daily backups
4. **Monitoring**: Integrate with monitoring tools like Prometheus
5. **Testing**: Always test Twilio integration in sandbox mode first

---

## 📞 Support

For issues or questions:
1. Check the troubleshooting section
2. Review Docker logs
3. Check SuiteCRM logs in `logs/` directory
4. Verify Twilio configuration and credentials

---

**Ready to revolutionize your CRM experience!** 🎉
