# Changelog

All notable changes to SuiteCRM PowerPack will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2025-11-17

### Added
- SMS (Click-to-text) functionality in Twilio Integration
  - Click-to-text button on all phone fields
  - Interactive SMS compose dialog
  - Character counter (1600 characters max)
  - Automatic SMS logging to Notes
  - SMS status callbacks
  - Message history retrieval
  - Ctrl+Enter shortcut to send

### Enhanced
- Updated JavaScript UI with separate Call and SMS buttons
- Improved notification system with info/success/error states
- Better visual feedback for SMS operations
- Enhanced TwilioClient with SMS API methods

## [1.0.0] - 2025-11-16

### Added
- Initial release of SuiteCRM PowerPack
- Twilio Integration module
  - Click-to-call functionality
  - Automatic call logging
  - Call recordings with storage
  - UI-based configuration panel
- Lead Journey Timeline module
  - Unified timeline view of all touchpoints
  - Support for calls, emails, meetings, site visits, LinkedIn clicks
  - Filterable timeline by touchpoint type
  - Engagement statistics dashboard
  - JavaScript tracking for site visits
- Funnel Dashboard module
  - Visual sales funnel with all stages
  - Category/lead source segmentation
  - Conversion rate calculations
  - Funnel velocity metrics
  - Top performing categories analysis
  - Date range filtering
- Docker support with MySQL 8 compatibility
- External database support (local, remote, cloud)
- Comprehensive documentation
- Database connection testing tool
- Build and deployment scripts

### Features
- Out-of-the-box ready modules
- UI-configurable settings
- Production-ready Docker image
- Support for AWS RDS, Google Cloud SQL, Azure Database
- Automated module installation
- Built on SuiteCRM 7.14.2
- PHP 8.1 support
- Apache web server

### Documentation
- README with quick start guide
- External database configuration guide
- Architecture overview
- Deployment checklist
- Quick start guide
- Troubleshooting documentation

[1.0.1]: https://github.com/mahir/suitecrm-powerpack/releases/tag/v1.0.1
[1.0.0]: https://github.com/mahir/suitecrm-powerpack/releases/tag/v1.0.0
