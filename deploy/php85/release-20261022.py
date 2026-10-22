#!/usr/bin/env python3
"""Reviewed release steps for this host. Explicit phases; never opens on test failure.

Upload the Git archive and source.json into STATE before candidate/stage.
Backups, credentials and verbose logs stay outside the web root, mode 0700.
"""
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess as sp
import sys

STATE = Path('/www/server/php-compat-assessments/20261022-review')
OLD = Path('/www/wwwroot/ysumcusercentre-php85-20261022')
SHARED = Path('/www/wwwroot/ysumcusercentre')
PREVIEW = Path('/www/wwwroot/ysumcusercentre-next')
TEST = Path('/www/wwwroot/ysumcusercentre-review-20261022')
NEW = Path('/www/wwwroot/ysumcusercentre-release-20261022')
BACK = STATE/'backup'
VHOST = Path('/www/server/panel/vhost/nginx/skin.ysumc.net.conf')
NGINX = '/www/server/nginx/sbin/nginx'
PHP = '/opt/ysumc/php-8.5.12/bin/php'
INI = '/opt/ysumc/php-8.5.12/etc/php.ini'
COMPOSER = '/www/server/php-compat-assessments/20261022-migration/composer.phar'
NODE = '/www/server/nodejs/v26.12.1/bin/node'
PROD_DB = 'ysumc-usercentre-database-1'
TEST_DB = 'ysumc-php85-test-db'

def run(args, **kwargs):
    return sp.run([str(x) for x in args], check=True, **kwargs)

def log(name, args, cwd=None, env=None):
    print(name, flush=True)
    with (STATE/(name+'.log')).open('wb') as out:
        run(args, cwd=cwd, env=env, stdout=out, stderr=sp.STDOUT)

def digest(path):
    with Path(path).open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()

def sql(container, query, database=None):
    cmd = 'MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}" exec mariadb -uroot -N -B'
    if database:
        assert database.replace('_', '').isalnum()
        cmd += ' '+database
    return run(['docker','exec','-i',container,'sh','-c',cmd], input=query.encode(), stdout=sp.PIPE).stdout

def counts(container, database):
    tables = sql(container,'SHOW TABLES',database).decode().splitlines()
    assert all(t.replace('_','').isalnum() for t in tables)
    query = ' UNION ALL '.join("SELECT '"+t+"', COUNT(*) FROM `"+t+'`' for t in tables)
    return dict(line.split('\t') for line in sql(container,query,database).decode().splitlines())

def populate(target, source, production=False):
    assert not target.exists()
    target.mkdir(mode=0o755)
    target.chmod(0o755)
    assert digest(STATE/'source.tar.gz') == json.loads((STATE/'source.json').read_text())['archive_sha256']
    run(['tar','-xzf',STATE/'source.tar.gz','-C',target])
    for folder in ['public/app', 'resources/views/assets']:
        run(['rsync','-a',str(source/folder)+'/',str(target/folder)+'/'])
    shutil.copy2(source/'.env',target/'.env')
    if production:
        (target/'storage').symlink_to(SHARED/'storage',target_is_directory=True)
    else:
        shutil.copytree(source/'storage',target/'storage',symlinks=False)
    (target/'bootstrap/cache').mkdir(parents=True,exist_ok=True)
    shutil.copytree(source/'vendor',target/'vendor',symlinks=False)
    for rel in ['janus/.env','janus/oauth-private.key','janus/prisma/schema.prisma']:
        if (source/rel).exists():
            (target/rel).parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(source/rel,target/rel)
    # Dependencies are copied only from the Linux tree with the same lock.
    dependencies = TEST if production else source
    assert digest(dependencies/'janus/package-lock.json') == digest(target/'janus/package-lock.json')
    shutil.copytree(dependencies/'janus/node_modules',target/'janus/node_modules',symlinks=True)
    if (source/'public/.user.ini').exists():
        text = (source/'public/.user.ini').read_text().replace(str(source),str(target))
        (target/'public/.user.ini').write_text(text)
        (target/'public/.user.ini').chmod(0o644)
    run(['chown','-R','root:www',target])
    for rel in ['.env','janus/.env','janus/oauth-private.key']:
        if (target/rel).exists(): (target/rel).chmod(0o640)
    for folder in ['bootstrap/cache','plugins'] + ([] if production else ['storage']):
        run(['chown','-R','www:www',target/folder])
    return target

