#!/usr/bin/env python3
"""Build the official PHP 8.1 emergency backport locally; never installs over PHP."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import shlex
import subprocess

COMMIT = '1d7dec178a094e6e75b229ce22754045f8c6070a'
SOURCE_SHA256 = 'ffa9e0982e82eeaea848f57687b425ed173aa278fe563001310ae2638db5c251'


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def output(args, **kwargs):
    return subprocess.check_output([str(a) for a in args], text=True, **kwargs).strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--work', required=True, type=Path, help='A NEW absolute build directory')
    parser.add_argument('--prefix', type=Path, default=Path('/www/server/php/81'))
    parser.add_argument('--jobs', type=int, default=4)
    parser.add_argument('--prepare-only', action='store_true')
    args = parser.parse_args()
    kit = Path(__file__).resolve().parent
    assert args.work.is_absolute(), 'Use an absolute work directory'
    work = args.work.resolve()
    assert work.is_absolute() and not work.exists(), 'Use a new absolute work directory'
    assert 1 <= args.jobs <= 16
    prefix = args.prefix.resolve()
    assert prefix not in work.parents, 'Build outside the live PHP installation'
    old_php = prefix / 'bin/php'
    assert output([old_php, '-n', '-r', 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;']) == '8.1'
    for line in (kit / 'SHA256SUMS').read_text().splitlines():
        digest, name = line.split(None, 1)
        path = kit / name.strip().lstrip('*')
        assert path.parent == kit and sha256(path) == digest, 'Kit hash mismatch: ' + str(path)
    archive = kit / 'php-8.1.34.tar.xz'
    assert sha256(archive) == SOURCE_SHA256
    commit = json.loads((kit / 'official-commit.json').read_text())
    assert commit['sha'] == COMMIT and len(commit['files']) == 23
    work.mkdir(mode=0o700, parents=True)
    (work / 'logs').mkdir()
    subprocess.run(['tar', '-xJf', str(archive), '-C', str(work)], check=True)
    source = work / 'php-8.1.34'
    subprocess.run(['patch', '--batch', '--fuzz=0', '-p1', '-i', str(kit / 'security.patch')], cwd=source, check=True)
    for file in commit['files']:
        content = (source / file['filename']).read_bytes()
        actual = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
        assert actual == file['sha'], 'Patched file mismatch: ' + file['filename']
    print('Verified official source and all 23 patched files.', flush=True)
    if args.prepare_only:
        return

    env = os.environ.copy()
    nice = prefix / 'src/config.nice'
    if nice.exists():
        for key, value in re.findall(r"(?m)^(CFLAGS|CPPFLAGS|LDFLAGS|LIBS|PKG_CONFIG_PATH)='([^']*)'", nice.read_text()):
            env.setdefault(key, value)
    # Preserve custom library selection without evaluating a shell config file.
    pc_dirs = []
    for library in re.findall(r'=> (/[^\s]+)', output(['ldd', old_php])):
        pc = Path(library).parent / 'pkgconfig'
        if str(pc).startswith('/usr/local/') and pc.is_dir() and str(pc) not in pc_dirs:
            pc_dirs.append(str(pc))
    env['PKG_CONFIG_PATH'] = ':'.join(pc_dirs + [env.get('PKG_CONFIG_PATH', '')])
    options = shlex.split(output([prefix / 'bin/php-config', '--configure-options']))
    removed = {'--with-iconv-dir', '--with-libxml-dir=/usr', '--enable-inline-optimization', '--with-xmlrpc', '--disable-fileinfo'}
    options = [o for o in options if o not in removed and not o.startswith(('--prefix=', '--enable-fileinfo', '--with-zip'))]
    options += [f'--prefix={prefix}', '--enable-fileinfo=shared', '--with-zip=shared']
    (work / 'configure-command.txt').write_text(shlex.join(['./configure'] + options) + '\n')
    (work / 'build-environment.json').write_text(json.dumps({k: env.get(k) for k in ('CFLAGS','CPPFLAGS','LDFLAGS','LIBS','PKG_CONFIG_PATH')}, indent=2))
    for label, command in [('configure', ['./configure'] + options), ('make', ['nice','-n','10','make',f'-j{args.jobs}']), ('stage', ['make','install',f'INSTALL_ROOT={work / "stage"}'])]:
        print('Running ' + label + '; see ' + str(work / 'logs' / (label + '.log')), flush=True)
        with (work / 'logs' / (label + '.log')).open('w') as log:
            subprocess.run(command, cwd=source, env=env, stdout=log, stderr=subprocess.STDOUT, check=True)
    stage = work / 'stage' / prefix.relative_to('/')
    ext = stage / 'lib/php/extensions/no-debug-non-zts-20210902'
    original_ext = Path(output([prefix / 'bin/php-config', '--extension-dir']))
    missing_ext = {p.name for p in original_ext.glob('*.so')} - {p.name for p in ext.glob('*.so')}
    assert not missing_ext, 'Rebuild third-party extensions before installation: ' + ', '.join(sorted(missing_ext))
    old_ini = prefix / 'etc/php-cli.ini'
    if not old_ini.exists():
        old_ini = prefix / 'etc/php.ini'
    ini = old_ini.read_text().replace(str(prefix / 'lib/php/extensions'), str(stage / 'lib/php/extensions'))
    ini += f'\nextension_dir = "{ext}"\nsession.upload_progress.enabled = Off\n'
    test_ini = work / 'staged.ini'
    test_ini.write_text(ini)
    old_modules = output([old_php, '-r', 'echo json_encode(get_loaded_extensions());'])
    new_modules = output([stage / 'bin/php', '-c', test_ini, '-r', 'echo json_encode(get_loaded_extensions());'])
    assert set(json.loads(old_modules)) == set(json.loads(new_modules)), 'Extension set differs; rebuild missing extensions before installation'
    marker = work / 'unexpected-shutdown-callback'
    code = 'register_shutdown_function(function(){file_put_contents(' + json.dumps(str(marker)) + ',"bad");});str_repeat("x",32*1024*1024);'
    oom = subprocess.run([str(stage / 'bin/php'), '-n', '-d', 'memory_limit=16M', '-r', code], capture_output=True, text=True)
    assert oom.returncode == 1 and not marker.exists(), 'Emergency OOM behavior check failed'
    files = ['bin/php','bin/php-cgi','bin/phpdbg','sbin/php-fpm']
    files += [str(p.relative_to(stage)) for p in ext.glob('*.so')]
    manifest = {'version':'8.1.34','patch_commit':COMMIT,'prefix':str(prefix),'work':str(work),
                'machine':platform.machine(),'source_sha256':SOURCE_SHA256,'modules':json.loads(new_modules),
                'sha256':{f:sha256(stage / f) for f in files}}
    (work / 'build.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print('Build complete. Validate FPM and application compatibility, then use replace.py --plan.', flush=True)


if __name__ == '__main__':
    main()
