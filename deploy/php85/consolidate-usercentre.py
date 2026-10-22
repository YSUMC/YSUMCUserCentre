from pathlib import Path
from datetime import datetime,timezone
import hashlib,json,os,pwd,re,shutil,socket,sqlite3,subprocess,sys,tarfile,time

BASE=Path('/www/wwwroot')
FINAL=BASE/'ysumcusercentre'
LIVE=BASE/'ysumcusercentre-release-20261022'
STATE=Path('/www/server/php-compat-assessments/20261022-consolidation')
RETIRED=STATE/'retired-original'
PHP='/opt/ysumc/php-8.5.12/bin/php'
INI='/opt/ysumc/php-8.5.12/etc/php.ini'
NGINX='/www/server/nginx/sbin/nginx'
VHOST=Path('/www/server/panel/vhost/nginx/skin.ysumc.net.conf')
FPM='ysumc-php85-fpm.service'
JANUS='ysumc-janus.service'
PREVIEWS=['ysumc-php85-candidate-fpm.service','ysumc-php85-candidate-janus.service','ysumc-php85-candidate-nginx.service','ysumc-php85-test-janus.service']
OTHERS=[BASE/'ysumcusercentre-next',BASE/'ysumcusercentre-php85-20261022',BASE/'ysumcusercentre-review-20261022']
os.umask(0o077)


def run(args,**kwargs):
    return subprocess.run(list(map(str,args)),check=True,capture_output=True,**kwargs)


def php(root,*args):
    result=run(['runuser','-u','www','--',PHP,'-c',INI,root/'artisan',*args],cwd=root)
    return result.stdout.decode(errors='replace')


def read_update():
    files={}
    with tarfile.open(STATE/'plugin-compat-eduroam-update.tar') as archive:
        for item in archive.getmembers():
            if not item.isfile():continue
            path=Path(item.name)
            allowed=str(path)=='config/auth-eduroam.php' or any(str(path).startswith('plugins/'+p+'/') for p in ['auth-eduroam','single-player-limit','restricted-email-domains'])
            if not allowed or path.is_absolute() or '..' in path.parts:raise RuntimeError('Unsafe update path')
            files[str(path)]=archive.extractfile(item).read()
    return files


def save_state(state):
    (STATE/'cutover-state.json').write_text(json.dumps(state,indent=2))


def stop_services():
    run(['systemctl','stop',FPM,JANUS])


def start_services():
    run(['systemctl','daemon-reload'])
    run(['systemctl','start',FPM,JANUS])
    for _ in range(20):
        ok=all(subprocess.run(['systemctl','is-active','--quiet',s]).returncode==0 for s in [FPM,JANUS])
        try:
            with socket.create_connection(('127.0.0.1',13010),timeout=1):
                if ok and Path('/run/ysumc-php85/php-fpm.sock').exists():return
        except OSError:pass
        time.sleep(1)
    raise RuntimeError('Services did not become ready')


