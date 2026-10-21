#!/usr/bin/env python3
"""Read-only snapshot of the currently running Janus unit and executable, even if deleted.

Creates a NEW private output directory. Never edits a unit, reloads systemd or restarts a service.
The snapshot may contain private unit settings: keep it on the server, out of the source archive.
"""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys


def run(*args):
    return subprocess.check_output(args, text=True).strip()


def main():
    if len(sys.argv) != 2 or os.geteuid() != 0:
        raise SystemExit('Usage (root): capture-janus-rollback.py NEW_ABSOLUTE_PRIVATE_DIRECTORY')
    output = Path(sys.argv[1])
    if not output.is_absolute() or output.exists():
        raise SystemExit('Output must be a new absolute directory')
    unit = 'ysumc-janus.service'
    pid = run('systemctl', 'show', unit, '-p', 'MainPID', '--value')
    if not pid.isdigit() or int(pid) <= 0:
        raise SystemExit('Janus must still be running to capture its deleted executable')
    executable = Path('/proc') / pid / 'exe'
    output.mkdir(mode=0o700)
    (output / 'unit-with-dropins.txt').write_text(run('systemctl', 'cat', unit) + '\n')
    binary = output / 'node'
    with executable.open('rb') as source, binary.open('xb') as target:
        shutil.copyfileobj(source, target)
    binary.chmod(0o700)
    if run('systemctl', 'show', unit, '-p', 'MainPID', '--value') != pid:
        raise SystemExit('Janus PID changed during capture; discard this snapshot and retry')
    snapshot = {
        'unit': unit, 'pid': int(pid), 'source_executable': os.readlink(executable),
        'version': run(str(binary), '--version'),
        'sha256': hashlib.sha256(binary.read_bytes()).hexdigest(),
        'unit_sha256': hashlib.sha256((output / 'unit-with-dropins.txt').read_bytes()).hexdigest(),
    }
    (output / 'manifest.json').write_text(json.dumps(snapshot, indent=2) + '\n')
    print(json.dumps(snapshot, indent=2))


if __name__ == '__main__':
    main()
