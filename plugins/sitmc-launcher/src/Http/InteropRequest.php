<?php

namespace SITMinecraft\Launcher\Http;

use Closure;
use Illuminate\Http\Request;

/** Versioned interop endpoints always use JSON errors and require HTTPS. */
final class InteropRequest
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        if (! $request->isSecure()) {
            return response('{"message":"Use HTTPS for mc-interop v1."}', 426, [
                'Content-Type' => 'application/json', 'Cache-Control' => 'no-store',
            ]);
        }
        return $next($request);
    }
}
