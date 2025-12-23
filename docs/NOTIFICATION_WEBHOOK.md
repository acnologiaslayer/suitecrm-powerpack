# Notification Webhook API Documentation

## Overview

The Notification Webhook provides a REST API endpoint for external systems to push real-time notifications to SuiteCRM users. Notifications appear in the SuiteCRM bell icon and can optionally be delivered via WebSocket for instant updates.

**Endpoint:** `POST /legacy/notification_webhook.php`

**Version:** 1.0.0

---

## Table of Contents

1. [Authentication](#authentication)
2. [Rate Limiting](#rate-limiting)
3. [Endpoints](#endpoints)
   - [Status Check](#status-check)
   - [Create Notification](#create-notification)
   - [Batch Notifications](#batch-notifications)
   - [Verbacall Signup](#verbacall-signup)
4. [Request Parameters](#request-parameters)
5. [Response Format](#response-format)
6. [Examples](#examples)
7. [Database Schema](#database-schema)
8. [Configuration](#configuration)
9. [Troubleshooting](#troubleshooting)

---

## Authentication

The webhook supports three authentication methods. Include one of the following with each request:

### 1. API Key (Recommended)

```http
X-API-Key: your-api-key-here
```

API keys are stored in the `notification_api_keys` table. Generate a new key:

```php
// In SuiteCRM PHP context
require_once('modules/Webhooks/NotificationSecurity.php');
$apiKey = NotificationSecurity::generateApiKey();
// Returns: 64-character hex string
```

### 2. HMAC Signature

For enhanced security with payload verification:

```http
X-Signature: {hmac-sha256-signature}
X-Timestamp: {unix-timestamp}
```

Signature calculation:
```
signature = HMAC-SHA256(timestamp + request_body, secret)
```

The secret is configured in `config_override.php`:
```php
$sugar_config['notification_webhook_secret'] = 'your-secret-here';
```

**Note:** Timestamps must be within 5 minutes of server time to prevent replay attacks.

### 3. Bearer Token (JWT)

For internal systems or future OAuth2 integration:

```http
Authorization: Bearer {jwt-token}
```

Generate a JWT token:
```php
require_once('modules/Webhooks/NotificationSecurity.php');
$token = NotificationSecurity::createJwtToken($userId, 3600); // 1 hour expiry
```

Configure JWT secret in `config_override.php`:
```php
$sugar_config['notification_jwt_secret'] = 'your-jwt-secret-here';
```

---

## Rate Limiting

- **Limit:** 100 requests per 60-second window per IP address
- **Response when exceeded:** HTTP 429

```json
{
  "error": "Rate limit exceeded",
  "message": "Too many requests. Please try again later.",
  "retry_after": 60
}
```

---

## Endpoints

### Status Check

Check if the webhook is operational.

**Request:**
```http
GET /legacy/notification_webhook.php?action=status
```

**Response:**
```json
{
  "status": "ok",
  "version": "1.0.0",
  "timestamp": "2025-01-15T12:00:00+00:00",
  "endpoints": {
    "create": "POST /legacy/notification_webhook.php",
    "batch": "POST /legacy/notification_webhook.php?action=batch",
    "verbacall_signup": "POST /legacy/notification_webhook.php?action=verbacall_signup",
    "status": "GET /legacy/notification_webhook.php?action=status"
  }
}
```

**Note:** The status endpoint does NOT require authentication.

---

### Create Notification

Create a single notification for specified users or roles.

**Request:**
```http
POST /legacy/notification_webhook.php
Content-Type: application/json
X-API-Key: your-api-key
```

**Body:**
```json
{
  "title": "New Lead Assigned",
  "message": "John Doe has been assigned to you",
  "type": "info",
  "priority": "normal",
  "target_users": ["user-uuid-1", "user-uuid-2"],
  "target_roles": ["Sales Rep"],
  "url_redirect": "#/leads/detail/lead-uuid",
  "target_module": "Leads",
  "target_record": "lead-uuid",
  "metadata": {
    "lead_name": "John Doe",
    "source": "web_form"
  }
}
```

**Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "alert_ids": ["alert-uuid-1", "alert-uuid-2"],
    "queue_ids": ["queue-uuid-1", "queue-uuid-2"],
    "user_count": 2
  }
}
```

---

### Batch Notifications

Create multiple notifications in a single request (max 50).

**Request:**
```http
POST /legacy/notification_webhook.php?action=batch
Content-Type: application/json
X-API-Key: your-api-key
```

**Body:**
```json
{
  "notifications": [
    {
      "title": "Lead Updated",
      "message": "Status changed to Qualified",
      "target_users": ["user-uuid-1"]
    },
    {
      "title": "New Task",
      "message": "Follow up with client",
      "target_users": ["user-uuid-2"],
      "priority": "high"
    }
  ]
}
```

**Response (201/207):**
```json
{
  "success": true,
  "summary": {
    "total": 2,
    "succeeded": 2,
    "failed": 0
  },
  "results": [
    {
      "index": 0,
      "success": true,
      "alert_ids": ["alert-uuid-1"],
      "user_count": 1
    },
    {
      "index": 1,
      "success": true,
      "alert_ids": ["alert-uuid-2"],
      "user_count": 1
    }
  ]
}
```

**Note:** Returns HTTP 207 (Multi-Status) if some notifications succeed and others fail.

---

### Verbacall Signup

Update a Lead record when Verbacall confirms a signup. This is specifically designed for Verbacall integration.

**Request:**
```http
POST /legacy/notification_webhook.php?action=verbacall_signup
Content-Type: application/json
X-API-Key: your-api-key
```

**Body:**
```json
{
  "leadId": "lead-uuid",
  "signedUpAt": "2025-01-15T12:00:00Z",
  "userId": "verbacall-user-id",
  "email": "lead@example.com",
  "planName": "Business Plan"
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "lead_id": "lead-uuid",
    "verbacall_signup_c": true,
    "notification_sent": true,
    "journey_logged": true
  }
}
```

**Actions Performed:**
1. Updates `verbacall_signup_c = 1` on the Lead record
2. Creates a LeadJourney touchpoint (`verbacall_signup_confirmed`)
3. Sends notification to assigned BDM (appears in bell icon)

---

## Request Parameters

### Notification Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `title` | string | Yes | Notification title (max 255 chars) |
| `message` | string | No | Notification body text |
| `type` | string | No | Alert type: `info`, `success`, `warning`, `error` (default: `info`) |
| `priority` | string | No | Priority: `low`, `normal`, `high`, `urgent` (default: `normal`) |
| `target_users` | array | Conditional | Array of user UUIDs to notify |
| `target_roles` | array | Conditional | Array of role names to notify all users in |
| `url_redirect` | string | No | URL to open when notification is clicked |
| `target_module` | string | No | SuiteCRM module name (e.g., `Leads`) |
| `target_record` | string | No | Record UUID to link notification to |
| `metadata` | object | No | Additional data stored with notification |

**Note:** Either `target_users` or `target_roles` must be provided.

### Verbacall Signup Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `leadId` | string | Yes | SuiteCRM Lead UUID |
| `signedUpAt` | string | No | ISO 8601 timestamp of signup (default: now) |
| `userId` | string | No | Verbacall user ID |
| `email` | string | No | Email used for signup |
| `planName` | string | No | Verbacall plan name |

---

## Response Format

### Success Response

```json
{
  "success": true,
  "data": { ... }
}
```

### Error Response

```json
{
  "success": false,
  "error": "Error type",
  "message": "Detailed error message",
  "errors": ["List of individual errors (optional)"]
}
```

### HTTP Status Codes

| Code | Description |
|------|-------------|
| 200 | Success (for updates) |
| 201 | Created (for new notifications) |
| 207 | Multi-Status (batch with partial success) |
| 400 | Bad Request (validation error) |
| 401 | Unauthorized (invalid credentials) |
| 404 | Not Found (e.g., Lead not found) |
| 405 | Method Not Allowed |
| 429 | Rate Limit Exceeded |
| 500 | Internal Server Error |

---

## Examples

### cURL Examples

**Create a simple notification:**
```bash
curl -X POST "https://yoursite.com/legacy/notification_webhook.php" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{
    "title": "New Lead",
    "message": "A new lead has been assigned to you",
    "target_users": ["user-uuid"]
  }'
```

**Notify all users in a role:**
```bash
curl -X POST "https://yoursite.com/legacy/notification_webhook.php" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{
    "title": "Weekly Report Ready",
    "message": "The weekly sales report is now available",
    "type": "info",
    "target_roles": ["Sales Manager", "CRO"],
    "url_redirect": "#/funnel-dashboard"
  }'
```

**High priority notification with record link:**
```bash
curl -X POST "https://yoursite.com/legacy/notification_webhook.php" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{
    "title": "Urgent: Demo Scheduled",
    "message": "Demo with Acme Corp scheduled for today at 3pm",
    "type": "warning",
    "priority": "high",
    "target_users": ["bdm-user-uuid"],
    "target_module": "Leads",
    "target_record": "lead-uuid",
    "url_redirect": "#/leads/detail/lead-uuid"
  }'
```

**Verbacall signup confirmation:**
```bash
curl -X POST "https://yoursite.com/legacy/notification_webhook.php?action=verbacall_signup" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{
    "leadId": "abc123-def456-ghi789",
    "signedUpAt": "2025-01-15T14:30:00Z",
    "userId": "vc-user-12345",
    "email": "john.doe@example.com",
    "planName": "Business Plan"
  }'
```

**Using HMAC authentication:**
```bash
#!/bin/bash
TIMESTAMP=$(date +%s)
BODY='{"title":"Test","message":"Hello","target_users":["user-uuid"]}'
SECRET="your-webhook-secret"
SIGNATURE=$(echo -n "${TIMESTAMP}${BODY}" | openssl dgst -sha256 -hmac "$SECRET" | cut -d' ' -f2)

curl -X POST "https://yoursite.com/legacy/notification_webhook.php" \
  -H "Content-Type: application/json" \
  -H "X-Signature: $SIGNATURE" \
  -H "X-Timestamp: $TIMESTAMP" \
  -d "$BODY"
```

**Check webhook status:**
```bash
curl "https://yoursite.com/legacy/notification_webhook.php?action=status"
```

---

## Database Schema

The webhook uses the following database tables:

### notification_api_keys

Stores API keys for authentication.

```sql
CREATE TABLE notification_api_keys (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    last_used_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted TINYINT(1) DEFAULT 0
);
```

### notification_queue

Queue for WebSocket delivery of real-time notifications.

```sql
CREATE TABLE notification_queue (
    id VARCHAR(36) PRIMARY KEY,
    alert_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    payload TEXT,
    status ENUM('pending','sent','acknowledged') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    INDEX idx_user_status (user_id, status),
    INDEX idx_created_at (created_at)
);
```

### notification_rate_limit

Tracks request counts for rate limiting.

```sql
CREATE TABLE notification_rate_limit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_created (ip_address, created_at)
);
```

---

## Configuration

Add these settings to `/bitnami/suitecrm/public/legacy/config_override.php`:

```php
<?php

// HMAC webhook secret (for X-Signature authentication)
$sugar_config['notification_webhook_secret'] = 'your-secure-secret-here';

// JWT secret (for Bearer token authentication)
$sugar_config['notification_jwt_secret'] = 'your-jwt-secret-here';
```

### Creating API Keys

Via SQL:
```sql
INSERT INTO notification_api_keys (id, name, api_key, is_active)
VALUES (
    UUID(),
    'Verbacall Integration',
    'your-64-char-hex-key-here',
    1
);
```

Via PHP:
```php
require_once('modules/Webhooks/NotificationSecurity.php');
$apiKey = NotificationSecurity::generateApiKey();
echo "New API Key: $apiKey";
// Store this in the notification_api_keys table
```

---

## Troubleshooting

### Common Issues

**401 Unauthorized**
- Check that your API key is correct and active in `notification_api_keys`
- Verify the `X-API-Key` header is being sent
- For HMAC: ensure timestamp is within 5 minutes of server time

**429 Rate Limit Exceeded**
- Wait 60 seconds before retrying
- Consider using batch endpoint for multiple notifications

**404 Lead Not Found (Verbacall)**
- Verify the `leadId` is a valid UUID in SuiteCRM
- Check that the lead hasn't been deleted

**500 Internal Server Error**
- Check SuiteCRM logs: `/bitnami/suitecrm/public/legacy/suitecrm.log`
- Verify database connectivity
- Ensure all required tables exist

### Logs

Webhook activity is logged to SuiteCRM's log file:

```bash
tail -f /bitnami/suitecrm/public/legacy/suitecrm.log | grep "Notification"
```

Example log entries:
```
[INFO] Notification Webhook - Request from 192.168.1.1, Method: POST
[INFO] Notification Webhook accessed via api_key: Verbacall Integration from 192.168.1.1
[INFO] NotificationService: Created alert abc123 for user user-uuid
[INFO] Verbacall Webhook: Signup received for lead lead-uuid
[INFO] Verbacall Webhook: Updated lead lead-uuid - verbacall_signup_c = 1
```

---

## Security Best Practices

1. **Use HTTPS** - Always use HTTPS in production
2. **Rotate API Keys** - Periodically generate new keys and deprecate old ones
3. **IP Whitelisting** - Consider firewall rules to limit webhook access to known IPs
4. **Monitor Usage** - Review `last_used_at` in `notification_api_keys` for unusual activity
5. **Use HMAC for Critical Integrations** - HMAC provides payload integrity verification

---

## Changelog

### v1.0.0
- Initial release
- API Key, HMAC, and JWT authentication
- Create, Batch, and Verbacall signup actions
- Rate limiting (100 req/min per IP)
- WebSocket queue support
