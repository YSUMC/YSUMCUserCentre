#!/usr/bin/env python3
"""Loopback-only FPM/Janus integration test. Reads disposable credentials privately."""
import base64
from html.parser import HTMLParser
import json
from pathlib import Path
import time
from urllib.parse import urljoin, urlparse
import requests

STATE = Path('/www/server/php-compat-assessments/20261022-migration')
fixture = json.loads((STATE/'http-fixture.json').read_text())
base = fixture['base']
assert base == 'http://localhost:13850'
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
    assert urlparse(url).hostname == 'localhost' and urlparse(url).port == 13850
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
check(metadata['issuer'] == base+'/api/janus', 'Janus advertises the isolated issuer')
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
(STATE/'http-smoke-result.json').write_text(json.dumps({'passed': len(checks), 'checks': checks}, indent=2))
print(f'Completed {len(checks)} HTTP checks; no credentials printed.')
