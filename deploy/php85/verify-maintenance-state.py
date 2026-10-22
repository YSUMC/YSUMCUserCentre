#!/usr/bin/env python3
"""Read-only production guard and candidate shutdown evidence for this migration."""
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path
import subprocess
import urllib.request

STATE = Path('/www/server/php-compat-assessments/20261022-migration')


def get(*args):
    return subprocess.check_output(args, text=True).strip()


baseline = json.loads((STATE / 'production-baseline.json').read_text())
changed = [name for name, digest in baseline.items()
           if not Path(name).is_file() or hashlib.sha256(Path(name).read_bytes()).hexdigest() != digest]
snapshot = json.loads((STATE / 'janus-rollback-20261022/manifest.json').read_text())
pid = int(get('systemctl', 'show', 'ysumc-janus.service', '-p', 'MainPID', '--value'))
unit_bytes = (get('systemctl', 'cat', 'ysumc-janus.service') + '\n').encode()
public = urllib.request.urlopen('https://skin.ysumc.net/', timeout=20).read()
units = ['ysumc-php85-candidate-' + name for name in ['fpm', 'nginx', 'janus', 'rollback-probe']]
active = {unit: get('systemctl', 'show', unit, '-p', 'ActiveState', '--value') for unit in units}
report = {
    'observed_at': datetime.now(timezone.utc).isoformat(),
    'production_files_checked': len(baseline), 'changed': changed,
    'production_php81': get('systemctl', 'show', 'php-fpm-81.service', '-p', 'ActiveState', '--value'),
    'public_stop_page_exact_match': public == Path('/www/server/stop/bt-stop.html').read_bytes(),
    'production_janus_pid': pid, 'production_janus_pid_unchanged': pid == snapshot['pid'],
    'production_janus_unit_unchanged': hashlib.sha256(unit_bytes).hexdigest() == snapshot['unit_sha256'],
    'production_janus_state': get('systemctl', 'show', 'ysumc-janus.service', '-p', 'ActiveState', '--value'),
    'production_janus_substate': get('systemctl', 'show', 'ysumc-janus.service', '-p', 'SubState', '--value'),
    'production_janus_exec_status': get('systemctl', 'show', 'ysumc-janus.service', '-p', 'ExecMainStatus', '--value'),
    'candidate_units': active,
    'candidate_database_running': get('docker', 'inspect', 'ysumc-php85-test-db', '--format', '{{.State.Running}}') == 'true',
}
(STATE / 'maintenance-production-verification.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report, indent=2))
assert not changed and report['production_php81'] == 'inactive'
assert report['public_stop_page_exact_match'] and report['production_janus_pid_unchanged'] and report['production_janus_unit_unchanged']
assert all(state == 'inactive' for state in active.values()) and not report['candidate_database_running']