def candidate():
    populate(TEST,PREVIEW)
    log('candidate-composer',[PHP,'-c',INI,COMPOSER,'install','--no-interaction','--prefer-dist'],TEST)
    log('candidate-platform',[PHP,'-c',INI,COMPOSER,'check-platform-reqs'],TEST)
    log('candidate-audit',[PHP,'-c',INI,COMPOSER,'audit','--locked','--format=json'],TEST)
    # Initialize schema before per-test transactions, and race two independent
    # processes against the same old schema to exercise the MySQL DDL lock.
    script = "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if(config('database.connections.mysql.database')!=='ysumc_php85_test'||config('database.connections.mysql.port')!=13385) throw new RuntimeException('Wrong database'); SITMinecraft\\Launcher\\Database\\LauncherSchema::install(); echo 'Schema ready'.PHP_EOL;"
    with (STATE/'candidate-schema.log').open('wb') as out:
        processes = [sp.Popen([PHP,'-c',INI,'-r',script],cwd=TEST,stdout=out,stderr=sp.STDOUT) for _ in range(2)]
        statuses = [p.wait() for p in processes]
        assert statuses == [0, 0]
    log('candidate-phpunit',[PHP,'-c',INI,'vendor/bin/phpunit'],TEST)
    for suite in ['security','interop']:
        log('candidate-'+suite,[PHP,'-c',INI,'vendor/bin/phpunit','-c','phpunit.'+suite+'.xml'],TEST)
    log('candidate-launcher',[PHP,'-c',INI,'tests/launcher-contract.php'],TEST)
    log('candidate-interop-ui',[NODE,'--test','tests/interop-publications.test.cjs'],TEST)
    log('candidate-janus-build',[NODE,'node_modules/typescript/bin/tsc','-p','tsconfig.build.json','--incremental','false'],TEST/'janus')
    tests = sorted((TEST/'janus/test').glob('*.test.cjs'))
    log('candidate-janus-tests',[NODE,'--test',*tests],TEST/'janus')
    (STATE/'candidate-passed.json').write_text(json.dumps({'php':'8.5.12','node':'26.12.1','source':json.loads((STATE/'source.json').read_text())},indent=2))
    print('Candidate passed; production still stopped.')

