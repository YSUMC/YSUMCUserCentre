<?php

namespace RequirePassword;

use App\Models\User;
use App\Services\PasswordResetTokens;
use Closure;
use Illuminate\Http\Request;
use Auth;
use URL;
use Carbon\Carbon;

class RequirePassword
{
    public function handle(Request $request, Closure $next)
    {
        /** @var User */
        $user = $request->user();
        $password = $user->password;
        if (empty($password)) {
            Auth::logout();
            $url = URL::temporarySignedRoute(
                'auth.reset',
                Carbon::now()->addHour(),
                ['uid' => $user->uid, 'token' => app(PasswordResetTokens::class)->issue($user)],
                false
            );
            return redirect($url);
        } else {
            return $next($request);
        }
    }
}

