#!/bin/bash
set -e

# Only start cron if this container is designated as the cron runner
if [ "$ENABLE_MOODLE_CRON" = "true" ]; then

    # Ensure MOODLE_PATH is set before creating the cron job
    if [ -z "${MOODLE_PATH:-}" ]; then
        echo "Error: MOODLE_PATH is not set; cannot configure Moodle cron job." >&2
        exit 1
    fi    
    
    # Create Moodle cron job
    echo "*/5 * * * * www-data /usr/local/bin/php $MOODLE_PATH/admin/cli/cron.php >> /proc/1/fd/1 2>> /proc/1/fd/2" > /etc/cron.d/moodle-cron
    chmod 0644 /etc/cron.d/moodle-cron
    echo "Moodle cron job configured"

    echo "Starting cron service..."
    service cron start
else
    echo "Moodle cron disabled (set ENABLE_MOODLE_CRON=true to enable)"
fi

# Don't exec anything, just return so the CMD can continue
echo "MChef initialization complete"