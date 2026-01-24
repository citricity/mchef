#!/bin/bash
set -e

# Only start cron if this container is designated as the cron runner
if [ "$ENABLE_MOODLE_CRON" = "true" ]; then
    echo "Starting cron service..."
    service cron start
    
    # Create Moodle cron job
    echo "*/5 * * * * www-data /usr/local/bin/php $MOODLE_PATH/admin/cli/cron.php >/dev/null 2>&1" > /etc/cron.d/moodle-cron
    chmod 0644 /etc/cron.d/moodle-cron
    crontab /etc/cron.d/moodle-cron
    echo "Moodle cron job configured"
else
    echo "Moodle cron disabled (set ENABLE_MOODLE_CRON=true to enable)"
fi

# Don't exec anything, just return so the CMD can continue
echo "MChef initialization complete"