def restore_before():
    state=json.loads((STATE/'cutover-state.json').read_text())
    if state.get('original_moved') and not RETIRED.exists():
        restore=STATE/('restore-original-'+str(time.time_ns()))
        restore.mkdir(mode=0o700)
        # Restore only old program files; keep the current live uploads and sessions.
        run(['tar','-xzf',STATE/'versions-before.tar.gz','-C',restore,'--exclude=ysumcusercentre/storage','--exclude=ysumcusercentre/.runtime','ysumcusercentre'])
        (restore/'ysumcusercentre').rename(RETIRED)
        restore.rmdir()
    stop_services()
    if state.get('live_moved'):
        if LIVE.exists():raise RuntimeError('Cannot overwrite existing release during rollback')
        FINAL.rename(LIVE)
    if state.get('storage_moved'):
        (LIVE/'storage').rename(RETIRED/'storage')
    if state.get('runtime_moved'):
        assert not (RETIRED/'.runtime').exists()
        (LIVE/'.runtime').rename(RETIRED/'.runtime')
    if state.get('storage_unlinked'):
        (LIVE/'storage').symlink_to(FINAL/'storage',target_is_directory=True)
    if state.get('original_moved'):
        RETIRED.rename(FINAL)
    original=json.loads((STATE/'updated-files-before.json').read_text())
    for name,metadata in original.items():
        target=LIVE/name
        if metadata is None:
            if target.is_file():target.unlink()
        else:
            target.write_bytes((STATE/'files-before'/name).read_bytes())
            os.chown(target,metadata['uid'],metadata['gid']);os.chmod(target,metadata['mode'])
    ini_backup=STATE/'public-user.ini.before-path-fix'
    if ini_backup.exists():
        target=LIVE/'public/.user.ini'
        metadata=json.loads((STATE/'public-user.ini.metadata.json').read_text())
        target.write_bytes(ini_backup.read_bytes())
        os.chown(target,metadata['uid'],metadata['gid']);os.chmod(target,metadata['mode'])
    for path,text in json.loads((STATE/'configuration-before.json').read_text()).items():
        Path(path).write_text(text)
    with sqlite3.connect('/www/server/panel/data/db/site.db') as db:
        db.execute('UPDATE sites SET path=? WHERE id=51 AND name=?',(str(LIVE),'skin.ysumc.net'))
    cached=LIVE/'bootstrap/cache/config.php'
    if cached.exists():cached.unlink()
    php(LIVE,'config:cache')
    start_services()
    run([NGINX,'-t']);run([NGINX,'-s','reload'])
    state['rolled_back']=True;save_state(state)
    print(json.dumps({'rolled_back':True,'active_root':str(LIVE)}),flush=True)


if '--rollback' in sys.argv:
    restore_before();sys.exit(0)

assert (STATE/'backup-manifest.json').is_file()
assert not (STATE/'cutover-state.json').exists()
assert FINAL.is_dir() and LIVE.is_dir() and not FINAL.is_symlink() and not LIVE.is_symlink()
assert FINAL.resolve().parent==BASE and LIVE.resolve().parent==BASE and not RETIRED.exists()
assert (LIVE/'storage').is_symlink() and (LIVE/'storage').resolve()==FINAL/'storage'
assert FINAL.stat().st_dev==STATE.stat().st_dev==LIVE.stat().st_dev
update=read_update()
stage=STATE/'source-update'
for name,body in update.items():
    target=stage/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(body)
    if target.suffix=='.php':run([PHP,'-c',INI,'-l',target])
print('Deployment source syntax prepared; no regression tests run',flush=True)

original={}
for name in update:
    path=LIVE/name
    if path.exists():
        assert path.is_file() and not path.is_symlink()
        saved=STATE/'files-before'/name;saved.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(path,saved)
        st=path.stat();original[name]={'uid':st.st_uid,'gid':st.st_gid,'mode':st.st_mode&0o777}
    else:original[name]=None
(STATE/'updated-files-before.json').write_text(json.dumps(original))
configs=json.loads((STATE/'configuration-before.json').read_text())
for path,text in configs.items():
    if Path(path).read_text()!=text:raise RuntimeError('Configuration changed after backup: '+path)
critical=[p for p in [LIVE/'.env',LIVE/'janus/.env',FINAL/'storage/oauth-private.key',FINAL/'storage/oauth-public.key'] if p.is_file()]
protected={str(p.relative_to(LIVE)) if p.is_relative_to(LIVE) else str(p.relative_to(FINAL)):hashlib.sha256(p.read_bytes()).hexdigest() for p in critical}
state={'commit':'d59449a','started_utc':datetime.now(timezone.utc).isoformat(),'protected_sha256':protected,'storage_inode':(FINAL/'storage').stat().st_ino}
save_state(state)
(STATE/'rollback.sh').write_text('#!/bin/sh\nset -eu\nexec python3 '+str(STATE/'consolidate-usercentre.py')+' --rollback\n')
os.chmod(STATE/'rollback.sh',0o700)

