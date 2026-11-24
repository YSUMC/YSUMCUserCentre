<?php

namespace Blessing\HideAdvancedMenu;

use Blessing\Filter;
use App\Services\Hook;
use App\Services\Plugin;
use Illuminate\Routing\Router;

return function (Filter $filter, Plugin $plugin) {
    CreationAudit::boot();

    $filter->add('side_menu', function ($menu, $type) {
        if ($type !== 'user') {
            return $menu;
        }

        return array_filter($menu, function ($item) {
            return $item['title'] !== 'general.developer';
        });
    });

    Hook::addMenuItem('admin', 99, [
        'title' => 'general.developer',
        'icon' => 'fa-code-branch',
        'children' => [[
            'title' => 'general.oauth-manage',
            'icon' => 'fa-feather-alt',
            'link' => ConfigController::PATH,
        ]],
    ]);
    Hook::addScriptFileToPage($plugin->assets('oauth-apps.js').'&revision=20261124', [ConfigController::PATH]);
    Hook::addRoute(function (Router $router) {
        $router->post(ConfigController::PATH.'/{client}/secret', [ConfigController::class, 'secret'])
            ->whereNumber('client')
            ->middleware(['web', 'authorize', 'role:admin']);
    });
};
