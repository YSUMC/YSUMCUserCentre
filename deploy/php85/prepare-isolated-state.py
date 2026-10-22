#!/usr/bin/env python3
"""After init-isolated.py and before booting the clone: remove shared identities."""
from pathlib import Path
import subprocess

APP = Path('/www/wwwroot/ysumcusercentre-next')
STATE = Path('/www/server/php-compat-assessments/20261022-migration')
assert APP.is_dir() and (STATE/'initialization.json').is_file()
assert not (STATE/'isolated-state-ready').exists(), 'Already initialized; do not rotate keys accidentally.'
env = (APP/'.env').read_text()
assert 'ysumc_php85_test' in env and '13385' in env
for prefix in ('oauth', 'yggdrasil-test'):
    private = APP/'storage'/f'{prefix}-private.key'
    public = APP/'storage'/f'{prefix}-public.key'
    assert not private.exists() and not public.exists(), 'Key already exists.'
    subprocess.run(['openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:4096', '-out', str(private)], check=True, stderr=subprocess.DEVNULL)
    subprocess.run(['openssl', 'pkey', '-in', str(private), '-pubout', '-out', str(public)], check=True, stderr=subprocess.DEVNULL)
    for key in (private, public):
        key.chmod(0o600)
        subprocess.run(['chown', 'www:www', str(key)], check=True)
options = {
    'site_url': 'http://127.0.0.1:13850', 'force_ssl': 'false',
    'ygg_connect_server_url': 'http://127.0.0.1:13850/api/janus',
    'union_api_root': 'http://127.0.0.1:13852', 'union_member_key': '',
    'union_oauth2_sig_private_key': '', 'union_oauth2_sig_public_key': '',
    'ygg_private_key': (APP/'storage/yggdrasil-test-private.key').read_text(),
}
def literal(value):
    return "CONVERT(X'"+value.encode().hex()+"' USING utf8mb4)"
sql = ''.join(f'UPDATE options SET option_value={literal(value)} WHERE option_name={literal(name)};\n' for name,value in options.items())
sql += ''.join(f'UPDATE {table} SET revoked=1;\n' for table in ('oauth_clients','oauth_access_tokens','oauth_refresh_tokens','oauth_auth_codes'))
subprocess.run(['docker','exec','-i','ysumc-php85-test-db','sh','-c',
                'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot ysumc_php85_test'], input=sql.encode(), check=True)
(APP/'storage/options.php').unlink(missing_ok=True)
for path in ('storage', 'bootstrap/cache', 'plugins'):
    subprocess.run(['chown','-R','www:www',str(APP/path)],check=True)
(STATE/'isolated-state-ready').touch(mode=0o600)
print('Clone keys, endpoints and OAuth credentials isolated. No production mutations.')
