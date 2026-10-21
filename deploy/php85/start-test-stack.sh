#!/usr/bin/env bash
set -euo pipefail
APP=/www/wwwroot/ysumcusercentre-next
STATE=/www/server/php-compat-assessments/20261022-migration
PHP=/www/server/php-compat-assessments/20261021-php85/runtime
[[ $(id -u) == 0 && -f "$STATE/http-fixture.json" && -x "$APP/.runtime/node" ]]
[[ -f "$APP/.env" && -f "$APP/janus/.env" ]]
docker start ysumc-php85-test-db >/dev/null
for i in {1..30}; do
    if docker exec ysumc-php85-test-db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then break; fi
    sleep 1
done
docker exec ysumc-php85-test-db healthcheck.sh --connect --innodb_initialized >/dev/null
mkdir -p "$STATE/logs" "$APP/storage/framework/nginx"
chown -R www:www "$APP/storage" "$APP/bootstrap/cache"
"$PHP/sbin/php-fpm" -t -y "$APP/deploy/php85/php-fpm-test.conf"
/www/server/nginx/sbin/nginx -t -p "$STATE/" -c "$APP/deploy/php85/nginx-test.conf"
if ! systemctl is-active --quiet ysumc-php85-candidate-fpm; then
    systemd-run --unit=ysumc-php85-candidate-fpm --property=Restart=no "$PHP/sbin/php-fpm" -y "$APP/deploy/php85/php-fpm-test.conf"
fi
if ! systemctl is-active --quiet ysumc-php85-candidate-janus; then
    systemd-run --unit=ysumc-php85-candidate-janus --uid=www --gid=www --working-directory="$APP/janus" --setenv=NODE_ENV=production --property=Restart=no "$APP/.runtime/node" dist/main.js
fi
if ! systemctl is-active --quiet ysumc-php85-candidate-nginx; then
    systemd-run --unit=ysumc-php85-candidate-nginx --property=Restart=no /www/server/nginx/sbin/nginx -p "$STATE/" -c "$APP/deploy/php85/nginx-test.conf" -g 'daemon off;'
fi
echo 'Candidate only: http://localhost:13850 ; production is unchanged.'
