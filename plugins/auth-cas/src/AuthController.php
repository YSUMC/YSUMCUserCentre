<?php

namespace AuthCAS;

use App\Models\User;
use Blessing\Filter;
use Carbon\Carbon;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Vectorface\Whip\Whip;
use phpCAS;

class AuthController extends Controller
{
    public function login(Dispatcher $dispatcher, Filter $filter)
    {
        phpCAS::forceAuthentication();
        $id = phpCAS::getUser();
        $email = $id . '@' . (config('auth-cas.store_host') ?: config('auth-cas.host'));
        $user = User::where('email', $email)->first();
        if (!$user) {
            $whip = new Whip();
            $ip = $whip->getValidIpAddress();
            $ip = $filter->apply('client_ip', $ip);

            $user = new User();
            $user->email = $email;
            $user->nickname = $id;
            $user->score = option('user_initial_score');
            $user->avatar = 0;
            $user->password = '';
            $user->ip = $ip;
            $user->permission = User::NORMAL;
            $user->register_at = Carbon::now();
            $user->last_sign_at = Carbon::now()->subDay();
            $user->verified = true;

            $user->save();
            //prevent email from being sent
            //$dispatcher->dispatch('auth.registration.completed', [$user]);
        }
        if (!$user->verified) {
            $user->verified = true;
            $user->save();
        }

        $dispatcher->dispatch('auth.login.ready', [$user]);
        Auth::login($user);
        $dispatcher->dispatch('auth.login.succeeded', [$user]);
        session(['cas_login_name' => $id]);
        return redirect('/user');
    }

    public function logout()
    {
        session()->forget('cas_login_name');
        phpCAS::logout();
    }

    public function reLogin()
    {
        phpCAS::logoutWithRedirectService(rtrim(config('auth-cas.service'), '/') . '/auth/cas');
    }

    public function singleLogout()
    {
        phpCAS::handleLogoutRequests(false);
        return json('OK', 0);
    }

    public function reset()
    {
        phpCAS::forceAuthentication();
        $id = phpCAS::getUser();
        $email = $id . '@' . (config('auth-cas.store_host') ?: config('auth-cas.host'));
        $user = User::where('email', $email)->first();
        if ($user) {
            $user->password = '';
            $user->save();
        }
        return redirect('/auth/cas');
    }
}