try:
    maintenance=re.sub(r'(?m)^(\s*server\s*\{)',r'\1\n    return 503;',configs[str(VHOST)])
    if maintenance==configs[str(VHOST)]:raise RuntimeError('Cannot apply maintenance response')
    VHOST.write_text(maintenance);run([NGINX,'-t']);run([NGINX,'-s','reload'])
    for name in PREVIEWS:
        subprocess.run(['systemctl','stop',name],capture_output=True)
        subprocess.run(['systemctl','reset-failed',name],capture_output=True)
    stop_services()
    www=pwd.getpwnam('www')
    for name,body in update.items():
        target=LIVE/name;target.parent.mkdir(parents=True,exist_ok=True)
        target.write_bytes(body);os.chown(target,www.pw_uid,www.pw_gid);os.chmod(target,0o644)
    for name in ['auth-eduroam','single-player-limit','restricted-email-domains']:
        for directory,dirs,files in os.walk(LIVE/'plugins'/name):
            os.chown(directory,www.pw_uid,www.pw_gid);os.chmod(directory,0o775)
    FINAL.rename(RETIRED);state['original_moved']=True;save_state(state)
    (LIVE/'storage').unlink();state['storage_unlinked']=True;save_state(state)
    (RETIRED/'storage').rename(LIVE/'storage');state['storage_moved']=True;save_state(state)
    if (RETIRED/'.runtime').exists():
        assert not (LIVE/'.runtime').exists()
        (RETIRED/'.runtime').rename(LIVE/'.runtime')
        state['runtime_moved']=True;save_state(state)
    LIVE.rename(FINAL);state['live_moved']=True;save_state(state)
    assert not (FINAL/'storage').is_symlink()
    assert (FINAL/'storage').stat().st_ino==state['storage_inode']
    for name,expected in protected.items():
        assert hashlib.sha256((FINAL/name).read_bytes()).hexdigest()==expected
    user_ini=FINAL/'public/.user.ini'
    if user_ini.exists():
        st=user_ini.stat();shutil.copy2(user_ini,STATE/'public-user.ini.before-path-fix')
        (STATE/'public-user.ini.metadata.json').write_text(json.dumps({'uid':st.st_uid,'gid':st.st_gid,'mode':st.st_mode&0o777}))
        user_ini.write_text(user_ini.read_text().replace(str(LIVE)+'/',str(FINAL)+'/'))

    for path,text in configs.items():
        changed=text.replace(str(LIVE),str(FINAL))
        if path.endswith('ysumc-usercentre-local.conf'):
            changed=changed.replace('unix:/tmp/php-cgi-81.sock','unix:/run/ysumc-php85/php-fpm.sock')
        if path==str(VHOST):
            changed=re.sub(r'(?m)^(\s*server\s*\{)',r'\1\n    return 503;',changed)
        Path(path).write_text(changed)
    with sqlite3.connect('/www/server/panel/data/db/site.db') as db:
        changed=db.execute('UPDATE sites SET path=? WHERE id=51 AND name=?',(str(FINAL),'skin.ysumc.net'))
        assert changed.rowcount==1
    (FINAL/'bootstrap/cache/config.php').unlink(missing_ok=True)
    php(FINAL,'config:cache')
    php(FINAL,'view:clear')
    start_services()
    run([NGINX,'-t'])
    VHOST.write_text(configs[str(VHOST)].replace(str(LIVE),str(FINAL)))
    run([NGINX,'-t']);run([NGINX,'-s','reload'])
    state['cutover_complete']=True;state['active_root']=str(FINAL)
    state['completed_utc']=datetime.now(timezone.utc).isoformat();save_state(state)
    print(json.dumps({'cutover_complete':True,'active_root':str(FINAL),'storage_preserved':True,'secrets_unchanged':True,'old_directories_pending_cleanup':[str(p) for p in OTHERS]+[str(RETIRED)]}),flush=True)
except Exception as error:
    print(json.dumps({'cutover_error':type(error).__name__}),flush=True)
    restore_before()
    raise
