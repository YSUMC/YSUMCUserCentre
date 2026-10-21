#!/usr/bin/env python3
"""Production TLS integration test. DNS is pinned to loopback; synthetic credentials only."""
import base64
import socket
import subprocess
import io
import zipfile
import secrets
import struct
import zlib
from html.parser import HTMLParser
import json
from pathlib import Path
import time
from urllib.parse import urljoin, urlparse
import requests

STATE = Path('/www/server/php-compat-assessments/20261022-migration/production-cutover-20261022')
fixture = json.loads((STATE/'production-fixture.json').read_text())
base = fixture['base']
assert base == 'https://skin.ysumc.net'
original_getaddrinfo = socket.getaddrinfo
def loopback_dns(host, port, *args, **kwargs):
    if host != 'skin.ysumc.net':
        raise RuntimeError('External request blocked by production verifier')
    return original_getaddrinfo('127.0.0.1', port, *args, **kwargs)
socket.getaddrinfo = loopback_dns
session = requests.Session()
session.trust_env = False
checks = []


def check(condition, label):
    if not condition:
        raise AssertionError(label)
    checks.append(label)
    print('PASS:', label, flush=True)


def request(method, url, **kwargs):
    url = urljoin(base, url)
    assert urlparse(url).hostname == 'skin.ysumc.net' and urlparse(url).scheme == 'https'
    response = session.request(method, url, timeout=20, **kwargs)
    check(response.status_code < 500, f'{method} {urlparse(url).path} no server error')
    return response


