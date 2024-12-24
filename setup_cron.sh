#!/bin/bash

# Add cron job to run every 5 minutes
(crontab -l 2>/dev/null; echo "*/5 * * * * php /www/wwwroot/vermino.uz/bots/orders/ibo/check_rentals.php") | crontab - 