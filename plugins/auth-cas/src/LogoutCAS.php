<?php

namespace AuthCAS;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use phpCAS;

class LogoutCAS
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            if (phpCAS::isAuthenticated()) {
                phpCAS::logout();
            }
        }
        return $next($request);
    }
}
