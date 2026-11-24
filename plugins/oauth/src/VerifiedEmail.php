<?php

namespace Blessing\OAuthCore;

use Blessing\Filter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Two\GithubProvider;

final class VerifiedEmail
{
    public static function read(User $user, Provider $provider, string $driver, Filter $filter): string
    {
        $email = $user->getEmail();
        abort_unless(is_string($email) && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL), 422,
            '第三方账号未提供有效邮箱，请先在登录平台设置并验证邮箱。');
        $raw = method_exists($user, 'getRaw') ? $user->getRaw() : [];
        $verified = is_array($raw) && (($raw['email_verified'] ?? null) === true || ($raw['verified_email'] ?? null) === true);
        // Socialite's GitHub user:email lookup returns only the verified primary email.
        if ($provider instanceof GithubProvider && in_array('user:email', $provider->getScopes(), true)) $verified = true;
        $verified = $filter->apply('oauth_email_verified', $verified, [$driver, $user, $provider]);
        abort_unless($verified === true, 403, '第三方平台未确认邮箱已验证，不能用该邮箱登录本站。');
        return $email;
    }
}
