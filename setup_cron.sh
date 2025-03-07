#!/bin/bash

# Add cron job to run every 5 minutes
(crontab -l 2>/dev/null; echo "*/5 * * * * php /www/wwwroot/path/") | crontab - 
