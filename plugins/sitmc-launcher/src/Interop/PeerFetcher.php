<?php

namespace SITMinecraft\Launcher\Interop;

/** Bounded v1-only conditional fetch; PeerTransport pins validated public DNS. */
final class PeerFetcher
{
    private PeerTransport $transport;

    public function __construct(
        private int $timeoutSeconds = 15,
        private int $attempts = 4,
        private int $maxBytes = InteropRules::MAX_PEER_BYTES,
        ?PeerTransport $transport = null,
    ) {
        $this->transport = $transport ?? new PeerTransport(new PublicAddress(), $this->timeoutSeconds);
        $this->attempts = min(4, max(1, $attempts));
        $this->maxBytes = min(InteropRules::MAX_PEER_BYTES, max(1024, $maxBytes));
    }

    public function get(string $url, ?string $etag = null): array
    {
        if (InteropRules::publicHttpsUrl($url) === null || parse_url($url, PHP_URL_PATH) !== InteropRules::WELL_KNOWN_PATH || parse_url($url, PHP_URL_QUERY) !== null) {
            return $this->failure('peer-url-invalid');
        }
        for ($attempt = 0; $attempt < $this->attempts; ++$attempt) {
            try {
                $response = $this->transport->get($url, $etag);
                $status = $response['status'];
                if ($status === 200 || $status === 304) {
                    if (strlen($response['body']) > $this->maxBytes) return $this->failure('peer-document-too-large');
                    $validator = $response['headers']['etag'] ?? ($status === 304 ? $etag : null);
                    if (! is_string($validator) || strlen($validator) > 255 || preg_match('/[\r\n]/', $validator)) $validator = null;
                    return ['status' => $status, 'body' => $status === 304 ? null : $response['body'], 'etag' => $validator, 'error' => null];
                }
                if ($status >= 300 && $status < 400) return $this->failure('peer-redirect-invalid');
                $error = 'peer-http-'.$status;
                if ($status < 500 && $status !== 429) return $this->failure($error);
            } catch (\InvalidArgumentException) {
                return $this->failure('peer-url-invalid');
            } catch (\RuntimeException) {
                $error = 'peer-connection';
            }
            if ($attempt + 1 < $this->attempts) $this->transport->backoff($attempt);
        }
        return $this->failure($error);
    }

    private function failure(string $error): array
    {
        return ['status' => null, 'body' => null, 'etag' => null, 'error' => $error];
    }
}
