<?php

namespace AuthCAS;

use Closure;
use Illuminate\Http\Request;
use phpCAS;

class LoginCAS
{
    public function handle(Request $request, Closure $next)
    {
        if (phpCAS::isAuthenticated()) {
            return redirect('/auth/cas');
        }
        return $next($request);
    }
}
