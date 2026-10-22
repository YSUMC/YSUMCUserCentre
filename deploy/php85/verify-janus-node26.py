#!/usr/bin/env python3
"""Verify the approved production Node change without opening the site or issuing tokens."""
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import subprocess
import urllib.error
import urllib.parse
import urllib.request

STATE = Path('/www/server/php-compat-assessments/20261022-migration')
REPAIR = STATE / 'janus-node26-repair-20261022'
NODE = '/www/server/nodejs/v26.12.1/bin/node'
DROPIN = Path('/etc/systemd/system/ysumc-janus.service.d/20-node-runtime.conf')
ISSUER = 'https://skin.ysumc.net/api/janus'


def get(*args):
    return subprocess.check_output(args, text=True).strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())


def local_request(path, data=None):
    assert path.startswith('/api/janus/')
    headers = {'Host': 'skin.ysumc.net', 'X-Forwarded-Proto': 'https', 'Accept': 'application/json'}
    body = None if data is None else urllib.parse.urlencode(data).encode()
    if body is not None:
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    request = urllib.request.Request('http://127.0.0.1:13010' + path, data=body, headers=headers)
    try:
        response = opener.open(request, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


before = json.loads((REPAIR / 'before.json').read_text())
old_baseline = json.loads((STATE / 'production-baseline.json').read_text())
protected = old_baseline | before['protected_files']
changed = [name for name, digest in protected.items()
           if not Path(name).is_file() or hashlib.sha256(Path(name).read_bytes()).hexdigest() != digest]
assert not changed, changed
assert DROPIN.read_bytes() == (STATE / 'janus-node26.conf').read_bytes()
assert hashlib.sha256(Path(NODE).read_bytes()).hexdigest() == before['node_sha256']
assert get('runuser', '-u', 'www', '--', NODE, '--version') == 'v26.12.1'
assert get('systemctl', 'show', 'ysumc-janus.service', '-p', 'ActiveState', '--value') == 'active'
assert get('systemctl', 'show', 'ysumc-janus.service', '-p', 'SubState', '--value') == 'running'
pid = int(get('systemctl', 'show', 'ysumc-janus.service', '-p', 'MainPID', '--value'))
assert pid > 0 and os.readlink(f'/proc/{pid}/exe') == NODE
listeners = get('ss', '-H', '-ltnp', 'sport = :13010').splitlines()
assert len(listeners) == 1 and '127.0.0.1:13010' in listeners[0] and f'pid={pid},' in listeners[0]
invocation = get('systemctl', 'show', 'ysumc-janus.service', '-p', 'InvocationID', '--value')
restarts = int(get('systemctl', 'show', 'ysumc-janus.service', '-p', 'NRestarts', '--value'))

status, _, body = local_request('/api/janus/.well-known/openid-configuration')
assert status == 200
metadata = json.loads(body)
assert metadata['issuer'] == ISSUER
assert 'urn:ietf:params:oauth:grant-type:device_code' in metadata['grant_types_supported']
status, _, body = local_request(urllib.parse.urlparse(metadata['jwks_uri']).path)
assert status == 200
keys = json.loads(body)['keys']
assert keys and all(key['kty'] == 'RSA' and 'n' in key and 'e' in key for key in keys)
assert all(not (set(key) & {'d', 'p', 'q', 'dp', 'dq', 'qi'}) for key in keys)
status, _, body = local_request(urllib.parse.urlparse(metadata['token_endpoint']).path, {
    'grant_type': 'authorization_code', 'client_id': '0',
    'client_secret': 'invalid-node-path-check', 'code': 'invalid-node-path-check',
    'redirect_uri': 'https://example.invalid/',
})
assert status == 401 and json.loads(body)['error'] == 'invalid_client'
# Client lookup above exercises the existing Prisma adapter/database, without creating a token.
status, headers, _ = local_request('/api/janus/userinfo')
assert status == 303 and headers['Location'] == 'https://skin.ysumc.net/yggc/userinfo'
public = urllib.request.urlopen('https://skin.ysumc.net/', timeout=20).read()
assert public == Path('/www/server/stop/bt-stop.html').read_bytes()
assert get('systemctl', 'show', 'php-fpm-81.service', '-p', 'ActiveState', '--value') == 'inactive'

report = {
    'observed_at': datetime.now(timezone.utc).isoformat(),
    'service': 'ysumc-janus.service', 'state': 'active/running', 'node_version': '26.12.1',
    'executable': NODE, 'pid': pid, 'invocation': invocation, 'restarts_total': restarts,
    'loopback_endpoint': '127.0.0.1:13010',
    'loopback_only': True,
    'checks': {'discovery': True, 'public_jwks': True, 'database_client_lookup': True,
               'invalid_client_rejected': True, 'userinfo_redirect': True},
    'protected_files_unchanged': len(protected), 'dropin_sha256': hashlib.sha256(DROPIN.read_bytes()).hexdigest(),
    'production_site_stopped': True, 'production_php81': 'inactive', 'php_switched': False,
    'limitations': ['Full interactive login remains unavailable while the PHP site is stopped.'],
}
first_path = REPAIR / 'first-healthy.json'
if first_path.exists():
    first = json.loads(first_path.read_text())
    report['stable_since'] = first['observed_at']
    report['pid_unchanged_since_first_check'] = pid == first['pid']
    report['no_new_restarts'] = restarts == first['restarts_total'] and invocation == first['invocation']
    assert report['pid_unchanged_since_first_check'] and report['no_new_restarts']
else:
    first_path.write_text(json.dumps(report, indent=2) + '\n')
(REPAIR / 'after.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report, indent=2))
