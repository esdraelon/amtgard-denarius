#!/bin/sh
mkdir -p /var/www/denarius.amtgard.com/logs /var/www/denarius.amtgard.com/config/cache/twig
touch /var/www/denarius.amtgard.com/logs/method-trace.jsonl 2>/dev/null || true
chown -R www-data:www-data /var/www/denarius.amtgard.com/logs /var/www/denarius.amtgard.com/config/cache/twig 2>/dev/null || true
chmod 775 /var/www/denarius.amtgard.com/logs 2>/dev/null || true
/usr/sbin/service nginx start
/usr/sbin/service php8.4-fpm start
/usr/sbin/service memcached start
while true; do sleep 1; done
