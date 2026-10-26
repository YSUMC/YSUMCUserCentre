<?php

use App\Services\Hook;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;
use MUTMC\Launcher\Auth\TokenVerifier;
use MUTMC\Launcher\Manifest\PackStore;
use MUTMC\Launcher\Announcement\AnnouncementRules;
use MUTMC\Launcher\Update\ReleaseRules;
use MUTMC\Launcher\Http\Controllers\MutmcController;
use MUTMC\Launcher\Http\Controllers\CurseForgeController;
use MUTMC\Launcher\Http\Controllers\AnnouncementController;
use MUTMC\Launcher\Http\Controllers\UpdateController;
use MUTMC\Launcher\Http\Controllers\Admin\InstanceController;
use MUTMC\Launcher\Http\Controllers\Admin\ReleaseController;
use MUTMC\Launcher\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;

return function ($plugin) {
    app('translator')->addNamespace('mutmc-launcher', $plugin->getPath().'/lang');
    app('view')->addNamespace('mutmc-launcher', $plugin->getPath().'/views');
    // Existing site config is a migration fallback only; MUTMC_* keys are independent.
    config(['mutmc-launcher' => array_replace([
        'disk' => config('sitmc-launcher.disk', 'local'),
        'userinfo_url' => config('sitmc-launcher.userinfo_url', url('api/janus/userinfo')),
        'curseforge_api_key' => config('sitmc-launcher.curseforge_api_key', ''),
    ], config('mutmc-launcher', []))]);
    app()->singleton(PackStore::class, fn () => new PackStore(Storage::disk(config('mutmc-launcher.disk', 'local'))));
    app()->singleton(TokenVerifier::class, fn () => new TokenVerifier(config('mutmc-launcher.userinfo_url')));
    app()->alias(TokenVerifier::class, \MUTMC\Launcher\Auth\VerifiesPlayerToken::class);
    Hook::addMenuItem('admin', 3, ['title' => 'MUTMC 启动器', 'link' => 'admin/launcher/mutmc', 'icon' => 'fa-trophy']);
    Hook::addRoute(function (Router $router) {
        $router->group(['middleware' => ['api', 'throttle:60,1'], 'prefix' => 'api/launcher/mutmc'], function () use ($router) {
            $router->get('manifest', [MutmcController::class, 'manifest'])->name('mutmc-launcher.manifest');
            $router->get('packs/{instanceId}/{revision}', [MutmcController::class, 'pack'])
                ->where(['instanceId' => '[A-Za-z0-9._-]{1,128}', 'revision' => '[0-9]+'])->name('mutmc-launcher.pack');
            $router->match(['GET', 'POST'], 'curseforge/{path}', [CurseForgeController::class, 'mutmc'])
                ->where('path', 'v1/[A-Za-z0-9/-]+')->middleware('throttle:30,1');
            $router->get('announcements', [AnnouncementController::class, 'index'])->defaults('distributionBrand', 'mutmc');
            foreach (['latest', 'versions', 'releases'] as $action) {
                $router->get('update/'.$action, [UpdateController::class, $action])->defaults('distributionBrand', 'mutmc');
            }
            $router->get('update/{target}/{arch}/{currentVersion}', [UpdateController::class, 'dynamic'])
                ->where(['target' => ReleaseRules::PLATFORM_PATTERN, 'arch' => ReleaseRules::PLATFORM_PATTERN, 'currentVersion' => '[0-9A-Za-z._+-]+'])
                ->defaults('distributionBrand', 'mutmc');
        });
        $router->group(['middleware' => ['web', 'authorize', 'role:admin'], 'prefix' => 'admin/launcher/mutmc'], function () use ($router) {
            $brand = 'mutmc';
            $names = 'mutmc-launcher.admin.';
            $router->get('', [InstanceController::class, 'index'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'index');

            $router->post('settings', [InstanceController::class, 'saveSettings'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'settings');

            $router->get('instances/create', [InstanceController::class, 'create'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'create');

            $router->post('instances', [InstanceController::class, 'store'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'store');

            $router->get('instances/{instanceId}/edit', [InstanceController::class, 'edit'])
                ->where('instanceId', '[A-Za-z0-9._-]{1,128}')
                ->defaults('distributionBrand', $brand)
                ->name($names.'edit');

            $router->put('instances/{instanceId}', [InstanceController::class, 'update'])
                ->where('instanceId', '[A-Za-z0-9._-]{1,128}')
                ->defaults('distributionBrand', $brand)
                ->name($names.'update');

            $router->delete('instances/{instanceId}', [InstanceController::class, 'destroy'])
                ->where('instanceId', '[A-Za-z0-9._-]{1,128}')
                ->defaults('distributionBrand', $brand)
                ->name($names.'destroy');

            $router->post('instances/{instanceId}/packs', [InstanceController::class, 'storePack'])
                ->where('instanceId', '[A-Za-z0-9._-]{1,128}')
                ->defaults('distributionBrand', $brand)
                ->name($names.'packs.store');

            $router->post(
                'instances/{instanceId}/packs/external',
                [InstanceController::class, 'storeExternalPack']
            )
                ->where('instanceId', '[A-Za-z0-9._-]{1,128}')
                ->defaults('distributionBrand', $brand)
                ->name($names.'packs.external');

            $router->post(
                'instances/{instanceId}/packs/{revision}/activate',
                [InstanceController::class, 'activatePack']
            )
                ->where(['instanceId' => '[A-Za-z0-9._-]{1,128}', 'revision' => '[0-9]+'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'packs.activate');

            $router->delete(
                'instances/{instanceId}/packs/{revision}',
                [InstanceController::class, 'destroyPack']
            )
                ->where(['instanceId' => '[A-Za-z0-9._-]{1,128}', 'revision' => '[0-9]+'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'packs.destroy');

            $router->get('announcements', [AdminAnnouncementController::class, 'index'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'announcements.index');

            $router->get('announcements/create', [AdminAnnouncementController::class, 'create'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'announcements.create');

            $router->post('announcements', [AdminAnnouncementController::class, 'store'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'announcements.store');

            $router->get('announcements/{announcementId}/edit', [
                AdminAnnouncementController::class, 'edit',
            ])
                ->where('announcementId', AnnouncementRules::ID_PATTERN)
                ->defaults('distributionBrand', $brand)
                ->name($names.'announcements.edit');

            $router->put('announcements/{announcementId}', [
                AdminAnnouncementController::class, 'update',
            ])
                ->where('announcementId', AnnouncementRules::ID_PATTERN)
                ->defaults('distributionBrand', $brand)
                ->name($names.'announcements.update');

            $router->delete('announcements/{announcementId}', [
                AdminAnnouncementController::class, 'destroy',
            ])
                ->where('announcementId', AnnouncementRules::ID_PATTERN)
                ->defaults('distributionBrand', $brand)
                ->name($names.'announcements.destroy');

            $router->get('releases', [ReleaseController::class, 'index'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'releases.index');

            $router->get('releases/create', [ReleaseController::class, 'create'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'releases.create');

            $router->post('releases', [ReleaseController::class, 'store'])
                ->defaults('distributionBrand', $brand)
                ->name($names.'releases.store');

            $router->get('releases/{id}/edit', [ReleaseController::class, 'edit'])
                ->where('id', '[0-9]+')
                ->defaults('distributionBrand', $brand)
                ->name($names.'releases.edit');

            $router->put('releases/{id}', [ReleaseController::class, 'update'])
                ->where('id', '[0-9]+')
                ->defaults('distributionBrand', $brand)
                ->name($names.'releases.update');

            $router->delete('releases/{id}', [ReleaseController::class, 'destroy'])
                ->where('id', '[0-9]+')
                ->defaults('distributionBrand', $brand)
                ->name($names.'releases.destroy');
        });
    });
};
