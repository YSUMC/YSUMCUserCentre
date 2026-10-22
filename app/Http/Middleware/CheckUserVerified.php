<?php

namespace App\Http\Middleware;

class CheckUserVerified
{
    public function handle($request, \Closure $next)
    {
        // Passport's GET authorization endpoint allows guests to reach its login prompt.
        $user = $request->user();
        abort_if($user && option('require_verification') && !$user->verified, 403, trans('auth.check.verified'));

        return $next($request);
    }
}
