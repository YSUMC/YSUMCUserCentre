#!/bin/sh
set -eu

# Install as a Certbot deploy hook once this site's certificate is issued.
# Leave unrelated certificates and their reload behavior alone.
if [ "${RENEWED_LINEAGE:-}" != "/etc/letsencrypt/live/skin.ysumc.net" ]; then
    exit 0
fi

/www/server/nginx/sbin/nginx -t
/www/server/nginx/sbin/nginx -s reload
