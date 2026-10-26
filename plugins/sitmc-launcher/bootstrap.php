<?php

use App\Services\Hook;
use SITMinecraft\Launcher\Http\Controllers\MutmcController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;
use SITMinecraft\Launcher\Announcement\AnnouncementRules;
use SITMinecraft\Launcher\Auth\TokenVerifier;
use SITMinecraft\Launcher\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use SITMinecraft\Launcher\Http\Controllers\Admin\InteropController as AdminInteropController;
use SITMinecraft\Launcher\Http\Controllers\Admin\InstanceController;
use SITMinecraft\Launcher\Http\Controllers\Admin\ReleaseController;
use SITMinecraft\Launcher\Http\Controllers\AnnouncementController as PublicAnnouncementController;
use SITMinecraft\Launcher\Http\Controllers\InteropController;
use SITMinecraft\Launcher\Http\Controllers\ManifestController;
use SITMinecraft\Launcher\Http\Controllers\PackController;
use SITMinecraft\Launcher\Http\Controllers\SkinController;
use SITMinecraft\Launcher\Http\Controllers\CurseForgeController;
use SITMinecraft\Launcher\Http\Controllers\UpdateController;
use SITMinecraft\Launcher\Interop\InteropRepository;
use SITMinecraft\Launcher\Interop\InteropRules;
use SITMinecraft\Launcher\Interop\PeerAggregator;
use SITMinecraft\Launcher\Interop\PeerDiscovery;
use SITMinecraft\Launcher\Interop\PeerFetcher;
use SITMinecraft\Launcher\Interop\SiteDocumentService;
use SITMinecraft\Launcher\Manifest\PackStore;
use SITMinecraft\Launcher\Update\ReleaseRules;

/**
 * Wires the plugin into Blessing Skin without touching any core file.
 *
 * Everything the plugin needs is registered here: its own translation and view
 * namespaces, the pack storage binding, the admin menu entry, and the routes.
 * Only an enabled plugin is bootstrapped, so a disabled plugin adds nothing.
 */
