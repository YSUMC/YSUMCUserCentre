<?php

use App\Services\Hook;
use Blessing\Filter;
use Composer\CaBundle\CaBundle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

return function (Filter $filter) {
    if (!(config('auth-cas.host') && config('auth-cas.service'))) {
        return;
    }
    // Console commands such as config:cache must not start a browser session.
    if (!app()->runningInConsole()) {
        phpCAS::setVerbose((bool) config('app.debug'));
        phpCAS::client(CAS_VERSION_2_0, config('auth-cas.host'), (int) config('auth-cas.port', 443), config('auth-cas.context', '/cas'), config('auth-cas.service'));
        phpCAS::setCasServerCACert(config('auth-cas.ca_bundle') ?: CaBundle::getSystemCaRootBundlePath());
        if (config('auth-cas.service_validate_url')) {
            phpCAS::setServerServiceValidateURL(config('auth-cas.service_validate_url'));
        }
    }

    Hook::addRoute(function () {
        $routes = Route::getRoutes()->getRoutes();
        array_walk($routes, function ($route) {
            $route->middleware(AuthCAS\CheckCAS::class);
        });
        $routes = array_filter($routes, function ($route) {
            return $route->uri() === '/';
        });
        array_walk($routes, function ($route) {
            $route->middleware(AuthCAS\LogoutCAS::class);
        });
        $routes = Route::getRoutes()->getRoutes();
        $routes = array_filter($routes, function ($route) {
            return $route->uri() === 'auth/login';
        });
        array_walk($routes, function ($route) {
            $route->middleware(AuthCAS\LoginCAS::class);
        });
    });

    Hook::addRoute(function () {
        Route::namespace('AuthCAS')
            ->prefix('auth/cas')
            ->middleware('web')
            ->group(function () {
                Route::get('/reset', 'AuthController@reset');
                Route::get('/logout', 'AuthController@logout');
            });
        // CAS back-channel logout is sent by the identity provider without Laravel CSRF.
        Route::post('auth/cas', '\\AuthCAS\\AuthController@singleLogout');
        Route::namespace('AuthCAS')
            ->prefix('auth/cas')
            ->middleware(['web', 'guest'])
            ->group(function () {
                Route::get('', 'AuthController@login');
                Route::get('/relogin', 'AuthController@reLogin');
            });
    });

    View::composer('AuthCAS::providers', function ($view) use ($filter) {
        $providers = $filter->apply('cas_providers', collect());
        $view->with('providers', $providers);
        $view->with('auth_title', option_localized('cas_title') ?? trans('AuthCAS::auth.title'));
    });

    View::composer('AuthCAS::reset_password', function ($view) {
        $view->with('text', [
            'title' => trans('AuthCAS::auth.reset.title', [
                'cas_login' => option_localized('cas_login') ?? trans('AuthCAS::auth.cas.login'),
            ]),
            'body' => trans('AuthCAS::auth.reset.body', [
                'cas_title' => option_localized('cas_title') ?? trans('AuthCAS::auth.title'),
            ]),
        ]);
    });

    $filter->add('grid:user.profile', function ($grid) {
        array_splice($grid['widgets'][0][1], 0, 0, ['AuthCAS::reset_password']);
        return $grid;
    });

    $filter->add('auth_page_rows:login', function ($rows) {
        $length = count($rows);
        array_splice($rows, $length - 1, 0, ['AuthCAS::providers']);
        return $rows;
    });

    $filter->add('auth_page_rows:register', function ($rows) {
        $rows[] = 'AuthCAS::providers';
        return $rows;
    });

    $filter->add('auth_page_rows:register_confirm', function ($rows) {
        $length = count($rows);
        array_splice($rows, 0, 0, ['AuthCAS::providers']);
        return $rows;
    });

    $filter->add('cas_providers', function (Collection $providers) {
        if (phpCAS::isAuthenticated()) {
            $providers->put('cas', [
                'icon' => 'user fas',
                'displayName' => trans('AuthCAS::auth.cas.login_with', [
                    'username' => phpCAS::getUser()
                ]),
            ]);
            $providers->put('cas/relogin', [
                'icon' => 'sync-alt fas',
                'displayName' => trans('AuthCAS::auth.cas.switch'),
            ]);
            $providers->put('cas/logout', [
                'icon' => 'power-off fas',
                'displayName' => trans('AuthCAS::auth.cas.logout'),
            ]);
        } else {
            $providers->put('cas', [
                'icon' => 'sign-in-alt fas',
                'displayName' => option_localized('cas_login') ?? trans('AuthCAS::auth.cas.login'),
            ]);
        }
        return $providers;
    });
};
