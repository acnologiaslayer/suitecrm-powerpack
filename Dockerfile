FROM public.ecr.aws/bitnami/suitecrm:latest

USER root

# Install additional dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Copy custom modules and configurations
COPY --chown=daemon:daemon custom-modules/TwilioIntegration /opt/bitnami/suitecrm/modules/TwilioIntegration
COPY --chown=daemon:daemon custom-modules/LeadJourney /opt/bitnami/suitecrm/modules/LeadJourney
COPY --chown=daemon:daemon custom-modules/FunnelDashboard /opt/bitnami/suitecrm/modules/FunnelDashboard

# Copy installation scripts
COPY install-scripts/install-modules.sh /opt/bitnami/scripts/suitecrm/install-modules.sh
RUN chmod +x /opt/bitnami/scripts/suitecrm/install-modules.sh

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

# Switch to daemon user (Bitnami non-root user)
USER 1001

# Expose Bitnami default ports
EXPOSE 8080 8443

# Use custom entrypoint that wraps Bitnami's entrypoint
ENTRYPOINT ["/opt/bitnami/scripts/suitecrm/powerpack-entrypoint.sh"]
CMD ["/opt/bitnami/scripts/suitecrm/run.sh"]
