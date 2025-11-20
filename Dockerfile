FROM public.ecr.aws/bitnami/suitecrm:latest

USER root

# Patch Bitnami's libsuitecrm.sh to fix readonly variable error
# Remove readonly modifier from url_port to allow Bitnami to reassign it
RUN sed -i 's/readonly url_port/url_port/g' /opt/bitnami/scripts/libsuitecrm.sh 2>/dev/null || true

# Download and install DigitalOcean CA certificate for SSL connections
RUN mkdir -p /opt/bitnami/mysql/certs && \
    curl -sSL -o /opt/bitnami/mysql/certs/ca-certificate.crt \
    https://docs.digitalocean.com/_next/static/media/ca-certificate.0d9f5b78.crt \
    && chmod 644 /opt/bitnami/mysql/certs/ca-certificate.crt

# Install additional dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    cron \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Copy custom modules and configurations
COPY --chown=daemon:daemon custom-modules/TwilioIntegration /opt/bitnami/suitecrm/modules/TwilioIntegration
COPY --chown=daemon:daemon custom-modules/LeadJourney /opt/bitnami/suitecrm/modules/LeadJourney
COPY --chown=daemon:daemon custom-modules/FunnelDashboard /opt/bitnami/suitecrm/modules/FunnelDashboard

# Copy installation scripts
COPY install-scripts/install-modules.sh /opt/bitnami/scripts/suitecrm/install-modules.sh
COPY install-scripts/silent-install.sh /opt/bitnami/scripts/suitecrm/silent-install.sh
RUN chmod +x /opt/bitnami/scripts/suitecrm/install-modules.sh && \
    chmod +x /opt/bitnami/scripts/suitecrm/silent-install.sh

# Copy custom entrypoint
COPY docker-entrypoint.sh /opt/bitnami/scripts/suitecrm/powerpack-entrypoint.sh
RUN chmod +x /opt/bitnami/scripts/suitecrm/powerpack-entrypoint.sh

# Create config_override.php.template for PowerPack features
RUN echo '<?php' > /opt/bitnami/suitecrm/config_override.php.template && \
    echo '// Twilio Integration Configuration' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_account_sid"] = getenv("TWILIO_ACCOUNT_SID") ?: "";' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_auth_token"] = getenv("TWILIO_AUTH_TOKEN") ?: "";' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_phone_number"] = getenv("TWILIO_PHONE_NUMBER") ?: "";' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_enable_click_to_call"] = true;' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_enable_auto_logging"] = true;' >> /opt/bitnami/suitecrm/config_override.php.template && \
    echo '$sugar_config["twilio_enable_recordings"] = true;' >> /opt/bitnami/suitecrm/config_override.php.template && \
    chown daemon:daemon /opt/bitnami/suitecrm/config_override.php.template

# Expose Bitnami default ports
EXPOSE 8080 8443

# Use custom entrypoint that handles volume persistence
# Note: Entrypoint runs as root to copy files, then Apache runs as daemon user
ENTRYPOINT ["/opt/bitnami/scripts/suitecrm/powerpack-entrypoint.sh"]