def stage():
    assert (STATE/'candidate-passed.json').exists()
    assert '/www/server/stop' in VHOST.read_text() and not BACK.exists()
    BACK.mkdir(mode=0o700)
    # Preserve the exact stopped-site state and all application data first.
    shutil.copy2(VHOST,BACK/'nginx.conf')
    shutil.copy2('/opt/ysumc/php-8.5.12/etc/php-fpm.conf',BACK/'php-fpm.conf')
    shutil.copytree('/etc/systemd/system/ysumc-janus.service.d',BACK/'janus-dropins')
    shutil.copy2('/etc/systemd/system/ysumc-php85-fpm.service',BACK/'php.service')
    with sqlite3.connect('/www/server/panel/data/db/site.db') as db:
        db.row_factory=sqlite3.Row
        rows=list(db.execute('SELECT * FROM sites WHERE name=?',('skin.ysumc.net',)))
        assert len(rows)==1
        (BACK/'site.json').write_text(json.dumps(dict(rows[0])))
        with sqlite3.connect(BACK/'site.db') as destination: db.backup(destination)
    for name,path in [('previous-release',OLD),('shared-original',SHARED)]:
        log('backup-'+name,['tar','--acls','--xattrs','--numeric-owner','--exclude=./.runtime/mysql','--exclude=./.git','--exclude=./.deploy','-czf',BACK/(name+'.tar.gz'),'-C',path,'.'])
    with (BACK/'database.sql.gz').open('wb') as out:
        dump=sp.Popen(['docker','exec',PROD_DB,'sh','-c','MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}" exec mariadb-dump -uroot --single-transaction --routines --events --triggers --hex-blob ysumc_skin'],stdout=sp.PIPE)
        run(['gzip','-c'],stdin=dump.stdout,stdout=out)
        dump.stdout.close()
        assert dump.wait()==0
    run(['gzip','-t',*BACK.glob('*.gz')])
    verify='ysumc_release_verify_20261022'
    assert not sql(TEST_DB,"SHOW DATABASES LIKE '"+verify+"'").strip()
    sql(TEST_DB,'CREATE DATABASE '+verify)
    with gzip.open(BACK/'database.sql.gz','rt') as f: sql(TEST_DB,f.read(),verify)
    before=counts(PROD_DB,'ysumc_skin')
    assert before==counts(TEST_DB,verify)
    sql(TEST_DB,'DROP DATABASE '+verify)
    (BACK/'database-restore-check.json').write_text(json.dumps({'tables':len(before),'counts':before,'matched':True},indent=2))
    (BACK/'SHA256SUMS').write_text('\n'.join(digest(p)+'  '+p.name for p in sorted(BACK.glob('*.gz')))+'\n')
    protected=[OLD/'.env',SHARED/'janus/.env',SHARED/'janus/oauth-private.key',SHARED/'storage/oauth-private.key',SHARED/'storage/oauth-public.key']
    (BACK/'protected-files.json').write_text(json.dumps({str(p):digest(p) for p in protected}))
    populate(NEW,OLD,production=True)
    # Janus still lives in SHARED in the previous deployment.
    for rel in ['.env','oauth-private.key','prisma/schema.prisma']:
        shutil.copy2(SHARED/'janus'/rel,NEW/'janus'/rel)
        run(['chown','root:www',NEW/'janus'/rel])
    log('production-composer',[PHP,'-c',INI,COMPOSER,'install','--no-dev','--no-interaction','--prefer-dist','--optimize-autoloader','--no-scripts'],NEW)
    log('production-platform',[PHP,'-c',INI,COMPOSER,'check-platform-reqs','--no-dev'],NEW)
    log('production-janus-build',[NODE,'node_modules/typescript/bin/tsc','-p','tsconfig.build.json','--incremental','false'],NEW/'janus')
    # Bounded verifier scripts retain their checks but use this release's paths.
    previous='/www/server/php-compat-assessments/20261022-migration/production-cutover-20261022'
    for file in ['production-fixture.php','production-http-smoke.py']:
        content=(NEW/'deploy/php85'/file).read_text().replace(str(OLD),str(NEW)).replace(previous,str(BACK))
        (BACK/file).write_text(content)
    (BACK/'rollback.sh').write_text('#!/bin/sh\nset -eu\npython3 '+str(STATE/'release.py')+' rollback\n')
    (BACK/'rollback.sh').chmod(0o700)
    print('Backups restored and checked; release staged. No production service started.')

def rollback():
    shutil.copy2(BACK/'nginx.conf',VHOST)
    run([NGINX,'-t']); run([NGINX,'-s','reload'])
    run(['systemctl','disable','--now','ysumc-php85-fpm'])
    shutil.copy2(BACK/'php-fpm.conf','/opt/ysumc/php-8.5.12/etc/php-fpm.conf')
    shutil.copy2(BACK/'php.service','/etc/systemd/system/ysumc-php85-fpm.service')
    release_dropin=Path('/etc/systemd/system/ysumc-janus.service.d/30-release.conf')
    release_dropin.unlink(missing_ok=True)
    run(['systemctl','daemon-reload']);run(['systemctl','restart','ysumc-janus'])
    row=json.loads((BACK/'site.json').read_text())
    with sqlite3.connect('/www/server/panel/data/db/site.db') as db:
        db.execute('UPDATE sites SET path=?,status=?,stop=? WHERE id=? AND name=?',(row['path'],row['status'],row['stop'],row['id'],row['name']))
    print('Rolled back to stopped site and previous Janus. No database or upload data overwritten.')

