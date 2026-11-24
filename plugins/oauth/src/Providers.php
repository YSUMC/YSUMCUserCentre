<?php

namespace Blessing\OAuthCore;

use Blessing\Filter;
use Illuminate\Support\Collection;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Contracts\Provider;

final class Providers
{
    public static function available(Filter $filter): Collection
    {
        return collect($filter->apply('oauth_providers', collect()))
            ->filter(fn ($value, $key) => is_string($key) && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key));
    }

    public static function resolve(Filter $filter, Factory $socialite, string $driver): Provider
    {
        abort_unless(self::available($filter)->has($driver), 404, '此第三方登录尚未启用。');
        try {
            return $socialite->driver($driver);
        } catch (\InvalidArgumentException|\TypeError|\SocialiteProviders\Manager\Exception\MissingConfigException $error) {
            // Configuration failures must not expose client secrets or upstream responses.
            abort(503, '第三方登录配置不完整，请联系管理员。');
        }
    }
}
