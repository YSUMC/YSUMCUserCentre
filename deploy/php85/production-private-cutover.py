#!/usr/bin/env python3
"""Start production PHP 8.5 and switch ONLY loopback traffic to the release."""
import json
from pathlib import Path
import shutil
import sqlite3
import subprocess as sp

BACK = Path('/www/server/php-compat-assessments/20261022-migration/production-cutover-20261022')
ROOT = Path('/www/wwwroot/ysumcusercentre-php85-20261022')
PHP = Path('/opt/ysumc/php-8.5.12')
VHOST = Path('/www/server/panel/vhost/nginx/skin.ysumc.net.conf')
NGINX = '/www/server/nginx/sbin/nginx'
assert (BACK/'database-restore-check.json').exists() and (BACK/'rollback.sh').exists()
assert VHOST.read_bytes() == (BACK/'nginx-stopped.conf').read_bytes()
assert not Path('/etc/systemd/system/ysumc-php85-fpm.service').exists()
db = Path('/www/server/panel/data/db/site.db')
con = sqlite3.connect(db)
con.row_factory = sqlite3.Row
rows = [dict(r) for r in con.execute('SELECT * FROM sites WHERE name=?', ('skin.ysumc.net',))]
assert len(rows) == 1, 'Cannot uniquely locate BaoTa site metadata'
(BACK/'baota-site-before.json').write_text(json.dumps(rows[0], indent=2))
copy = sqlite3.connect(BACK/'baota-site.db')
con.backup(copy)
copy.close()
con.close()
(PHP/'etc/php-fpm.conf').write_text('''[global]
pid = /run/ysumc-php85/php-fpm.pid
error_log = /var/log/ysumc-php85/fpm.log
daemonize = no
[ysumc]
user = www
group = www
listen = /run/ysumc-php85/php-fpm.sock
listen.owner = www
listen.group = www
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 15s
pm.max_requests = 500
chdir = /www/wwwroot/ysumcusercentre-php85-20261022
catch_workers_output = yes
clear_env = yes
security.limit_extensions = .php
php_admin_flag[session.upload_progress.enabled] = off
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
''')
unit = '''[Unit]
Description=YSUMC production PHP 8.5.12 FPM
After=network.target docker.service
[Service]
Type=simple
ExecStart=/opt/ysumc/php-8.5.12/sbin/php-fpm -F -c /opt/ysumc/php-8.5.12/etc/php.ini -y /opt/ysumc/php-8.5.12/etc/php-fpm.conf
ExecReload=/bin/kill -USR2 $MAINPID
Restart=on-failure
RestartSec=5
RuntimeDirectory=ysumc-php85
LogsDirectory=ysumc-php85
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/www/wwwroot/ysumcusercentre/storage /www/wwwroot/ysumcusercentre-php85-20261022/bootstrap/cache /www/wwwroot/ysumcusercentre-php85-20261022/plugins
NoNewPrivileges=true
UMask=0027
[Install]
WantedBy=multi-user.target
'''
Path('/etc/systemd/system/ysumc-php85-fpm.service').write_text(unit)
sp.run([str(PHP/'sbin/php-fpm'), '-t', '-c', str(PHP/'etc/php.ini'), '-y', str(PHP/'etc/php-fpm.conf')], check=True, capture_output=True) if Path('/var/log/ysumc-php85').exists() else None
sp.run(['systemctl','daemon-reload'],check=True)
sp.run(['systemctl','start','ysumc-php85-fpm'],check=True)
sp.run(['systemctl','is-active','ysumc-php85-fpm'],check=True)
original = VHOST.read_text()
stopped = '''    rewrite ^/(?!bt-stop\\.html$).* /bt-stop.html last;
    location = /bt-stop.html {
        root /www/server/stop;
        internal;
    }
'''
assert stopped in original
active = original.replace('root /www/server/stop;', 'root '+str(ROOT)+'/public;', 1).replace(stopped, '')
active = active.replace('location /api/janus/ {', 'location ^~ /api/janus/ {')
active = active.replace('#include /www/server/panel/vhost/rewrite/skin.ysumc.net.conf;', 'include /www/server/panel/vhost/rewrite/skin.ysumc.net.conf;')
active = active.replace('#include enable-php-81.conf;', '''# Dedicated PHP 8.5.12 runtime; managed by ysumc-php85-fpm.service.
    location = /index.php {
        include /www/server/nginx/conf/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTPS $https if_not_empty;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/ysumc-php85/php-fpm.sock;
        fastcgi_read_timeout 90s;
    }''')
gate = '''    # Only TCP connections addressed to loopback can reach the new release.
    # Request headers cannot bypass this gate.
    if ($server_addr != 127.0.0.1) {
        rewrite ^/(?!bt-stop\\.html$).* /bt-stop.html last;
    }
    location = /bt-stop.html {
        root /www/server/stop;
        internal;
    }
'''
private = active.replace('    index index.php;', gate+'\n    index index.php;')
(BACK/'nginx-public-ready.conf').write_text(active)
(BACK/'nginx-private.conf').write_text(private)
try:
    # Test the final public config on disk, without loading it into Nginx.
    VHOST.write_text(active)
    sp.run([NGINX,'-t'],check=True)
    VHOST.write_text(private)
    sp.run([NGINX,'-t'],check=True)
    sp.run([NGINX,'-s','reload'],check=True)
except BaseException:
    shutil.copy2(BACK/'nginx-stopped.conf',VHOST)
    sp.run([NGINX,'-t'],check=True)
    sp.run([NGINX,'-s','reload'],check=True)
    raise
print('Loopback cutover applied. Public access remains on the stopped page.')
