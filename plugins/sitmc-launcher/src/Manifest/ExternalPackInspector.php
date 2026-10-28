<?php

namespace SITMinecraft\Launcher\Manifest;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use RuntimeException;
use SITMinecraft\Launcher\Interop\PublicAddress;

/** Hash the response as it arrives; never create a temporary file or body cache. */
class ExternalPackInspector
{
    public const MAX_BYTES = 2147483648;
    public const TIMEOUT_SECONDS = 55;
    public const MAX_REDIRECTS = 5;

    public function __construct(
        private PublicAddress $addresses,
        private int $maxBytes = self::MAX_BYTES,
        private int $timeoutSeconds = self::TIMEOUT_SECONDS,
    ) {}

    /** @return array{sha1: string, sha512: string, size: int} */
    public function inspect(string $url, string $format = 'mrpack'): array
    {
        $deadline = microtime(true) + min(self::TIMEOUT_SECONDS, max(1, $this->timeoutSeconds));
        $maxBytes = min(self::MAX_BYTES, max(1, $this->maxBytes));
        $visited = [];
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            if (isset($visited[$url]) || strlen($url) > 8192) throw new RuntimeException('remote-redirect');
            $visited[$url] = true;
            // Revalidate and pin every hop, including cross-host CDN redirects.
            $this->addresses->url($url);
            $host = strtolower(trim(parse_url($url, PHP_URL_HOST), '[]'));
            $port = parse_url($url, PHP_URL_PORT) ?? 443;
            $ips = $this->addresses->resolve($host);
            $remaining = (int) floor(($deadline - microtime(true)) * 1000);
            if ($remaining <= 0) throw new RuntimeException('remote-timeout');
            $sha1 = hash_init('sha1');
            $sha512 = hash_init('sha512');
            $size = 0; $prefix = ''; $status = 0; $headers = []; $headerBytes = 0; $failure = null;
            $curl = curl_init($url);
            try {
                $ip = $ips[0];
                $resolve = filter_var($host, FILTER_VALIDATE_IP) ? [] : [
                    $host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip),
                ];
                curl_setopt_array($curl, [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_PROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED,
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_RESOLVE => $resolve,
                    CURLOPT_TIMEOUT_MS => $remaining, CURLOPT_CONNECTTIMEOUT_MS => min(10000, $remaining),
                    CURLOPT_HTTPHEADER => ['Accept: application/octet-stream, */*', 'Accept-Encoding: identity'],
                    CURLOPT_USERAGENT => 'YSUMCL-PackInspector/1.0',
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, &$status, &$headerBytes, &$failure, $maxBytes): int {
                        $headerBytes += strlen($line);
                        if ($headerBytes > 32768) { $failure = 'remote-response'; return 0; }
                        if (preg_match('~^HTTP/\S+\s+(\d{3})(?:\s|$)~', $line, $match)) {
                            $status = (int) $match[1]; $headers = [];
                        } elseif (str_contains($line, ':')) {
                            [$key, $value] = explode(':', $line, 2);
                            $headers[strtolower(trim($key))] = trim($value);
                        }
                        if (trim($line) === '' && $status >= 200) {
                            // Ignore error/redirect bodies; only hash the final complete 200 response.
                            if ($status !== 200) return 0;
                            if (isset($headers['content-length']) && (!ctype_digit($headers['content-length']) || (float) $headers['content-length'] > $maxBytes)) {
                                $failure = 'remote-too-large'; return 0;
                            }
                            if (isset($headers['content-range']) || !in_array(strtolower($headers['content-encoding'] ?? 'identity'), ['', 'identity'], true)) {
                                $failure = 'remote-response'; return 0;
                            }
                        }
                        return strlen($line);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$size, &$prefix, &$failure, &$status, $sha1, $sha512, $maxBytes): int {
                        $length = strlen($chunk);
                        if ($status !== 200) return 0;
                        if ($length > $maxBytes - $size) { $failure = 'remote-too-large'; return 0; }
                        $prefix .= substr($chunk, 0, max(0, 4 - strlen($prefix)));
                        hash_update($sha1, $chunk);
                        hash_update($sha512, $chunk);
                        $size += $length;
                        return $length;
                    },
                ]);
                $ok = curl_exec($curl);
                $error = curl_errno($curl);
                if ($failure !== null) throw new RuntimeException($failure);
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    if (empty($headers['location']) || $hop === self::MAX_REDIRECTS) throw new RuntimeException('remote-redirect');
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($headers['location']));
                    continue;
                }
                if ($error === CURLE_OPERATION_TIMEDOUT) throw new RuntimeException('remote-timeout');
                if ($status !== 200 || $ok === false) throw new RuntimeException('remote-response');
                if ($size === 0) throw new RuntimeException('remote-empty');
                if (isset($headers['content-length']) && (int) $headers['content-length'] !== $size) throw new RuntimeException('remote-response');
                if ($format !== 'raw' && !in_array($prefix, ["PK\x03\x04", "PK\x05\x06"], true)) throw new RuntimeException('remote-not-zip');
                return ['sha1' => hash_final($sha1), 'sha512' => hash_final($sha512), 'size' => $size];
            } finally {
                // Release the connection on success, redirects and all failures.
                // No file is ever written, so interrupted requests leave no cache behind.
                unset($curl);
            }
        }
        throw new RuntimeException('remote-redirect');
    }
}
