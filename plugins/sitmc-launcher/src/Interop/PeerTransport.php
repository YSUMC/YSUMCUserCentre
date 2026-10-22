<?php

namespace SITMinecraft\Launcher\Interop;

use RuntimeException;

/** No redirects/proxy/ambient credentials; pin the validated DNS answer to prevent rebinding. */
class PeerTransport
{
    public function __construct(private PublicAddress $addresses, private int $timeoutSeconds = 15) {}

    public function get(string $url, ?string $etag): array
    {
        $this->addresses->url($url);
        $host = trim(parse_url($url, PHP_URL_HOST), '[]');
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        $ips = $this->addresses->resolve($host);
        $body = ''; $headers = []; $headerBytes = 0;
        $curl = curl_init($url);
        $ip = $ips[0];
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => min(15, max(1, $this->timeoutSeconds)), CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
            CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', 'Accept-Encoding: identity',
                $etag !== null && strlen($etag) <= 512 && ! preg_match('/[\r\n]/', $etag) ? 'If-None-Match: '.$etag : null]),
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, &$headerBytes): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 32768) return 0;
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        ]);
        try {
            if (curl_exec($curl) === false) throw new RuntimeException('Peer HTTPS fetch failed or exceeded its limits.');
            return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
        } finally {
            unset($curl);
        }
    }

    public function backoff(int $attempt): void
    {
        usleep((2 ** $attempt) * 250000);
    }
}
