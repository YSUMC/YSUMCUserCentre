<?php

namespace SITMinecraft\Launcher\Access;

use App\Events\RenderingHeader;
use App\Http\Middleware\CheckRole;
use App\Services\Hook;
use Blessing\Filter;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use SITMinecraft\Launcher\Http\BackendGuard;
use SITMinecraft\Launcher\Http\Controllers\Admin\DelegatedPlayersController;
use SITMinecraft\Launcher\Http\Controllers\Admin\DelegatedUsersController;
use SITMinecraft\Launcher\Http\Controllers\Admin\DelegatedReportsController;
use SITMinecraft\Launcher\Http\Controllers\Admin\MembershipController;

final class BackendIntegration
{
    public static function boot(): void
    {
        app()->singleton(BackendPolicy::class);
        app()->bind(CheckRole::class, BackendGuard::class);
        app()->bind(\App\Http\Controllers\UsersManagementController::class, DelegatedUsersController::class);
        app()->bind(\App\Http\Controllers\PlayersManagementController::class, DelegatedPlayersController::class);
        app()->bind(\App\Http\Controllers\ReportController::class, DelegatedReportsController::class);
        // Runs for cached routes too, after all plugins have registered routes.
        app('events')->listen(RouteMatched::class, function (RouteMatched $event) {
            if (BackendPolicy::backendPath($event->request->path())) $event->route->middleware(BackendGuard::class);
        });
        app(Filter::class)->add('side_menu', function ($menu, $type) {
            if (!in_array($type, ['admin', 'user'], true) || !request()->user()) return $menu;
            $policy = app(BackendPolicy::class);
            $user = request()->user();
            if ($type === 'admin') return $policy->filterMenu($menu, $user);
            if ($type === 'user' && ($landing = $policy->landing($user))) {
                $menu[] = ['title' => '后台管理', 'link' => $landing, 'icon' => 'fa-user-shield'];
            }
            return $menu;
        }, PHP_INT_MAX);
        app(Filter::class)->add('grid:admin.index', function ($grid) {
            if (!app(BackendPolicy::class)->allows(request()->user(), 'dashboard', 2)) {
                foreach ($grid['widgets'] as &$row) foreach ($row as &$column) {
                    $column = array_values(array_diff($column, ['admin.widgets.dashboard.notification']));
                }
            }
            return $grid;
        });
        Hook::addScriptFileToPage(url('plugins/sitmc-launcher/assets/backend-access.js').'?v=20261026-1', ['admin', 'admin/*', 'user', 'user/*']);
        app('events')->listen(RenderingHeader::class, function (RenderingHeader $event) {
            if (!request()->user() || !request()->is('admin', 'admin/*', 'user', 'user/*')) return;
            $policy = app(BackendPolicy::class);
            $user = request()->user();
            $modules = [];
            foreach ($policy->catalog() as $key => $module) $modules[$key] = ['path' => $module['path'], 'prefixes' => $module['prefixes'], 'level' => $policy->level($user, $key)];
            $data = ['modules' => $modules, 'super' => $user->permission >= \App\Models\User::SUPER_ADMIN,
                'readOnly' => BackendPolicy::backendPath(request()->path()) && !$policy->allows($user, $policy->capability(request()->path()), 2)];
            $event->addContent('<script id="launcher-backend-access" type="application/json">'.json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR).'</script>');
        });
        Hook::addRoute(function (Router $router) {
            $router->get('admin/users', [MembershipController::class, 'userManagement'])
                ->middleware(['web', 'authorize', 'role:admin'])->name('sitmc-launcher.admin.user-management');
        });
    }
}