class Forms(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.forms = []
        self.current = None
        self.csrf = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'meta' and attrs.get('name') == 'csrf-token':
            self.csrf = attrs.get('content')
        if tag == 'form':
            self.current = {'action': attrs.get('action', ''), 'id': attrs.get('id'), 'data': {}}
            self.forms.append(self.current)
        if tag == 'input' and self.current and attrs.get('name') and attrs.get('type') not in ('submit', 'button'):
            self.current['data'][attrs['name']] = attrs.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'form':
            self.current = None


page = request('GET', '/auth/login')
check(page.status_code == 200, 'FPM renders login with Twig 3')
csrf = Forms(page.text).csrf
check(bool(csrf), 'CSRF token rendered')
bad = request('POST', '/auth/login', json={'identification': fixture['email'], 'password': fixture['password']}, headers={'Accept': 'application/json'})
check(bad.status_code == 419, 'FPM rejects login without CSRF token')
login = request('POST', '/auth/login', json={'identification': fixture['email'], 'password': fixture['password']}, headers={'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'})
check(login.json().get('code') == 0, 'Real session login succeeds')
check(request('GET', '/user').status_code == 200, 'Session cookie authenticates next HTTP request')
check(request('GET', '/admin').status_code == 403, 'Ordinary user cannot access admin over FPM')

metadata = request('GET', '/api/janus/.well-known/openid-configuration').json()
check(metadata['issuer'] == base+'/api/janus', 'Janus advertises the production HTTPS issuer')
credentials = {'client_id': str(fixture['client_id']), 'client_secret': fixture['client_secret']}
scopes = 'openid profile email offline_access Yggdrasil.PlayerProfiles.Select Yggdrasil.Server.Join'
device_response = request('POST', metadata['device_authorization_endpoint'], data=credentials | {'scope': scopes})
check(device_response.status_code == 200, 'Device authorization accepts shared legacy client')
device = device_response.json()
poll = credentials | {'grant_type': 'urn:ietf:params:oauth:grant-type:device_code', 'device_code': device['device_code']}
pending = request('POST', metadata['token_endpoint'], data=poll)
check(pending.json().get('error') == 'authorization_pending', 'Unapproved device code cannot issue a token')

page = request('GET', device['verification_uri_complete'])
for step in range(10):
    parsed = Forms(page.text)
    if '登录成功' in page.text:
        break
    if any('auth_token' in f['data'] for f in parsed.forms):
        form = next(f for f in parsed.forms if 'auth_token' in f['data'])
        check(bool(form['data']['auth_token']), 'Passport 13 authorization approval nonce present')
    elif any(f['id'] == 'select-profile' for f in parsed.forms):
        form = next(f for f in parsed.forms if f['id'] == 'select-profile')
        form['data']['selectedProfile'] = fixture['uuid']
    elif parsed.forms:
        form = parsed.forms[0]
    else:
        raise AssertionError('No expected authorization form at '+urlparse(page.url).path)
    action = urljoin(page.url, form['action'])
    page = request('POST', action, data=form['data'])
check('登录成功' in page.text, 'Device confirmation, PHP consent and profile selection complete')
time.sleep(max(1, min(device.get('interval', 5), 10)))
tokens_response = request('POST', metadata['token_endpoint'], data=poll)
check(tokens_response.status_code == 200, 'Approved device code issues tokens')
tokens = tokens_response.json()
check(all(k in tokens for k in ('access_token', 'refresh_token', 'id_token')), 'Access, refresh and ID tokens returned')
claims = json.loads(base64.urlsafe_b64decode(tokens['access_token'].split('.')[1]+'=='))
check(claims['selectedProfile'] == fixture['uuid'] and claims['sub'] == str(fixture['uid']), 'Janus JWT binds the correct user and profile')
time.sleep(0.3)
info = request('GET', '/yggc/userinfo', headers={'Authorization': 'Bearer '+tokens['access_token'], 'Accept': 'application/json'})
check(info.status_code == 200 and info.json()['selectedProfile']['id'] == fixture['uuid'], 'PHP 8.5 accepts Janus JWT and returns selected profile')
validate = request('POST', '/api/yggdrasil/authserver/validate', json={'accessToken': tokens['access_token']})
check(validate.status_code == 204, 'Janus token works for Yggdrasil')
refreshed = request('POST', metadata['token_endpoint'], data=credentials | {'grant_type': 'refresh_token', 'refresh_token': tokens['refresh_token']})
check(refreshed.status_code == 200, 'Janus refresh token rotates successfully')
fresh = refreshed.json()
time.sleep(0.3)
revoked = request('POST', '/api/yggdrasil/authserver/invalidate', json={'accessToken': fresh['access_token']})
check(revoked.status_code == 204, 'PHP revokes the current Janus access token')
invalid = request('POST', metadata['token_endpoint'], data=credentials | {'grant_type': 'refresh_token', 'refresh_token': fresh['refresh_token']})
check(invalid.status_code == 400 and invalid.json().get('error') == 'invalid_grant', 'PHP revocation also blocks Janus refresh')
# Existing production plugin settings and credentials are preserved throughout.
for path in ['/user/player', '/user/closet', '/user/oauth/manage', '/skinlib', '/skinlib/upload']:
    check(request('GET', path).status_code == 200, 'Authenticated page '+path)
anon = requests.Session()
anon.trust_env = False
eduroam = anon.get(base+'/auth/eduroam/login', timeout=20)
check(eduroam.status_code == 200 and '<form' in eduroam.text, 'Eduroam login plugin renders its form')
registration = anon.get(base+'/auth/register', timeout=20, allow_redirects=False)
check(registration.status_code == 503 and '注册' in registration.text, 'Registration-disabled plugin returns its intentional 503 notice')
for path in ['/api/launcher/manifest', '/api/launcher/announcements', '/api/launcher/update/versions']:
    check(request('GET', path).status_code == 200, 'Launcher public endpoint '+path)

credentials_ygg = {'username': fixture['email'], 'password': fixture['password'], 'clientToken': secrets.token_hex(16), 'requestUser': True}
ygg = '/api/yggdrasil/'
auth = request('POST', ygg+'authserver/authenticate', json=credentials_ygg)
check(auth.status_code == 200 and auth.json()['selectedProfile']['id'] == fixture['uuid'], 'Yggdrasil password login and personal access client work')
ygg_token = auth.json()['accessToken']
rotated = request('POST', ygg+'authserver/refresh', json={'accessToken': ygg_token, 'clientToken': credentials_ygg['clientToken']})
check(rotated.status_code == 200, 'Yggdrasil refresh succeeds')
check(request('POST', ygg+'authserver/validate', json={'accessToken': ygg_token}).status_code == 403, 'Old Yggdrasil token is invalidated by refresh')
ygg_token = rotated.json()['accessToken']
server_id = 'php85-'+secrets.token_hex(12)
joined = request('POST', ygg+'sessionserver/session/minecraft/join', json={'accessToken': ygg_token, 'selectedProfile': fixture['uuid'], 'serverId': server_id})
check(joined.status_code == 204, 'Minecraft join accepts the owned profile')
profile = request('GET', ygg+'sessionserver/session/minecraft/hasJoined', params={'username': fixture['player_name'], 'serverId': server_id})
check(profile.status_code == 200 and profile.json()['id'] == fixture['uuid'], 'Minecraft hasJoined returns the correct profile')
prop = profile.json()['properties'][0]
key = request('GET', ygg).json()['signaturePublickey']
(STATE/'verify-ygg-public.pem').write_text(key)
(STATE/'verify-ygg-signature.bin').write_bytes(base64.b64decode(prop['signature']))
signed = subprocess.run(['openssl', 'dgst', '-sha1', '-verify', str(STATE/'verify-ygg-public.pem'), '-signature', str(STATE/'verify-ygg-signature.bin')], input=prop['value'].encode(), capture_output=True)
check(signed.returncode == 0, 'Yggdrasil texture signature verifies against unchanged production public key')
check(request('POST', ygg+'authserver/invalidate', json={'accessToken': ygg_token}).status_code == 204, 'Yggdrasil invalidation succeeds')
check(request('POST', ygg+'authserver/validate', json={'accessToken': ygg_token}).status_code == 403, 'Revoked Yggdrasil token is rejected')

csrf = Forms(request('GET', '/user').text).csrf
headers = {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}
def chunk(kind, content):
    return struct.pack('!I', len(content))+kind+content+struct.pack('!I', zlib.crc32(kind+content)&0xffffffff)
pixel = secrets.token_bytes(3)+b'\xff'
png = b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!IIBBBBB',64,64,8,6,0,0,0))+chunk(b'IDAT',zlib.compress((b'\0'+pixel*64)*64))+chunk(b'IEND',b'')
uploaded = request('POST', '/texture', data={'name':'PHP85 Cutover Skin','type':'steve','public':'0'}, files={'file':('skin.png',png,'image/png')}, headers=headers)
check(uploaded.status_code == 200 and uploaded.json().get('code') == 0, 'Real skin upload writes shared production texture storage')
tid = uploaded.json()['data']['tid']
(STATE/'synthetic-texture.json').write_text(json.dumps({'tid':tid,'uid':fixture['uid']}))
for path in [f'/preview/{tid}?png',f'/avatar/{tid}?png',f'/avatar/{tid}?3d&png']:
    preview = request('GET',path)
    check(preview.status_code == 200 and preview.content.startswith(b'\x89PNG'), 'GD rendering '+path.split('?')[0])
deleted = request('DELETE',f'/texture/{tid}',headers=headers)
check(deleted.status_code == 200 and deleted.json().get('code') == 0, 'Synthetic skin removed through the normal API')

archive = io.BytesIO()
with zipfile.ZipFile(archive,'w') as z:
    z.writestr(fixture['plugin_dir']+'/LICENSE','PHP85 production extraction check')
zip_bytes = archive.getvalue()
denied = request('POST','/admin/plugins/upload',files={'file':('fixture.zip',zip_bytes,'application/zip')},headers=headers)
check(denied.status_code == 403, 'Ordinary user cannot upload plugin ZIP')
subprocess.run(['/opt/ysumc/php-8.5.12/bin/php','-c','/opt/ysumc/php-8.5.12/etc/php.ini',str(STATE/'production-fixture.php'),'admin'],check=True)
for path in ['/admin','/admin/users','/admin/plugins/manage','/admin/launcher','/admin/launcher/announcements']:
    check(request('GET',path).status_code == 200, 'Admin plugin page '+path)
extracted = request('POST','/admin/plugins/upload',files={'file':('fixture.zip',zip_bytes,'application/zip')},headers=headers)
check(extracted.status_code == 200 and extracted.json().get('code') == 0, 'Admin ZIP extraction succeeds under production FPM permissions')
license_file = Path('/www/wwwroot/ysumcusercentre-php85-20261022/plugins')/fixture['plugin_dir']/'LICENSE'
check(license_file.read_text() == 'PHP85 production extraction check', 'Extracted plugin LICENSE matches expected bytes')
(STATE/'http-smoke-result.json').write_text(json.dumps({'passed': len(checks), 'checks': checks}, indent=2))
print(f'Completed {len(checks)} production HTTP checks; no credentials printed.')
