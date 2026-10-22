<?php

namespace SITMinecraft\Launcher\Interop;

use InvalidArgumentException;

/** Shared publication and fetch policy. DNS is checked again and pinned at fetch time. */
class PublicAddress
{
    public function host(string $host): string
    {
        $host = strtolower($host);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->publicIp($host)) {
                throw new InvalidArgumentException('Address must be public.');
            }
            return $host;
        }
        if (strlen($host) > 253 || ! str_contains($host, '.') ||
            ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ||
            preg_match('/(?:^|\.)(localhost|local|internal|lan|home|test|invalid)$/i', $host) ||
            preg_match('/^[0-9.]+$/', $host)) {
            throw new InvalidArgumentException('A public hostname is required.');
        }
        return $host;
    }

    public function url(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new InvalidArgumentException('An HTTPS URL without credentials or fragment is required.');
        }
        $this->host(trim($parts['host'] ?? '', '[]'));
        return $url;
    }

    /** @return list<string> */
    public function resolve(string $host): array
    {
        $host = $this->host($host);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_values(array_unique(array_filter(array_map(
            static fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            @dns_get_record($host, DNS_A | DNS_AAAA) ?: []
        ))));
        if ($ips === []) {
            throw new InvalidArgumentException('Public DNS resolution failed.');
        }
        foreach ($ips as $ip) {
            if (! $this->publicIp($ip)) {
                throw new InvalidArgumentException('DNS contains a non-public address.');
            }
        }
        return $ips;
    }

    public function checkUrl(string $url): void
    {
        $this->url($url);
        $this->resolve(trim(parse_url($url, PHP_URL_HOST), '[]'));
    }

    private function publicIp(string $ip): bool
    {
        // Reject mapped/transition IPv6 and special-use IPv4, including shared address space.
        if (str_contains($ip, ':')) {
            $binary = inet_pton($ip);
            return $binary !== false && (ord($binary[0]) & 0xe0) === 0x20 &&
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false &&
                substr($binary, 0, 4) !== hex2bin('20010000') && substr($binary, 0, 4) !== hex2bin('20010db8') &&
                substr($binary, 0, 2) !== hex2bin('2002');
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false &&
            ! preg_match('/^(?:0\.|100\.(?:6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|169\.254\.|192\.0\.0\.|192\.0\.2\.|198\.(?:1[89]|51)\.|203\.0\.113\.|2[2-5][0-9]\.)/', $ip);
    }
}
