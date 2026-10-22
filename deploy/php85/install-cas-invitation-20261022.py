from pathlib import Path
from datetime import datetime, timezone
import hashlib, json, os, pwd, shutil, subprocess, sys

ROOT = Path('/www/wwwroot/ysumcusercentre')
STATE = Path('/www/server/php-compat-assessments/20261022-cas-invitation-install')
NAMES = ['auth-cas', 'invitation-codes']
PHP = '/opt/ysumc/php-8.5.12/bin/php'
INI = '/opt/ysumc/php-8.5.12/etc/php.ini'
os.umask(0o077)

def run(args, **kwargs):
    return subprocess.run(list(map(str, args)), check=True, capture_output=True, **kwargs)

def inspect():
    source = '''<?php
chdir('/www/wwwroot/ysumcusercentre');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$manager = app(App\\Services\\PluginManager::class);
$plugins = [];
foreach (['auth-cas', 'invitation-codes'] as $name) {
    $p = $manager->get($name);
    $plugins[$name] = $p ? ['version'=>$p->version, 'enabled'=>$p->isEnabled(), 'unsatisfied'=>$manager->getUnsatisfied($p)->all(), 'conflicts'=>$manager->getConflicts($p)->all()] : null;
}
echo json_encode(['plugins'=>$plugins, 'enabled_sha256'=>hash('sha256', (string) option('plugins_enabled')), 'invitation_table_exists'=>Illuminate\\Support\\Facades\\Schema::hasTable('invitation_codes'), 'invitation_policy'=>option('invitation_codes_for_union_enabled'), 'config_cached'=>$app->configurationIsCached()]);
'''
    return json.loads(run(['runuser', '-u', 'www', '--', PHP, '-c', INI], input=source.encode()).stdout)

def cache():
    run(['runuser', '-u', 'www', '--', PHP, '-c', INI, ROOT/'artisan', 'config:cache'], cwd=ROOT)

def entry_statuses():
    return {path: int(run(['curl', '-sS', '--max-time', '20', '-o', '/dev/null', '-w', '%{http_code}', 'https://skin.ysumc.net'+path]).stdout.decode()) for path in ['/auth/login', '/auth/register', '/api/janus/.well-known/openid-configuration']}

def rollback():
    current = inspect()
    assert all(not p or not p['enabled'] for p in current['plugins'].values()), 'Disable installed plugins before rollback'
    state = json.loads((STATE/'install-state.json').read_text())
    target = STATE/('removed-'+str(int(datetime.now(timezone.utc).timestamp())))
    target.mkdir(mode=0o700)
    for name in state['installed']:
        path = ROOT/'plugins'/name
        assert path.resolve() == path and path.parent == ROOT/'plugins'
        path.rename(target/name)
    if state['config_installed']:
        (ROOT/'config/auth-cas.php').rename(target/'auth-cas.php')
    cache()
    state['rolled_back'] = True
    (STATE/'install-state.json').write_text(json.dumps(state, indent=2))
    print('Installed plugins removed; original enablement unchanged')

if '--rollback' in sys.argv:
    rollback()
    sys.exit(0)

assert ROOT.resolve() == ROOT and ROOT.is_dir()
assert not (STATE/'install-state.json').exists()
assert not (ROOT/'config/auth-cas.php').exists()
assert not (ROOT/'plugins/bs-plugin-auth-cas').exists()
before = inspect()
before_entries = entry_statuses()
assert before_entries['/auth/login'] == before_entries['/api/janus/.well-known/openid-configuration'] == 200
assert all(p is None for p in before['plugins'].values())
assert not json.loads((STATE/'syntax-check.json').read_text())['issues']
manifest = json.loads((STATE/'files-manifest.json').read_text())
for name, expected in manifest.items():
    assert hashlib.sha256((STATE/'stage'/name).read_bytes()).hexdigest() == expected
(STATE/'before.json').write_text(json.dumps(before, indent=2))
cached = ROOT/'bootstrap/cache/config.php'
if cached.is_file():
    shutil.copy2(cached, STATE/'config-cache.before.php')
    os.chmod(STATE/'config-cache.before.php', 0o600)
env_hash = hashlib.sha256((ROOT/'.env').read_bytes()).hexdigest()
state = {'installed': [], 'config_installed': False, 'started_utc': datetime.now(timezone.utc).isoformat()}
def save():
    (STATE/'install-state.json').write_text(json.dumps(state, indent=2))
save()
(STATE/'rollback.sh').write_text('#!/bin/sh\nset -eu\nexec python3 '+str(STATE/'install.py')+' --rollback\n')
os.chmod(STATE/'rollback.sh', 0o700)
www = pwd.getpwnam('www')
try:
    for name in NAMES:
        source = STATE/'stage/plugins'/name
        destination = ROOT/'plugins'/name
        assert not destination.exists() and source.stat().st_dev == destination.parent.stat().st_dev
        for directory, dirs, files in os.walk(source):
            os.chown(directory, www.pw_uid, www.pw_gid); os.chmod(directory, 0o755)
            for file in files:
                path = Path(directory)/file
                os.chown(path, www.pw_uid, www.pw_gid); os.chmod(path, 0o644)
        source.rename(destination)
        state['installed'].append(name); save()
    config = ROOT/'config/auth-cas.php'
    shutil.copyfile(STATE/'stage/config/auth-cas.php', config)
    os.chown(config, www.pw_uid, www.pw_gid); os.chmod(config, 0o644)
    state['config_installed'] = True; save()
    cache()
    after = inspect()
    for name in NAMES:
        assert after['plugins'][name] and not after['plugins'][name]['enabled']
        assert not after['plugins'][name]['unsatisfied'] and not after['plugins'][name]['conflicts']
    for key in ['enabled_sha256', 'invitation_table_exists', 'invitation_policy']:
        assert before[key] == after[key], 'Registration or enablement state changed'
    assert hashlib.sha256((ROOT/'.env').read_bytes()).hexdigest() == env_hash
    for name, expected in manifest.items():
        assert hashlib.sha256((ROOT/name).read_bytes()).hexdigest() == expected
    entries = entry_statuses()
    assert entries == before_entries, 'Entry behavior changed from before installation'
    report = {'completed_utc': datetime.now(timezone.utc).isoformat(), 'plugins': after['plugins'], 'entries': entries, 'enabled_plugins_unchanged': True, 'registration_policy_unchanged': True, 'invitation_table_unchanged': True, 'environment_unchanged': True, 'files_match_manifest': True, 'regression_tests_run': False}
    (STATE/'installed-status.json').write_text(json.dumps(report, indent=2))
    state['complete'] = True; save()
    print(json.dumps(report, indent=2))
except Exception:
    rollback()
    raise
