#!/usr/bin/env python3
"""Replace a stopped local BaoTa PHP 8.1 with a build from build.py; leave it stopped."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import subprocess
import time


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--work', required=True, type=Path)
    parser.add_argument('--plan', action='store_true', help='Validate and print paths without replacing anything')
    args = parser.parse_args()
    work = args.work.resolve()
    manifest = json.loads((work / 'build.json').read_text())
    assert manifest['version'] == '8.1.34'
    assert manifest['patch_commit'] == '1d7dec178a094e6e75b229ce22754045f8c6070a'
    assert manifest['machine'] == platform.machine()
    prefix = Path(manifest['prefix'])
    assert prefix == Path('/www/server/php/81'), 'This replacement script supports only BaoTa /www/server/php/81'
    assert prefix.is_dir() and not prefix.is_symlink()
    assert subprocess.check_output([str(prefix / 'bin/php'),'-n','-r','echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;'],text=True) == '8.1'
    stage = work / 'stage' / prefix.relative_to('/')
    for name, digest in manifest['sha256'].items():
        assert hashlib.sha256((stage / name).read_bytes()).hexdigest() == digest, 'Built file changed: ' + name
    assert '8.1.34' in subprocess.check_output([str(stage / 'bin/php'),'-n','-v'],text=True)
    if args.plan:
        print(json.dumps({'source':str(stage),'destination':str(prefix),'backup':str(work / 'previous-runtime'),
                          'will_start_service':False,'will_change_website':False},indent=2))
        return
    assert os.geteuid() == 0, 'Root is required for replacement'
    assert not (prefix / 'var/run/php-fpm.pid').exists(), 'Stop FPM first; inspect any stale PID file manually'
    for process in Path('/proc').glob('[0-9]*/exe'):
        try:
            target = os.readlink(process)
        except (FileNotFoundError, PermissionError, ProcessLookupError):
            continue
        assert not target.startswith(str(prefix) + '/'), 'A process is still using PHP: ' + str(process)
    previous = work / 'previous-runtime'
    assert not previous.exists(), 'Previous runtime backup already exists'
    assert prefix.stat().st_dev == work.stat().st_dev, 'Work and PHP must be on the same filesystem for atomic directory replacement'
    candidate = prefix.parent / ('81-repair-candidate-' + str(int(time.time())))
    assert not candidate.exists()
    candidate.mkdir(mode=0o755)
    subprocess.run(['rsync','-a','--exclude=/src','--exclude=/src.tar.gz',str(prefix)+'/',str(candidate)+'/'],check=True)
    subprocess.run(['rsync','-a',str(stage)+'/',str(candidate)+'/'],check=True)
    # Preserve all current settings; force the already recommended session mitigation.
    for name in ('php.ini','php-cli.ini'):
        ini = candidate / 'etc' / name
        if not ini.exists():
            continue
        content = ini.read_text()
        pattern = r'(?m)^\s*;?\s*session\.upload_progress\.enabled\s*=.*$'
        if re.search(pattern, content):
            content = re.sub(pattern,'session.upload_progress.enabled = Off',content)
        else:
            content += '\nsession.upload_progress.enabled = Off\n'
        ini.write_text(content)
    (candidate / 'src').symlink_to(work / 'php-8.1.34')
    for name in ('version.pl','version_check.pl'):
        (candidate / name).write_text('8.1.34\n')
    (candidate / 'YSUMC-SECURITY-BUILD.json').write_text(json.dumps(manifest,indent=2)+'\n')
    subprocess.run([str(candidate / 'sbin/php-fpm'),'-t','-y',str(candidate / 'etc/php-fpm.conf')],check=True)
    os.rename(prefix, previous)
    try:
        os.rename(candidate, prefix)
        subprocess.run([str(prefix / 'bin/php'),'-v'],check=True)
        subprocess.run([str(prefix / 'sbin/php-fpm'),'-t'],check=True)
    except BaseException:
        if prefix.exists():
            os.rename(prefix, work / 'failed-runtime')
        os.rename(previous, prefix)
        raise
    print('Installed patched PHP. FPM and websites remain stopped. Backup: ' + str(previous))


if __name__ == '__main__':
    main()
