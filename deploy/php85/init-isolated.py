#!/usr/bin/env python3
"""Create a fresh local-only application/database candidate; never alter production."""
import base64
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import time

SOURCE = Path('/www/wwwroot/ysumcusercentre')
TARGET = Path('/www/wwwroot/ysumcusercentre-next')
STATE = Path('/www/server/php-compat-assessments/20261022-migration')
CONTAINER = 'ysumc-php85-test-db'


def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def main():
    assert os.geteuid() == 0
    assert SOURCE.is_dir() and not TARGET.exists() and not STATE.exists()
    existing = subprocess.check_output(['docker', 'ps', '-a', '--format', '{{.Names}}'], text=True).splitlines()
    assert CONTAINER not in existing
    STATE.mkdir(mode=0o700)
    baseline = {}
    for folder in ['app', 'config', 'routes', 'plugins', 'database']:
        for file in (SOURCE / folder).rglob('*'):
            if file.is_file() and file.suffix in ('.php', '.json', '.twig'):
                baseline[str(file)] = hashlib.sha256(file.read_bytes()).hexdigest()
    for file in [SOURCE/'.env', SOURCE/'composer.lock', Path('/www/server/panel/vhost/nginx/skin.ysumc.net.conf'), Path('/www/server/php/81/bin/php')]:
        baseline[str(file)] = hashlib.sha256(file.read_bytes()).hexdigest()
    (STATE/'production-baseline.json').write_text(json.dumps(baseline, indent=2))
    excludes = ['.git', '.env', '.deploy', '.runtime', '.ysumc-source.tar.gz', 'vendor',
                'node_modules', 'janus/.env', 'storage/logs/*', 'storage/framework/cache/*',
                'storage/framework/sessions/*', 'storage/framework/views/*', 'bootstrap/cache/*.php',
                'storage/oauth*.key', 'storage/*private.key', 'storage/*public.key',
                'janus/oauth-private.key', 'storage/options.php', 'deploy/php81-repair-kit']
    run(['rsync', '-a'] + ['--exclude=/' + e for e in excludes] +
        ['--exclude=node_modules', str(SOURCE)+'/', str(TARGET)+'/'])
    for link in TARGET.rglob('*'):
        if link.is_symlink():
            assert not str(link.resolve()).startswith(str(SOURCE)), f'Production symlink: {link}'
    os.chmod(TARGET, 0o755)
    info = json.loads(subprocess.check_output(['docker', 'inspect', 'ysumc-usercentre-database-1']))[0]
    original = dict(s.split('=', 1) for s in info['Config']['Env'] if '=' in s)
    database = 'ysumc_php85_test'
    password = secrets.token_hex(24)
    root_password = secrets.token_hex(24)
    db_env = STATE/'database.env'
    db_env.write_text(f'MARIADB_ROOT_PASSWORD={root_password}\nMARIADB_DATABASE={database}\nMARIADB_USER=ysumc_test\nMARIADB_PASSWORD={password}\n')
    db_env.chmod(0o600)
    run(['docker', 'run', '-d', '--name', CONTAINER, '--restart=no',
         '--memory=768m', '--cpus=1', '--publish=127.0.0.1:13385:3306',
         '--env-file', str(db_env), '--volume', str(STATE/'mysql')+':/var/lib/mysql', info['Config']['Image']], stdout=subprocess.DEVNULL)
    for _ in range(60):
        result = subprocess.run(['docker', 'exec', CONTAINER, 'healthcheck.sh', '--connect', '--innodb_initialized'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if result.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('Candidate database did not become ready')
    snapshot = STATE/'production-snapshot.sql'
    with snapshot.open('wb') as output:
        run(['docker','exec','ysumc-usercentre-database-1','sh','-c',
             'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump -uroot --single-transaction --skip-lock-tables --hex-blob "$MARIADB_DATABASE"'], stdout=output)
    snapshot.chmod(0o600)
    with snapshot.open('rb') as input_file:
        run(['docker','exec','-i',CONTAINER,'sh','-c',
             'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot "$MARIADB_DATABASE"'], stdin=input_file)
    # Build an allowlisted candidate environment, with no external service credentials.
    env = {'APP_NAME':'YSUMC PHP85 Candidate', 'APP_ENV':'testing',
           'APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(), 'APP_DEBUG':'false',
           'APP_URL':'http://127.0.0.1:13850','DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1',
           'DB_PORT':'13385','DB_DATABASE':database,'DB_USERNAME':'ysumc_test','DB_PASSWORD':password,
           'CACHE_DRIVER':'file','CACHE_STORE':'file','CACHE_PREFIX':'ysumc_php85_test',
           'SESSION_DRIVER':'file','SESSION_COOKIE':'ysumc_php85_test','SESSION_SECURE_COOKIE':'false',
           'QUEUE_CONNECTION':'sync','MAIL_MAILER':'log','MAIL_FROM_ADDRESS':'test@example.invalid',
           'MAIL_FROM_NAME':'YSUMC PHP85 Candidate','YSUMC_USERINFO_URL':'http://127.0.0.1:13851/me',
           'EDUROAM_HOST':'http://127.0.0.1:13852','EDUROAM_STORE_HOST':'http://127.0.0.1:13852',
           'PLUGINS_REGISTRY':'http://127.0.0.1:13852','YSUMC_CURSEFORGE_API_KEY':''}
    (TARGET/'.env').write_text(''.join(k+'='+json.dumps(v)+'\n' for k,v in env.items()))
    run(['chown','root:www',str(TARGET/'.env')])
    (TARGET/'.env').chmod(0o640)
    user_ini = TARGET/'public/.user.ini'
    if user_ini.exists():
        user_ini.write_text(user_ini.read_text().replace(str(SOURCE),str(TARGET)))
    (STATE/'initialization.json').write_text(json.dumps({'source':str(SOURCE),'candidate':str(TARGET),'database_container':CONTAINER,'database_port':13385,'snapshot_sha256':hashlib.sha256(snapshot.read_bytes()).hexdigest()},indent=2))
    print('Isolated application and database snapshot created. No production service/config changed.')


if __name__ == '__main__':
    main()