return function ($plugin) {
    $path = $plugin->getPath();

    app('translator')->addNamespace('sitmc-launcher', $path.'/lang');
    app('view')->addNamespace('sitmc-launcher', $path.'/views');

    \SITMinecraft\Launcher\Access\BackendIntegration::boot();

    // Extend the existing users screen; disabling this plugin restores core UI.
    Hook::addScriptFileToPage(url('plugins/sitmc-launcher/assets/user-memberships.js').'?v=20261026-3', ['admin/users']);
    Hook::addStyleFileToPage(url('plugins/sitmc-launcher/assets/user-memberships.css').'?v=20261026-3', ['admin/users']);

    app()->singleton(PackStore::class, function () {
        return new PackStore(Storage::disk(config('sitmc-launcher.disk', 'local')));
    });

    app()->singleton(TokenVerifier::class, function () {
        return new TokenVerifier(config(
            'sitmc-launcher.userinfo_url',
            'https://skin.ysumc.net/api/janus/userinfo'
        ));
    });

    app()->alias(TokenVerifier::class, \SITMinecraft\Launcher\Auth\VerifiesPlayerToken::class);

    // mc-interop v1: storage, peer puller and the single serializer. Everything
    // is a singleton so one request builds one document and reuses one HTTP
    // client configuration.
    app()->singleton(InteropRepository::class, static fn () => new InteropRepository());

    app()->singleton(PeerFetcher::class, static fn () => new PeerFetcher(
        (int) config('sitmc-launcher.interop_timeout', 15),
        (int) config('sitmc-launcher.interop_attempts', 4),
        (int) config('sitmc-launcher.interop_max_bytes', InteropRules::MAX_PEER_BYTES)
    ));

    app()->singleton(PeerAggregator::class, static fn ($app) => new PeerAggregator(
        $app->make(InteropRepository::class),
        $app->make(PeerFetcher::class)
    ));

    app()->singleton(PeerDiscovery::class, static fn ($app) => new PeerDiscovery(
        $app->make(PeerFetcher::class)
    ));

    app()->singleton(SiteDocumentService::class, static fn ($app) => new SiteDocumentService(
        $app->make(InteropRepository::class),
        $app->make(PeerAggregator::class)
    ));

    Hook::addMenuItem('admin', 3, [
        'title' => 'sitmc-launcher::launcher.menu',
        'link' => 'admin/launcher',
        'icon' => 'fa-cube',
    ]);

    Hook::addMenuItem('admin', 3, ['title' => 'sitmc-launcher::launcher.distribution.mutmc-menu', 'link' => 'admin/launcher/mutmc', 'icon' => 'fa-trophy']);

    Hook::addRoute(function (Router $router) {
        // mc-interop v1 unified exit. Same bytes at the canonical well-known
        // path and at the plugin-visible mirror a reverse proxy can forward.
        // Nothing below the legacy `api/launcher` group changed.
        $router->group(['middleware' => [\SITMinecraft\Launcher\Http\InteropRequest::class, 'api', 'throttle:60,1']], function () use ($router) {
            $router->get('.well-known/mc-interop-v1.json', [InteropController::class, 'site'])
                ->name('sitmc-launcher.interop.well-known');
        });

        $router->group(
            ['middleware' => [\SITMinecraft\Launcher\Http\InteropRequest::class, 'api', 'throttle:60,1'], 'prefix' => 'api/launcher/interop/v1'],
            function () use ($router) {
                $router->get('site', [InteropController::class, 'site'])
                    ->name('sitmc-launcher.interop.site');

                $router->get('friends', [InteropController::class, 'friends'])
                    ->name('sitmc-launcher.interop.friends');

                $router->post('refresh', [InteropController::class, 'refresh'])
                    ->name('sitmc-launcher.interop.refresh');
            }
        );

        $router->group(
            ['middleware' => ['api', 'throttle:60,1'], 'prefix' => 'api/launcher'],
            function () use ($router) {
                $router->get('identity', \SITMinecraft\Launcher\Http\Controllers\IdentityController::class);
                $router->group(['prefix' => 'mutmc'], function () use ($router) {
                    $router->match(['GET', 'POST'], 'curseforge/{path}', [CurseForgeController::class, 'mutmc'])
                        ->where('path', 'v1/[A-Za-z0-9/-]+')->middleware('throttle:30,1');
                    $router->get('announcements', [PublicAnnouncementController::class, 'index'])->defaults('distributionBrand', 'mutmc');
                    $router->get('update/latest', [UpdateController::class, 'latest'])->defaults('distributionBrand', 'mutmc');
                    $router->get('update/versions', [UpdateController::class, 'versions'])->defaults('distributionBrand', 'mutmc');
                    $router->get('update/releases', [UpdateController::class, 'releases'])->defaults('distributionBrand', 'mutmc');
                    $router->get('update/{target}/{arch}/{currentVersion}', [UpdateController::class, 'dynamic'])
                        ->where(['target' => ReleaseRules::PLATFORM_PATTERN, 'arch' => ReleaseRules::PLATFORM_PATTERN, 'currentVersion' => '[0-9A-Za-z._+-]+'])
                        ->defaults('distributionBrand', 'mutmc');
                });

                $router->get('mutmc/manifest', [MutmcController::class, 'manifest'])->name('sitmc-launcher.mutmc.manifest');
                $router->get('mutmc/packs/{instanceId}/{revision}', [MutmcController::class, 'pack'])
                    ->where(['instanceId' => '[A-Za-z0-9._-]{1,128}', 'revision' => '[0-9]+'])
                    ->name('sitmc-launcher.mutmc.pack');

                $router->get('manifest', [ManifestController::class, 'show'])
                    ->name('sitmc-launcher.manifest');

                $router->match(['GET', 'POST'], 'curseforge/{path}', [CurseForgeController::class, 'proxy'])
                    ->where('path', 'v1/[A-Za-z0-9/-]+')
                    ->name('sitmc-launcher.curseforge');

                $router->get('friends', [InteropController::class, 'friends'])
                    ->name('sitmc-launcher.friends');

                $router->get('packs/{instanceId}/{revision}', [PackController::class, 'show'])
                    ->where(['instanceId' => '[A-Za-z0-9._-]{1,128}', 'revision' => '[0-9]+'])
                    ->name('sitmc-launcher.pack');

                $router->get('announcements', [PublicAnnouncementController::class, 'index'])
                    ->name('sitmc-launcher.announcements');

                $router->get('update/latest', [UpdateController::class, 'latest'])
                    ->name('sitmc-launcher.update.latest');

                $router->get('update/versions', [UpdateController::class, 'versions'])
                    ->name('sitmc-launcher.update.versions');

                $router->get('update/releases', [UpdateController::class, 'releases'])
                    ->name('sitmc-launcher.update.releases');

                $router->get('update/{target}/{arch}/{currentVersion}', [UpdateController::class, 'dynamic'])
                    ->where([
                        'target' => ReleaseRules::PLATFORM_PATTERN,
                        'arch' => ReleaseRules::PLATFORM_PATTERN,
                        'currentVersion' => '[0-9A-Za-z._+-]+',
                    ])
                    ->name('sitmc-launcher.update.dynamic');

                // Skin management for the launcher. These authenticate the
                // player's own launcher session instead of a Passport token, so
                // they throttle a little tighter than the read-only endpoints.
                $router->group(['middleware' => ['throttle:30,1']], function () use ($router) {
                    $router->get('skins', [SkinController::class, 'index'])
                        ->name('sitmc-launcher.skins.index');

                    $router->post('skins', [SkinController::class, 'upload'])
                        ->name('sitmc-launcher.skins.upload');

                    $router->put('skins/equip', [SkinController::class, 'equip'])
                        ->name('sitmc-launcher.skins.equip');

                    $router->delete('skins', [SkinController::class, 'destroy'])
                        ->name('sitmc-launcher.skins.destroy');
                });
            }
        );

        $router->group(
            ['middleware' => ['web', 'authorize', 'role:admin'], 'prefix' => 'admin/launcher'],
            function () use ($router) {
                foreach (['ysumc', 'mutmc'] as $brand) {
                    $router->group(['prefix' => $brand === 'mutmc' ? 'mutmc' : ''], function () use ($router, $brand) {
                        $names = 'sitmc-launcher.admin.'.($brand === 'mutmc' ? 'mutmc.' : '');
                        $router->get('', [InstanceController::class, 'index'])
                            ->defaults('distributionBrand', $brand)
                            ->name($brand === 'mutmc' ? 'sitmc-launcher.admin.mutmc' : $names.'index');

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
                }

                $router->get('users', [\SITMinecraft\Launcher\Http\Controllers\Admin\MembershipController::class, 'index'])->name('sitmc-launcher.admin.memberships');
                $router->get('users/data', [\SITMinecraft\Launcher\Http\Controllers\Admin\MembershipController::class, 'data'])->name('sitmc-launcher.admin.memberships.data');
                $router->put('users/{uid}', [\SITMinecraft\Launcher\Http\Controllers\Admin\MembershipController::class, 'update'])->where('uid', '[0-9]+')->name('sitmc-launcher.admin.memberships.update');

                // YSUMC interop remains outside the MUTMCL administration area.
                $router->get('interop', [AdminInteropController::class, 'index'])
                    ->name('sitmc-launcher.admin.interop.index');

                $router->post('interop/settings', [AdminInteropController::class, 'saveSettings'])
                    ->name('sitmc-launcher.admin.interop.settings');

                $router->get('interop/peers/create', [AdminInteropController::class, 'createPeer'])
                    ->name('sitmc-launcher.admin.interop.peers.create');

                $router->post('interop/peers', [AdminInteropController::class, 'storePeer'])
                    ->name('sitmc-launcher.admin.interop.peers.store');

                $router->post('interop/peers/sync', [AdminInteropController::class, 'syncPeers'])
                    ->name('sitmc-launcher.admin.interop.peers.sync-all');

                $router->get('interop/peers/{siteId}/edit', [AdminInteropController::class, 'editPeer'])
                    ->where('siteId', '[A-Za-z0-9._-]{1,128}')
                    ->name('sitmc-launcher.admin.interop.peers.edit');

                $router->put('interop/peers/{siteId}', [AdminInteropController::class, 'updatePeer'])
                    ->where('siteId', '[A-Za-z0-9._-]{1,128}')
                    ->name('sitmc-launcher.admin.interop.peers.update');

                $router->delete('interop/peers/{siteId}', [AdminInteropController::class, 'destroyPeer'])
                    ->where('siteId', '[A-Za-z0-9._-]{1,128}')
                    ->name('sitmc-launcher.admin.interop.peers.destroy');

                $router->post('interop/peers/{siteId}/sync', [AdminInteropController::class, 'syncPeer'])
                    ->where('siteId', '[A-Za-z0-9._-]{1,128}')
                    ->name('sitmc-launcher.admin.interop.peers.sync');

                $router->post('interop/publications', [AdminInteropController::class, 'savePublications'])
                    ->name('sitmc-launcher.admin.interop.publications');

                $router->post('interop/sync-token', [AdminInteropController::class, 'rotateSyncToken'])
                    ->name('sitmc-launcher.admin.interop.sync-token');
            }
        );
    });
};
