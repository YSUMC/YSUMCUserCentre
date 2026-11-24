"""Package only new OAuth libraries from a Composer build using the host lockfile.

Prepare a separate copy of the host composer.json/lock and packages directory;
add laravel/socialite:^5 and socialiteproviders/manager:^4 || ^5, then run:
composer update laravel/socialite socialiteproviders/manager --no-dev --no-scripts --no-plugins
Never run this Composer operation in the live webroot.
"""
import argparse
import hashlib
import json
import shutil
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--host-lock', required=True, type=Path)
parser.add_argument('--build-dir', required=True, type=Path)
parser.add_argument('--output', required=True, type=Path)
args = parser.parse_args()
host_bytes = args.host_lock.read_bytes()
host = {p['name']: p for p in json.loads(host_bytes)['packages']}
built = {p['name']: p for p in json.loads((args.build_dir/'composer.lock').read_bytes())['packages']}
allowed = {
    'laravel/socialite': {'Laravel\\Socialite\\': 'src/'},
    'league/oauth1-client': {'League\\OAuth1\\Client\\': 'src/'},
    'socialiteproviders/manager': {'SocialiteProviders\\Manager\\': 'src/'},
}
assert all(built.get(name) == metadata for name, metadata in host.items()), 'A host dependency changed'
assert set(built) - set(host) == set(allowed), 'Unexpected additional dependency'
vendor = args.output/'vendor'
assert not vendor.exists(), 'Refusing to merge with an existing vendor directory'
args.output.mkdir(parents=True, exist_ok=True)
vendor.mkdir()
autoload = ['<?php', '// Generated: only OAuth namespaces; the host loader always has priority.',
            '$loader = new \\Composer\\Autoload\\ClassLoader;']
for name, namespaces in allowed.items():
    package = built[name]
    assert package['autoload'] == {'psr-4': namespaces}, 'Unexpected autoload rules'
    shutil.copytree(args.build_dir/'vendor'/name, vendor/name)
    for namespace, path in namespaces.items():
        escaped = namespace.replace('\\', '\\\\')
        autoload.append(f"$loader->addPsr4('{escaped}', __DIR__.'/{name}/{path.rstrip('/')}');")
autoload += ['$loader->register(false);', 'return $loader;', '']
(vendor/'autoload.php').write_text('\n'.join(autoload), encoding='utf-8')
receipt = {
    'host_lock_sha256': hashlib.sha256(host_bytes).hexdigest(),
    'host_packages': {name: metadata['version'] for name, metadata in host.items()},
    'packages': [built[name] for name in allowed],
}
(args.output/'dependencies.lock.json').write_text(json.dumps(receipt, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
print(json.dumps({'packaged': {name: built[name]['version'] for name in allowed}, 'changed_host_packages': 0}))
