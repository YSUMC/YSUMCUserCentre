#!/usr/bin/env python3
"""Stage this approved, host-specific production release; never opens the site.

Run as root on the existing server. Fails if the destination already exists.
Secrets and full backups stay in a root-only directory outside the web roots.
"""
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess as sp

OLD = Path('/www/wwwroot/ysumcusercentre')
NEW = Path('/www/wwwroot/ysumcusercentre-php85-20261022')
STATE = Path('/www/server/php-compat-assessments/20261022-migration')
BACK = STATE / 'production-cutover-20261022'
RUNTIME = Path('/opt/ysumc/php-8.5.12')
SOURCE_RUNTIME = Path('/www/server/php-compat-assessments/20261021-php85/runtime')
VHOST = Path('/www/server/panel/vhost/nginx/skin.ysumc.net.conf')
NGINX = '/www/server/nginx/sbin/nginx'
PROD_DB = 'ysumc-usercentre-database-1'
TEST_DB = 'ysumc-php85-test-db'
VERIFY_DB = 'ysumc_backup_verify_20261022'


def run(args, **kwargs):
    return sp.run([str(x) for x in args], check=True, **kwargs)


def digest(p):
    with p.open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()


def sql(container, query, database=None):
    command = 'MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}" exec mariadb -uroot -N -B'
    if database:
        assert database.replace('_', '').isalnum()
        command += ' ' + database
    return run(['docker', 'exec', '-i', container, 'sh', '-c', command], input=query.encode(), stdout=sp.PIPE).stdout


def counts(container, database):
    tables = sql(container, 'SHOW TABLES', database).decode().splitlines()
    assert all(t.replace('_', '').isalnum() for t in tables)
    query = ' UNION ALL '.join("SELECT '"+t+"', COUNT(*) FROM `"+t+'`' for t in tables)
    return dict(line.split('\t') for line in sql(container, query, database).decode().splitlines())


assert os.geteuid() == 0
assert not NEW.exists() and not BACK.exists() and not RUNTIME.exists()
assert '/www/server/stop' in VHOST.read_text()
assert run(['systemctl', 'is-active', 'ysumc-janus'], stdout=sp.PIPE).stdout.strip() == b'active'
BACK.mkdir(mode=0o700)
os.umask(0o077)
print('Creating private source/data and SQL backups...', flush=True)
run(['tar', '--acls', '--xattrs', '--numeric-owner', '--exclude=./.runtime/mysql', '--exclude=./.git',
     '-czf', BACK/'production-files.tar.gz', '-C', OLD, '.'])
with (BACK/'database.sql.gz').open('wb') as out:
    dump = sp.Popen(['docker', 'exec', PROD_DB, 'sh', '-c',
        'MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}" exec mariadb-dump -uroot --single-transaction --routines --events --triggers --hex-blob ysumc_skin'], stdout=sp.PIPE)
    zipper = run(['gzip', '-c'], stdin=dump.stdout, stdout=out)
    dump.stdout.close()
    assert dump.wait() == 0, 'Database dump failed'
run(['gzip', '-t', BACK/'database.sql.gz', BACK/'production-files.tar.gz'])
run(['tar', '-tzf', BACK/'production-files.tar.gz'], stdout=sp.DEVNULL)
shutil.copy2(VHOST, BACK/'nginx-stopped.conf')
shutil.copy2('/www/server/panel/vhost/rewrite/skin.ysumc.net.conf', BACK/'nginx-rewrite.conf')
shutil.copytree('/www/server/php/81/etc', BACK/'php81-etc', symlinks=True)
for name in ['ysumc-janus.service', 'ysumc-janus.service.d']:
    p = Path('/etc/systemd/system')/name
    if p.is_dir(): shutil.copytree(p, BACK/name)
    else: shutil.copy2(p, BACK/name)
protected = [OLD/'.env', OLD/'janus/.env', OLD/'janus/oauth-private.key', OLD/'storage/oauth-private.key', OLD/'storage/oauth-public.key']
(BACK/'protected-files.json').write_text(json.dumps({str(p):digest(p) for p in protected}, indent=2))
print('Restoring SQL into an isolated verification schema...', flush=True)
assert not sql(TEST_DB, "SHOW DATABASES LIKE '"+VERIFY_DB+"'").strip()
sql(TEST_DB, 'CREATE DATABASE '+VERIFY_DB)
with gzip.open(BACK/'database.sql.gz', 'rb') as inp:
    sql(TEST_DB, inp.read().decode(), VERIFY_DB)
