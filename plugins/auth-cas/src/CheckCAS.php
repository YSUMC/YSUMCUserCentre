<?php

namespace AuthCAS;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use phpCAS;

class CheckCAS
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $cas_login_name = session()->get('cas_login_name');
            if ($cas_login_name) {
                if (!phpCAS::isAuthenticated() || phpCAS::getUser() != $cas_login_name) {
                    session()->forget('cas_login_name');
                    Auth::logout();
                    session(['msg' => trans('AuthCAS::auth.message.single_logout', [
                        'cas_title' => option_localized('cas_title') ?? trans('AuthCAS::auth.title'),
                    ])]);
                    return redirect('/auth/login');
                }
            }
        } else {
            session()->forget('cas_login_name');
        }
        return $next($request);
    }
}
