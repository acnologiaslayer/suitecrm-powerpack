FROM public.ecr.aws/bitnami/suitecrm:latest

USER root

# Patch Bitnami's libsuitecrm.sh to fix readonly variable error
# Remove readonly modifier from url_port to allow Bitnami to reassign it
RUN sed -i 's/readonly url_port/url_port/g' /opt/bitnami/scripts/libsuitecrm.sh 2>/dev/null || true

# Install additional dependencies including Node.js for WebSocket server and PHP IMAP
RUN apt-get update && apt-get install -y \
    git \
    curl \
    cron \
    default-mysql-client \
    gnupg \
    php-imap \
    && curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Copy custom modules and configurations
COPY --chown=daemon:daemon custom-modules/TwilioIntegration /opt/bitnami/suitecrm/modules/TwilioIntegration
COPY --chown=daemon:daemon custom-modules/LeadJourney /opt/bitnami/suitecrm/modules/LeadJourney
COPY --chown=daemon:daemon custom-modules/FunnelDashboard /opt/bitnami/suitecrm/modules/FunnelDashboard
COPY --chown=daemon:daemon custom-modules/SalesTargets /opt/bitnami/suitecrm/modules/SalesTargets
COPY --chown=daemon:daemon custom-modules/Packages /opt/bitnami/suitecrm/modules/Packages
COPY --chown=daemon:daemon custom-modules/Webhooks /opt/bitnami/suitecrm/modules/Webhooks
COPY --chown=daemon:daemon custom-modules/NotificationHub /opt/bitnami/suitecrm/modules/NotificationHub
COPY --chown=daemon:daemon custom-modules/VerbacallIntegration /opt/bitnami/suitecrm/modules/VerbacallIntegration
COPY --chown=daemon:daemon custom-modules/InboundEmail /opt/bitnami/suitecrm/modules/InboundEmail

# Copy and setup WebSocket notification server
COPY --chown=daemon:daemon config/notification-websocket /opt/bitnami/suitecrm/notification-websocket
RUN cd /opt/bitnami/suitecrm/notification-websocket && npm install --production

# Copy custom field extensions
COPY --chown=daemon:daemon custom-modules/Extensions /opt/bitnami/suitecrm/custom/Extension

# Copy custom extensions (click-to-call, notification, verbacall, leadjourney and incoming call JS for Angular UI)
COPY --chown=daemon:daemon config/custom-extensions/dist/twilio-click-to-call.js /opt/bitnami/suitecrm/dist/twilio-click-to-call.js
COPY --chown=daemon:daemon config/custom-extensions/dist/notification-ws.js /opt/bitnami/suitecrm/dist/notification-ws.js
COPY --chown=daemon:daemon config/custom-extensions/dist/verbacall-integration.js /opt/bitnami/suitecrm/dist/verbacall-integration.js
COPY --chown=daemon:daemon config/custom-extensions/dist/leadjourney-buttons.js /opt/bitnami/suitecrm/dist/leadjourney-buttons.js
COPY --chown=daemon:daemon config/custom-extensions/dist/twilio-incoming-call.js /opt/bitnami/suitecrm/dist/twilio-incoming-call.js

# Ensure JS files have proper permissions for web serving
RUN chmod 644 /opt/bitnami/suitecrm/dist/*.js

# Copy installation scripts
COPY install-scripts/install-modules.sh /opt/bitnami/scripts/suitecrm/install-modules.sh
COPY install-scripts/silent-install.sh /opt/bitnami/scripts/suitecrm/silent-install.sh
COPY install-scripts/enable-modules-suite8.sh /opt/bitnami/scripts/suitecrm/enable-modules-suite8.sh
RUN chmod +x /opt/bitnami/scripts/suitecrm/install-modules.sh && \
    chmod +x /opt/bitnami/scripts/suitecrm/silent-install.sh && \
    chmod +x /opt/bitnami/scripts/suitecrm/enable-modules-suite8.sh

# Copy custom entrypoint
COPY docker-entrypoint.sh /opt/bitnami/scripts/suitecrm/powerpack-entrypoint.sh
RUN chmod +x /opt/bitnami/scripts/suitecrm/powerpack-entrypoint.sh

# Create config_override.php.template for PowerPack features
RUN echo '<?php' > /opt/bitnami/suitecrm/config_override.php.template && \
    echo '// Twilio Integration Configuration' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_account_sid"] = getenv("TWILIO_ACCOUNT_SID") ?: "";' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_auth_token"] = getenv("TWILIO_AUTH_TOKEN") ?: "";' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_phone_number"] = getenv("TWILIO_PHONE_NUMBER") ?: "";' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_fallback_phone"] = getenv("TWILIO_FALLBACK_PHONE") ?: "";' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_enable_click_to_call"] = true;' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_enable_auto_logging"] = true;' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_enable_recordings"] = true;' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_auto_create_lead"] = false;' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '// Notification WebSocket Configuration' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["notification_jwt_secret"] = getenv("NOTIFICATION_JWT_SECRET") ?: "default-secret-change-me";' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["notification_ws_url"] = getenv("NOTIFICATION_WS_URL") ?: "ws://localhost:3001";' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '// Verbacall Integration Configuration' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["verbacall_api_url"] = getenv("VERBACALL_API_URL") ?: "https://app.verbacall.com";' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["verbacall_default_discount"] = 10;' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["verbacall_expiry_days"] = 7;' >> /opt/bitnami/suitecrm/config_override.php.template && \
    chown daemon:daemon /opt/bitnami/suitecrm/config_override.php.template

# Expose ports: 8080 (HTTP), 8443 (HTTPS), 3001 (WebSocket)
EXPOSE 8080 8443 3001

# Use custom entrypoint that handles volume persistence
# Note: Entrypoint runs as root to copy files, then Apache runs as daemon user
ENTRYPOINT ["/opt/bitnami/scripts/suitecrm/powerpack-entrypoint.sh"]