before = counts(PROD_DB, 'ysumc_skin')
restored = counts(TEST_DB, VERIFY_DB)
assert before == restored, 'Restored row counts differ'
(BACK/'database-restore-check.json').write_text(json.dumps({'table_count':len(before), 'all_row_counts_match': True, 'rows': before}, indent=2))
sql(TEST_DB, 'DROP DATABASE '+VERIFY_DB)
(BACK/'SHA256SUMS').write_text('\n'.join(digest(BACK/f)+'  '+f for f in ['production-files.tar.gz','database.sql.gz','nginx-stopped.conf'])+'\n')
rollback = '''#!/bin/bash
set -euo pipefail
umask 077
BACK=/www/server/php-compat-assessments/20261022-migration/production-cutover-20261022
VHOST=/www/server/panel/vhost/nginx/skin.ysumc.net.conf
cp -a "$VHOST" "$BACK/nginx-before-rollback.conf"
cp "$BACK/nginx-stopped.conf" "$VHOST"
/www/server/nginx/sbin/nginx -t
/www/server/nginx/sbin/nginx -s reload
python3 - <<'PY'
import json,sqlite3
from pathlib import Path
backup=Path('/www/server/php-compat-assessments/20261022-migration/production-cutover-20261022')
row=json.loads((backup/'baota-site-before.json').read_text())
with sqlite3.connect('/www/server/panel/data/db/site.db') as con:
    changed=con.execute('UPDATE sites SET path=?,status=?,stop=? WHERE id=? AND name=?', (row['path'],row['status'],row['stop'],row['id'],row['name']))
    assert changed.rowcount == 1
PY
systemctl disable --now ysumc-php85-fpm.service
echo 'Rolled back to the original stopped-site vhost. Original code, PHP 8.1, database and Janus remain available.'
echo 'Database and shared uploads were NOT overwritten. Keep the site stopped while investigating.'
'''
(BACK/'rollback.sh').write_text(rollback)
(BACK/'rollback.sh').chmod(0o700)
run(['bash', '-n', BACK/'rollback.sh'])
print('Backup and restore rehearsal passed. Staging release and runtime...', flush=True)
os.umask(0o022)
NEW.mkdir(mode=0o755)
run(['rsync', '-a', '--exclude=/.runtime/', '--exclude=/.deploy/', '--exclude=/.git', '--exclude=/vendor/',
     '--exclude=/storage/', '--exclude=/bootstrap/cache/', '--exclude=node_modules/', str(OLD)+'/', str(NEW)+'/'])
(NEW/'storage').symlink_to(OLD/'storage', target_is_directory=True)
(NEW/'bootstrap/cache').mkdir(parents=True, mode=0o770)
run(['chown','www:www',NEW/'bootstrap/cache'])
run(['tar', '-xzf', STATE/'php85-maintenance-final-source.tar.gz', '-C', NEW])
user_ini = NEW/'public/.user.ini'
if user_ini.exists():
    content = user_ini.read_text().replace('open_basedir='+str(OLD)+'/:', 'open_basedir='+str(NEW)+'/:'+str(OLD)+'/storage/:')
    user_ini.write_text(content)
manifest = json.loads((STATE/'php85-maintenance-manifest.json').read_text())
for rel in manifest['deleted']:
    target = NEW/rel
    assert target.resolve().is_relative_to(NEW)
    target.unlink(missing_ok=True)
shutil.copytree(SOURCE_RUNTIME, RUNTIME, symlinks=True)
ini = '''[PHP]
expose_php = Off
display_errors = Off
log_errors = On
memory_limit = 256M
max_execution_time = 60
upload_max_filesize = 512M
post_max_size = 520M
date.timezone = Asia/Shanghai
cgi.fix_pathinfo = 0
session.upload_progress.enabled = Off
session.cookie_httponly = On
session.cookie_secure = On
session.use_strict_mode = On
'''
ext = RUNTIME/'lib/php/extensions/no-debug-non-zts-20250925'
ini += 'extension_dir = '+str(ext)+'\n'
(RUNTIME/'etc/php.ini').write_text(ini)
print('Release staged; Composer install and local validation still required.', flush=True)
(BACK/'stage.json').write_text(json.dumps({'release':str(NEW),'runtime':str(RUNTIME),'backup':str(BACK),'restored_tables':len(before),'source_commit':manifest['commit']}, indent=2))
