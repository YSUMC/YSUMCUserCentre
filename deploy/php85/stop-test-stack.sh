#!/usr/bin/env bash
set -euo pipefail
[[ $(id -u) == 0 ]]
for unit in ysumc-php85-candidate-nginx ysumc-php85-candidate-janus ysumc-php85-candidate-fpm; do
    if systemctl is-active --quiet "$unit"; then systemctl stop "$unit"; fi
done
docker stop ysumc-php85-test-db >/dev/null
echo 'Candidate services stopped; candidate files/database retained. Production unchanged.'
