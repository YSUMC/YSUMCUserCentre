<?php

use App\Services\Hook;
use Blessing\Filter;
use Blessing\OAuthCore\AuthController;
use Blessing\OAuthCore\Providers;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

return function (Filter $filter) {
    app()->register(SocialiteProviders\Manager\ServiceProvider::class);
    View::composer('Blessing\OAuthCore::providers', function ($view) use ($filter) {
        $view->with('providers', Providers::available($filter));
    });
    $filter->add('auth_page_rows:login', function ($rows) {
        array_splice($rows, max(0, count($rows) - 1), 0, ['Blessing\OAuthCore::providers']);
        return $rows;
    });
    $filter->add('auth_page_rows:register', function ($rows) {
        $rows[] = 'Blessing\OAuthCore::providers';
        return $rows;
    });
    Hook::addRoute(function () {
        Route::prefix('auth/login')->middleware(['web', 'guest'])->group(function () {
            Route::get('{driver}', [AuthController::class, 'login'])->where('driver', '[a-z][a-z0-9_-]{0,63}');
            Route::get('{driver}/callback', [AuthController::class, 'callback'])->where('driver', '[a-z][a-z0-9_-]{0,63}');
        });
    });
};
