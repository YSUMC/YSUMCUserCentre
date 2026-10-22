from pathlib import Path
from datetime import datetime, timezone
import hashlib, json, os, shutil, sqlite3, subprocess, tarfile

BASE = Path('/www/wwwroot')
FINAL = BASE / 'ysumcusercentre'
STATE = Path('/www/server/php-compat-assessments/20261022-consolidation')
TARGETS = [BASE / name for name in [
    'ysumcusercentre-next', 'ysumcusercentre-php85-20261022',
    'ysumcusercentre-review-20261022',
]] + [STATE / 'retired-original'] + [FINAL / 'janus' / name for name in [
    'node_modules.pre-audit', 'dist.pre-audit', 'dist.email-policy-previous-1c50557',
]]
os.umask(0o077)

def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()

state = json.loads((STATE / 'cutover-state.json').read_text())
assert state['cutover_complete'] and state['runtime_moved'] and not state.get('rolled_back')
assert FINAL.resolve() == FINAL and not (FINAL / 'storage').is_symlink()
assert (FINAL / 'storage').stat().st_ino == state['storage_inode']
assert (FINAL / '.runtime/mysql').stat().st_ino == state['database_inode']
for name, expected in state['protected_sha256'].items():
    assert digest(FINAL / name) == expected, 'Protected file changed'
for name, expected in json.loads((STATE / 'backup-manifest.json').read_text())['sha256'].items():
    assert digest(STATE / name) == expected, 'Backup checksum failed: ' + name
print('Backup checksums and protected data confirmed', flush=True)
with tarfile.open(STATE / 'plugin-compat-eduroam-update.tar') as archive:
    for item in archive.getmembers():
        if item.isfile():
            assert (FINAL / item.name).read_bytes() == archive.extractfile(item).read()
for name in ['ysumc-php85-fpm.service', 'ysumc-janus.service']:
    subprocess.run(['systemctl', 'is-active', '--quiet', name], check=True)
for path in ['/auth/login', '/auth/eduroam/login', '/api/janus/.well-known/openid-configuration']:
    status = subprocess.check_output(['curl', '-sS', '--max-time', '20', '-o', '/dev/null', '-w', '%{http_code}', 'https://skin.ysumc.net' + path], text=True)
    assert status == '200', 'Entry unavailable: ' + path
with sqlite3.connect('/www/server/panel/data/db/site.db') as db:
    assert db.execute('SELECT path FROM sites WHERE id=51 AND name=?', ('skin.ysumc.net',)).fetchone() == (str(FINAL),)
for target in TARGETS:
    assert target.exists() and target.is_dir() and not target.is_symlink()
    assert target.resolve() == target and target != FINAL
    assert target.parent in [BASE, STATE, FINAL / 'janus']
assert not (STATE / 'retired-original/storage').exists()
assert not (STATE / 'retired-original/.runtime').exists()

def targets_path(value):
    return any(value == str(p) or value.startswith(str(p) + '/') for p in TARGETS)

for proc in Path('/proc').iterdir():
    if not proc.name.isdigit():
        continue
    links = [proc / 'cwd', proc / 'root', proc / 'exe']
    try:
        links.extend((proc / 'fd').iterdir())
    except OSError:
        pass
    for link in links:
        try:
            value = os.readlink(link)
        except OSError:
            continue
        assert not targets_path(value), 'Active process uses old directory: ' + str(link)
    try:
        mountinfo = (proc / 'mountinfo').read_text()
    except OSError:
        continue
    assert not any(str(p) + '/' in mountinfo or str(p) + ' ' in mountinfo for p in TARGETS), 'Mounted old directory: ' + proc.name
ids = subprocess.check_output(['docker', 'ps', '-aq'], text=True).split()
for container in ids:
    item = json.loads(subprocess.check_output(['docker', 'inspect', container]))[0]
    for mount in item.get('Mounts', []):
        assert not targets_path(mount.get('Source', '')), 'Container references old directory'
db = json.loads(subprocess.check_output(['docker', 'inspect', 'ysumc-usercentre-database-1']))[0]
mount = Path('/proc') / str(db['State']['Pid']) / 'root/var/lib/mysql'
assert mount.stat().st_ino == (FINAL / '.runtime/mysql').stat().st_ino

print('No running processes or mounts use the deletion targets', flush=True)
removed = []
for target in TARGETS:
    # BaoTa may mark only these per-site INI files immutable.
    for ini in [target / '.user.ini', target / 'public/.user.ini']:
        if ini.is_file() and not ini.is_symlink():
            flags = subprocess.check_output(['lsattr', '-d', str(ini)], text=True).split()[0]
            if 'i' in flags:
                subprocess.run(['chattr', '-i', str(ini)], check=True)
    shutil.rmtree(target)
    removed.append(str(target))
    print('Removed ' + str(target), flush=True)
remaining = sorted(str(p) for p in BASE.glob('ysumcusercentre*'))
assert remaining == [str(FINAL)]
assert (FINAL / 'storage').stat().st_ino == state['storage_inode']
assert (FINAL / '.runtime/mysql').stat().st_ino == state['database_inode']
report = {'completed_utc': datetime.now(timezone.utc).isoformat(), 'removed': removed, 'remaining': remaining, 'storage_preserved': True, 'database_preserved': True, 'backup_checksums_verified': True}
(STATE / 'cleanup.json').write_text(json.dumps(report, indent=2))
print(json.dumps(report), flush=True)
