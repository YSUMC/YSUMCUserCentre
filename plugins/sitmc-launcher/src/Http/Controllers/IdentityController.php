<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SITMinecraft\Launcher\Access\Audience;

final class IdentityController
{
    public function __invoke(Request $request, Audience $audience): JsonResponse
    {
        return response()->json($audience->identity($request, true), 200, Audience::headers());
    }
}
