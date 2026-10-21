#!/usr/bin/env python3
"""Query actual production FPM through its Unix socket, then remove the probe."""
import hashlib
import json
from pathlib import Path
import secrets
import socket
import struct
import subprocess as sp

ROOT = Path('/www/wwwroot/ysumcusercentre-php85-20261022')
BACK = Path('/www/server/php-compat-assessments/20261022-migration/production-cutover-20261022')
probe = ROOT/'public'/('.php85-probe-'+secrets.token_hex(12)+'.php')

def record(kind, payload):
    pad = (-len(payload)) % 8
    return struct.pack('!BBHHBB',1,kind,1,len(payload),pad,0)+payload+b'\0'*pad

def length(n):
    return bytes([n]) if n < 128 else struct.pack('!I',n | 0x80000000)

params = {'SCRIPT_FILENAME':str(probe),'SCRIPT_NAME':'/'+probe.name,'REQUEST_METHOD':'GET','SERVER_PROTOCOL':'HTTP/1.1','SERVER_NAME':'skin.ysumc.net','HTTPS':'on','REMOTE_ADDR':'127.0.0.1','DOCUMENT_ROOT':str(ROOT/'public')}
payload = b''.join(length(len(k.encode()))+length(len(v.encode()))+k.encode()+v.encode() for k,v in params.items())
try:
    probe.write_text("<?php header('Content-Type: application/json'); echo json_encode(['version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'ini'=>php_ini_loaded_file(),'upload_progress'=>ini_get('session.upload_progress.enabled'),'extensions'=>get_loaded_extensions(),'open_basedir'=>ini_get('open_basedir')]);")
    probe.chmod(0o644)
    with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as sock:
        sock.settimeout(10)
        sock.connect('/run/ysumc-php85/php-fpm.sock')
        sock.sendall(record(1,struct.pack('!HB5x',1,0))+record(4,payload)+record(4,b'')+record(5,b''))
        raw=b''
        while True:
            chunk=sock.recv(65536)
            if not chunk: break
            raw+=chunk
    output=b''
    while len(raw)>=8:
        version,kind,rid,size,pad,_=struct.unpack('!BBHHBB',raw[:8])
        if kind==6: output+=raw[8:8+size]
        if kind==7 and raw[8:8+size]: raise RuntimeError('FPM emitted stderr; inspect private logs')
        raw=raw[8+size+pad:]
    result=json.loads(output.split(b'\r\n\r\n',1)[1])
    assert result['version']=='8.5.12' and result['sapi']=='fpm-fcgi'
    assert result['upload_progress'] in ('','0','Off')
    assert result['ini']=='/opt/ysumc/php-8.5.12/etc/php.ini'
    assert {'gd','zip','fileinfo','pdo_mysql','mbstring','curl','intl','sodium','openssl'} <= set(result['extensions'])
    result['source_files_checked']=0
    manifest=json.loads((BACK.parent/'php85-maintenance-manifest.json').read_text())
    for rel, expected in manifest['files'].items():
        assert hashlib.sha256((ROOT/rel).read_bytes()).hexdigest()==expected, 'Source mismatch: '+rel
        result['source_files_checked']+=1
    protected=json.loads((BACK/'protected-files.json').read_text())
    assert all(hashlib.sha256(Path(p).read_bytes()).hexdigest()==h for p,h in protected.items())
    assert (ROOT/'.env').read_bytes()==Path('/www/wwwroot/ysumcusercentre/.env').read_bytes()
    result['production_env_and_signing_keys_unchanged']=True
    pid=sp.check_output(['systemctl','show','ysumc-janus','-p','MainPID','--value'],text=True).strip()
    exe=Path('/proc')/pid/'exe'
    result['janus_node_version']=sp.check_output([str(exe),'--version'],text=True).strip()
    result['janus_executable']=str(exe.resolve())
    assert result['janus_node_version']=='v26.12.1'
    (BACK/'runtime-check.json').write_text(json.dumps(result,indent=2))
    print(json.dumps(result,indent=2))
finally:
    probe.unlink(missing_ok=True)
