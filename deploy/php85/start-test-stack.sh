#!/usr/bin/env bash
set -euo pipefail
APP=/www/wwwroot/ysumcusercentre-next
STATE=/www/server/php-compat-assessments/20261022-migration
PHP=/www/server/php-compat-assessments/20261021-php85/runtime
NODE=/opt/ysumc-php85-candidate/runtimes/node-v22.23.4-linux-x64/bin/node
[[ $(id -u) == 0 && -f "$STATE/http-fixture.json" && -x "$NODE" ]]
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
    systemd-run --unit=ysumc-php85-candidate-janus --uid=www --gid=www --working-directory="$APP/janus" --setenv=NODE_ENV=production --setenv="PATH=$(dirname "$NODE"):/usr/bin:/bin" --property=Restart=no --property=NoNewPrivileges=true --property=PrivateTmp=true --property=ProtectHome=true --property=ProtectSystem=strict --property=UMask=0027 "$NODE" dist/main.js
fi
if ! systemctl is-active --quiet ysumc-php85-candidate-nginx; then
    systemd-run --unit=ysumc-php85-candidate-nginx --property=Restart=no /www/server/nginx/sbin/nginx -p "$STATE/" -c "$APP/deploy/php85/nginx-test.conf" -g 'daemon off;'
fi
echo 'Candidate only: http://localhost:13850 ; production is unchanged.'
