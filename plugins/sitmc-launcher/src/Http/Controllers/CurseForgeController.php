<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use SITMinecraft\Launcher\Auth\TokenVerifier;
use SITMinecraft\Launcher\Http\CurseForgeRequest;

final class CurseForgeController
{
    public function proxy(Request $request, string $path): Response
    {
        $token = $request->bearerToken();
        if (!$token || strlen($token) > 8192 || app(TokenVerifier::class)->verifiedPlayer($token) === null) {
            return $this->failure(401, 'Please sign in to YSUMC again.');
        }
        return $this->forward($request, $path);
    }

    /** Public Minecraft catalog only; never send Microsoft credentials to this service. */
    public function mutmc(Request $request, string $path): Response
    {
        return $this->forward($request, $path);
    }

    private function forward(Request $request, string $path): Response
    {
        $body = $request->isMethod('POST') ? $request->json()->all() : null;
        if (strlen($request->getContent()) > 65536
            || !CurseForgeRequest::allowed($request->method(), $path, $request->query(), $body)) {
            return $this->failure(400, 'Unsupported CurseForge request.');
        }
        $key = trim((string) config('sitmc-launcher.curseforge_api_key', ''));
        if ($key === '') {
            return $this->failure(503, 'CurseForge is temporarily unavailable.');
        }
        $query = CurseForgeRequest::query($request->query());
        if (in_array($path, ['v1/categories', 'v1/mods/search'], true)) {
            $query['gameId'] = 432;
        }
        ksort($query);
        $cacheKey = 'launcher-cf:'.hash('sha256', $request->method().$path.json_encode([$query, $body]));
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $this->success($cached);
        }
        // Neither the request host nor authentication headers can be supplied by the client.
        $ch = curl_init('https://api.curseforge.com/'.$path.($query ? '?'.http_build_query($query) : ''));
        $responseBody = '';
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['x-api-key: '.$key, 'Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > 8 * 1024 * 1024) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $ok = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        // Do not expose upstream errors, headers or request credentials in public responses.
        if ($ok === false || $status !== 200 || !is_array(json_decode($responseBody, true))
            || str_contains($responseBody, $key)) {
            return $this->failure($status === 429 ? 429 : 502, 'CurseForge is temporarily unavailable. Please try again later.');
        }
        Cache::put($cacheKey, $responseBody, $path === 'v1/categories' ? 300 : 60);
        return $this->success($responseBody);
    }

    private function success(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
    }

    private function failure(int $status, string $message): Response
    {
        return response(json_encode(['message' => $message]), $status,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