def cutover():
    assert (BACK/'database-restore-check.json').exists() and (BACK/'rollback.sh').exists()
    assert VHOST.read_bytes()==(BACK/'nginx.conf').read_bytes()
    fpm=Path('/opt/ysumc/php-8.5.12/etc/php-fpm.conf')
    fpm.write_text((BACK/'php-fpm.conf').read_text().replace(str(OLD),str(NEW)))
    unit=Path('/etc/systemd/system/ysumc-php85-fpm.service')
    unit.write_text((BACK/'php.service').read_text().replace(str(OLD),str(NEW)))
    Path('/etc/systemd/system/ysumc-janus.service.d/30-release.conf').write_text('[Service]\nWorkingDirectory='+str(NEW/'janus')+'\n')
    for command in ['package:discover','config:cache','view:cache']:
        log('production-'+command.replace(':','-'),['runuser','-u','www','--',PHP,'-c',INI,'artisan',command],NEW)
    # Explicitly migrate the plugin before any concurrent FPM request.
    script="require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); SITMinecraft\\Launcher\\Database\\LauncherSchema::install(); echo 'Schema ready'.PHP_EOL;"
    log('production-schema',['runuser','-u','www','--',PHP,'-c',INI,'-r',script],NEW)
    original=(BACK/'nginx.conf').read_text()
    marker='    # User-requested maintenance. Reopen only after explicit instruction.'
    start=original.index(marker);end=original.index('    index index.php;',start)
    public=(original[:start]+original[end:]).replace('root /www/server/stop;', 'root '+str(NEW)+'/public;',1)
    public=public.replace('    location ^~ /.well-known/acme-challenge/', '    location = /.well-known/mc-interop-v1.json {\n        rewrite ^ /index.php last;\n    }\n\n    location ^~ /.well-known/acme-challenge/')
    gate='    if ($server_addr != 127.0.0.1) {\n        rewrite ^/(?!bt-stop\\.html$).* /bt-stop.html last;\n    }\n    location = /bt-stop.html { root /www/server/stop; internal; }\n'
    private=public.replace('    index index.php;',gate+'    index index.php;')
    (BACK/'nginx-public.conf').write_text(public)
    (BACK/'nginx-private.conf').write_text(private)
    try:
        VHOST.write_text(public); run([NGINX,'-t'])
        VHOST.write_text(private); run([NGINX,'-t'])
        run(['systemctl','daemon-reload'])
        run(['systemctl','restart','ysumc-janus'])
        run(['systemctl','start','ysumc-php85-fpm'])
        run([NGINX,'-s','reload'])
    except BaseException:
        rollback(); raise
    print('Private cutover ready. Public traffic still receives the stopped page.')

def open_site():
    assert (BACK/'fixture-cleanup.json').exists() and (BACK/'interop-http-passed.json').exists()
    assert json.loads((BACK/'http-smoke-result.json').read_text())['passed'] >= 98
    assert VHOST.read_bytes()==(BACK/'nginx-private.conf').read_bytes()
    protected=json.loads((BACK/'protected-files.json').read_text())
    assert all(digest(p)==v for p,v in protected.items())
    assert (NEW/'.env').read_bytes()==(OLD/'.env').read_bytes()
    VHOST.write_bytes((BACK/'nginx-public.conf').read_bytes())
    try: run([NGINX,'-t']);run([NGINX,'-s','reload'])
    except BaseException:
        VHOST.write_bytes((BACK/'nginx-private.conf').read_bytes());run([NGINX,'-s','reload']);raise
    with sqlite3.connect('/www/server/panel/data/db/site.db') as db:
        changed=db.execute("UPDATE sites SET path=?,status='1',stop='0' WHERE name=?",(str(NEW),'skin.ysumc.net'))
        assert changed.rowcount==1
    run(['systemctl','enable','ysumc-php85-fpm'])
    print('Production access restored after checks and fixture cleanup.')

if __name__=='__main__':
    assert os.geteuid()==0
    os.umask(0o077)
    assert STATE.is_dir()
    {'candidate':candidate,'stage':stage,'cutover':cutover,'rollback':rollback,'open':open_site}[sys.argv[1]]()
