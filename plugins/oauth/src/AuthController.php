<?php

namespace Blessing\OAuthCore;

use App\Events\UserLoggedIn;
use App\Events\UserRegistered;
use App\Models\User;
use Blessing\Filter;
use Blessing\Rejection;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Two\InvalidStateException;
use Vectorface\Whip\Whip;

class AuthController extends Controller
{
    public function login(Filter $filter, Factory $socialite, string $driver)
    {
        try {
            return Providers::resolve($filter, $socialite, $driver)->redirect();
        } catch (GuzzleException $error) {
            abort(502, '暂时无法联系第三方登录平台，请稍后重试。');
        }
    }

    public function callback(Request $request, Dispatcher $dispatcher, Filter $filter, Factory $socialite, string $driver)
    {
        $provider = Providers::resolve($filter, $socialite, $driver);
        if ($request->has('error')) {
            $request->session()->forget('state');
            abort(400, '第三方授权未完成，请返回登录页重试。');
        }
        try {
            // Keep Socialite's state validation and single-use state consumption enabled.
            $remoteUser = $provider->user();
        } catch (InvalidStateException|\Laravel\Socialite\One\MissingTemporaryCredentialsException|\Laravel\Socialite\One\MissingVerifierException $error) {
            abort(419, '授权会话已过期，请从登录页重新发起授权。');
        } catch (GuzzleException $error) {
            abort(502, '暂时无法联系第三方登录平台，请稍后重试。');
        }
        $email = VerifiedEmail::read($remoteUser, $provider, $driver, $filter);
        $can = $filter->apply('can_login', null, [$email, '']);
        abort_if($can instanceof Rejection, 403, $can instanceof Rejection ? $can->getReason() : '');

        $user = User::where('email', $email)->first();
        if (!$user) {
            // Registration filters must receive the verified provider email, never callback input.
            $request->merge(['email' => $email]);
            $can = $filter->apply('can_register', null);
            abort_if($can instanceof Rejection, 403, $can instanceof Rejection ? $can->getReason() : '');
            abort_if(option('register_with_player_name'), 403, '请先完成本站注册，再使用第三方登录。');
            $ip = $filter->apply('client_ip', (new Whip)->getValidIpAddress());
            abort_if(User::where('ip', $ip)->count() >= (int) option('regs_per_ip'), 403, '此地址注册数量已达上限。');
            $nickname = $remoteUser->getNickname() ?: $remoteUser->getName() ?: $email;
            $nickname = is_string($nickname) ? mb_substr($nickname, 0, 255) : $email;
            $user = DB::transaction(function () use ($dispatcher, $email, $nickname, $ip) {
                $data = compact('email', 'nickname');
                $dispatcher->dispatch('auth.registration.attempt', [$data]);
                $dispatcher->dispatch('auth.registration.ready', [$data]);
                $user = new User;
                $user->email = $email;
                $user->nickname = $nickname;
                $user->score = option('user_initial_score');
                $user->avatar = 0;
                $user->password = '';
                $user->ip = $ip;
                $user->permission = User::NORMAL;
                $user->register_at = now();
                $user->last_sign_at = now()->subDay();
                $user->verified = true;
                $user->save();
                $dispatcher->dispatch('auth.registration.completed', [$user]);
                event(new UserRegistered($user));
                return $user;
            });
        }
        abort_if($user->permission < User::NORMAL, 403, '此账号当前不能登录。');
        $dispatcher->dispatch('auth.login.ready', [$user]);
        Auth::login($user);
        $request->session()->regenerate();
        $dispatcher->dispatch('auth.login.succeeded', [$user]);
        event(new UserLoggedIn($user));

        $target = $request->session()->pull('last_requested_path', '/user');
        if (!is_string($target) || preg_match('/[\\\\\x00-\x20]/', $target)) $target = '/user';
        if (str_starts_with($target, $request->getSchemeAndHttpHost().'/')) $target = substr($target, strlen($request->getSchemeAndHttpHost()));
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) $target = '/user';
        return redirect($target);
    }
}
