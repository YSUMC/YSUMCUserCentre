#!/usr/bin/env python3
"""Check a local PHP-FPM socket; run --oom only against an isolated one-worker pool."""

import argparse
import json
import os
from pathlib import Path
import pwd
import shutil
import socket
import struct
import tempfile
import time


def record(kind, content):
    return struct.pack("!BBHHBB", 1, kind, 1, len(content), 0, 0) + content


def length(value):
    return bytes([value]) if value < 128 else struct.pack("!I", value | 0x80000000)


def receive(sock, size):
    result = b""
    while len(result) < size:
        part = sock.recv(size - len(result))
        if not part:
            raise EOFError("FPM closed the request before completion")
        result += part
    return result


def request(socket_path, script, uri, body=b"", content_type=""):
    params = {
        "GATEWAY_INTERFACE": "CGI/1.1", "SERVER_PROTOCOL": "HTTP/1.1",
        "REQUEST_METHOD": "POST" if body else "GET", "SCRIPT_FILENAME": str(script),
        "SCRIPT_NAME": "/index.php", "REQUEST_URI": uri,
        "QUERY_STRING": uri.partition("?")[2], "DOCUMENT_ROOT": str(script.parent),
        "SERVER_NAME": "skin.ysumc.net", "HTTP_HOST": "skin.ysumc.net",
        "SERVER_PORT": "443", "HTTPS": "on", "REMOTE_ADDR": "127.0.0.1",
        "CONTENT_LENGTH": str(len(body)), "CONTENT_TYPE": content_type,
    }
    encoded = b"".join(length(len(k.encode())) + length(len(v.encode()))
                       + k.encode() + v.encode() for k, v in params.items())
    output = b""
    with socket.socket(socket.AF_UNIX) as sock:
        sock.settimeout(30)
        sock.connect(socket_path)
        sock.sendall(record(1, struct.pack("!HB5x", 1, 0))
                     + record(4, encoded) + record(4, b"")
                     + (record(5, body) if body else b"") + record(5, b""))
        while True:
            version, kind, req_id, size, padding, _ = struct.unpack("!BBHHBB", receive(sock, 8))
            assert version == 1 and req_id == 1
            payload = receive(sock, size)
            if padding:
                receive(sock, padding)
            if kind == 6:
                output += payload
            if kind == 3:
                assert payload[4] == 0, "FastCGI protocol failure"
                break
    head, sep, data = output.partition(b"\r\n\r\n")
    assert sep, "Missing FastCGI response headers"
    status = 200
    for line in head.split(b"\r\n"):
        if line.lower().startswith(b"status:"):
            status = int(line.split()[1])
    return status, data


PROBE = r'''<?php
if (($_GET['mode'] ?? '') === 'oom') {
    ini_set('memory_limit', '16M');
    register_shutdown_function(function () {
        file_put_contents(__DIR__.'/data/oom-shutdown-ran', 'unexpected');
    });
    $allocation = str_repeat('X', 32 * 1024 * 1024);
    exit;
}
$zipPath = __DIR__.'/data/check.zip';
$outPath = __DIR__.'/data/extract';
$zip = new ZipArchive;
$opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('probe.txt', 'patched-runtime');
$zip->close();
$zip->open($zipPath);
$extracted = $zip->extractTo($outPath);
$zip->close();
$image = imagecreatetruecolor(1, 1);
ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
$mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($png);
$result = [
    'version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'pid' => getmypid(),
    'loaded_ini' => php_ini_loaded_file(),
    'upload_progress' => ini_get('session.upload_progress.enabled'),
    'modules' => get_loaded_extensions(),
    'zip_roundtrip' => $opened === true && $extracted &&
        file_get_contents($outPath.'/probe.txt') === 'patched-runtime',
    'gd_fileinfo' => $mime === 'image/png',
    'session_status' => session_status(), 'session_id' => session_id(),
    'upload_error' => $_FILES['sample']['error'] ?? null,
];
unlink($outPath.'/probe.txt'); rmdir($outPath); unlink($zipPath);
header('Content-Type: application/json');
echo json_encode($result, JSON_THROW_ON_ERROR);
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--socket", required=True)
    parser.add_argument("--app", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--oom", action="store_true")
    parser.add_argument("--upload-progress-disabled-in-core", action="store_true")
    args = parser.parse_args()
    if (args.oom or args.upload_progress_disabled_in_core) and args.socket != "/tmp/ysumc-php81-security-test.sock":
        parser.error("Security behavior tests require the dedicated isolated test socket")
    checks = {}
    temporary = Path(tempfile.mkdtemp(prefix="ysumc-php81-check-"))
    try:
        temporary.chmod(0o755)
        data_dir = temporary / "data"
        data_dir.mkdir(mode=0o750)
        www = pwd.getpwnam("www")
        os.chown(data_dir, www.pw_uid, www.pw_gid)
        script = temporary / "probe.php"
        script.write_text(PROBE)
        script.chmod(0o644)
        status, body = request(args.socket, script, "/probe")
        metadata = json.loads(body)
        assert status == 200 and metadata["version"] == "8.1.34", metadata
        assert metadata["zip_roundtrip"] and metadata["gd_fileinfo"], metadata
        checks["runtime"] = metadata
        if args.oom:
            try:
                request(args.socket, script, "/probe?mode=oom")
            except (EOFError, ConnectionResetError):
                pass
            else:
                raise AssertionError("OOM did not terminate isolated worker")
            assert not (data_dir / "oom-shutdown-ran").exists(), "OOM invoked PHP shutdown callbacks"
            time.sleep(1)
            _, body = request(args.socket, script, "/probe")
            after = json.loads(body)
            assert after["pid"] != metadata["pid"], "OOM worker was reused"
            checks["oom_worker_exit_and_replacement"] = True
        if args.upload_progress_disabled_in_core:
            boundary = "YSUMCSecurityBoundary"
            body = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"PHP_SESSION_UPLOAD_PROGRESS\"\r\n\r\ncheck\r\n"
                    f"--{boundary}\r\nContent-Disposition: form-data; name=\"sample\"; filename=\"check.txt\"\r\n"
                    f"Content-Type: text/plain\r\n\r\ncheck\r\n--{boundary}--\r\n").encode()
            status, result = request(args.socket, script, "/probe", body, f"multipart/form-data; boundary={boundary}")
            upload = json.loads(result)
            assert status == 200 and upload["upload_error"] == 0, upload
            assert upload["upload_progress"] == "1", "Test pool must enable upload progress to test core patch"
            assert upload["session_id"] == "" and upload["session_status"] == 1, upload
            checks["core_upload_progress_disabled_with_ini_on"] = True
        for path in ("/auth/login", "/auth/eduroam/login", "/api/launcher/manifest"):
            status, body = request(args.socket, args.app / "public/index.php", path)
            assert status == 200, f"{path}: HTTP {status}"
            if path.endswith("manifest"):
                assert isinstance(json.loads(body), dict)
            else:
                assert b"<html" in body.lower() and len(body) > 1000
            checks[path] = {"status": status, "response_bytes": len(body)}
        args.output.write_text(json.dumps(checks, indent=2) + "\n")
        print(json.dumps(checks, indent=2))
    finally:
        shutil.rmtree(temporary)


if __name__ == "__main__":
    main()
