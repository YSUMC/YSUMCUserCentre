#!/usr/bin/env python3
"""Open the tested production release; automatically stop it on verification failure."""
import datetime
import hashlib
import json
from pathlib import Path
import shutil
import sqlite3
import subprocess as sp
import time
import requests

BACK = Path('/www/server/php-compat-assessments/20261022-migration/production-cutover-20261022')
VHOST = Path('/www/server/panel/vhost/nginx/skin.ysumc.net.conf')
ROOT = '/www/wwwroot/ysumcusercentre-php85-20261022'
NGINX = '/www/server/nginx/sbin/nginx'
http_checks = json.loads((BACK/'http-smoke-result.json').read_text())
assert http_checks['passed'] >= 98
assert 'Browser bootstrap configuration is usable' in http_checks['checks']
assert 'Browser translations are injected by the footer composer' in http_checks['checks']
assert 'Plugin view composer renders the campus login entry' in http_checks['checks']
assert all(json.loads((BACK/'fixture-cleanup.json').read_text()).values())
assert json.loads((BACK/'rollback-rehearsal.json').read_text())['passed']
runtime = json.loads((BACK/'runtime-check.json').read_text())
assert runtime['version'] == '8.5.12' and runtime['production_env_and_signing_keys_unchanged']
assert VHOST.read_bytes() == (BACK/'nginx-private.conf').read_bytes()
for p, expected in json.loads((BACK/'protected-files.json').read_text()).items():
    assert hashlib.sha256(Path(p).read_bytes()).hexdigest() == expected
session = requests.Session()
session.trust_env = False
base = 'https://skin.ysumc.net'
assert session.get(base+'/auth/login',timeout=20).content == Path('/www/server/stop/bt-stop.html').read_bytes()
checks = []
try:
    sp.run(['systemctl','enable','ysumc-php85-fpm'],check=True)
    sp.run(['systemctl','is-active','--quiet','ysumc-php85-fpm'],check=True)
    sp.run(['systemctl','is-active','--quiet','ysumc-janus'],check=True)
    shutil.copy2(BACK/'nginx-public-ready.conf',VHOST)
    sp.run([NGINX,'-t'],check=True)
    sp.run([NGINX,'-s','reload'],check=True)
    with sqlite3.connect('/www/server/panel/data/db/site.db') as con:
        result=con.execute("UPDATE sites SET path=?,status='1',stop='' WHERE id=51 AND name='skin.ysumc.net'",(ROOT,))
        assert result.rowcount == 1
    # New connections may briefly reach a worker that is draining the old config.
    for i in range(10):
        response=session.get(base+'/auth/login',timeout=20,headers={'Connection':'close'})
        if response.status_code == 200 and 'csrf-token' in response.text: break
        time.sleep(1)
    assert response.status_code == 200 and 'csrf-token' in response.text
    checks.append('Public HTTPS login page restored')
    response=session.get(base+'/auth/eduroam/login',timeout=20)
    assert response.status_code == 200 and '<form' in response.text
    checks.append('Public eduroam form renders')
    for path in ['/api/launcher/manifest','/api/launcher/announcements']:
        response=session.get(base+path,timeout=20)
        assert response.status_code == 200
        response.json()
        checks.append('Public '+path)
    response=session.get(base+'/api/janus/.well-known/openid-configuration',timeout=20)
    assert response.status_code == 200 and response.json()['issuer']==base+'/api/janus'
    jwks=session.get(response.json()['jwks_uri'],timeout=20)
    assert jwks.status_code == 200 and jwks.json()['keys']
    assert all(not any(k in key for k in ['d','p','q','dp','dq','qi']) for key in jwks.json()['keys'])
    checks.append('Public Janus discovery and public-only JWKS')
    for path in ['/.env','/.user.ini','/test.php']:
        assert session.get(base+path,timeout=20).status_code in (403,404)
        checks.append('Protected '+path)
    assert session.get('http://skin.ysumc.net/auth/login',timeout=20,allow_redirects=False).status_code==301
    checks.append('HTTP redirects to HTTPS')
    (BACK/'opened.json').write_text(json.dumps({'opened_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'php':'8.5.12','node':'26.12.1','checks':checks,'public_access_restored':True},indent=2))
    print(json.dumps({'public_access_restored':True,'checks':checks},indent=2))
except BaseException:
    sp.run(['bash',str(BACK/'rollback.sh')],check=True)
    raise
