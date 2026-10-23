<?php

namespace Tests\Interop;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Database\LauncherSchema;
use SITMinecraft\Launcher\Http\Controllers\Admin\InstanceController;
use SITMinecraft\Launcher\Http\Controllers\ManifestController;
use SITMinecraft\Launcher\Http\Controllers\MutmcController;
use SITMinecraft\Launcher\Http\Controllers\PackController;
use SITMinecraft\Launcher\Interop\InteropRepository;
use SITMinecraft\Launcher\Interop\PeerAggregator;
use SITMinecraft\Launcher\Interop\PeerFetcher;
use SITMinecraft\Launcher\Interop\PublicAddress;
use SITMinecraft\Launcher\Interop\SiteDocumentService;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\PackStore;

final class MutmcDistributionTest extends \Tests\Security\TestCase
{
    private LauncherRepository $catalog;
    private InteropRepository $interop;
    private SiteDocumentService $documents;
    private PackController $packs;
    private MutmcController $mutmc;
    private InstanceController $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('app.url', 'https://skin.example.com');
        $this->catalog = new LauncherRepository;
        $this->interop = new InteropRepository;
        $this->interop->saveSettings(['site_id' => 'ysumc', 'site_domain' => 'skin.example.com']);
        $this->documents = new SiteDocumentService($this->interop, new PeerAggregator($this->interop, new PeerFetcher));
        $disk = new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem(
            new \League\Flysystem\Local\LocalFilesystemAdapter(storage_path('app'))
        ), new \League\Flysystem\Local\LocalFilesystemAdapter(storage_path('app')));
        $store = new PackStore($disk);
        $this->packs = new PackController($this->catalog, $store, $this->documents);
        $this->mutmc = new MutmcController($this->catalog, $this->packs);
        $this->admin = new InstanceController($this->catalog, $store);
        $plugin = new class {
            public function getPath(): string { return getenv('MUTMC_STAGE_PLUGIN') ?: base_path('plugins/sitmc-launcher'); }
        };
        (require $plugin->getPath().'/bootstrap.php')($plugin);
        $this->app['view']->addExtension('twig', 'php');
        $this->app['events']->dispatch(new \App\Events\ConfigureRoutes($this->app['router']));
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function instance(string $id, string $brand): void
    {
        $this->catalog->saveInstance(['instance_id' => $id, 'distribution_brand' => $brand,
            'name' => $id, 'game_version' => '1.21.1', 'revision' => 1, 'enabled' => true,
            'server_address' => 'play.example.com',
            'created_at' => now(), 'updated_at' => now()]);
        $this->catalog->savePack($id, 2, ['url' => 'https://cdn.example.com/'.$id.'.mrpack',
            'sha1' => str_repeat('a', 40), 'size' => 100, 'format' => 'mrpack']);
        $this->catalog->setRevision($id, 2);
    }

    public function testCatalogsAndPublicListingRemainSeparate(): void
    {
        $this->instance('ysu', 'ysumc');
        foreach (['mut', 'disabled', 'bad', 'zip'] as $id) $this->instance($id, 'mutmc');
        DB::table(LauncherSchema::INSTANCES)->where('instance_id', 'disabled')->update(['enabled' => false]);
        DB::table(LauncherSchema::PACKS)->where('instance_id', 'bad')->update(['sha1' => 'bad']);
        DB::table(LauncherSchema::PACKS)->where('instance_id', 'zip')->update(['format' => 'multimc']);
        $this->interop->savePublications(['ysu', 'mut']);
        $request = Request::create('https://skin.example.com/api/launcher/mutmc/manifest');
        $first = $this->mutmc->manifest($request);
        $this->assertSame(['mut'], array_column(json_decode($first->getContent(), true)['instances'], 'id'));
        $ysu = (new ManifestController($this->catalog, $this->documents))->show($request);
        $this->assertSame(['ysu'], array_column(json_decode($ysu->getContent(), true)['instances'], 'id'));
        $this->assertSame(['ysu'], array_column($this->documents->document()['document']['packs'], 'id'));
        $this->interop->savePublications([]);
        $this->assertSame($first->getContent(), $this->mutmc->manifest($request)->getContent());
        $request->headers->set('If-None-Match', '"old", W/'.$first->headers->get('ETag'));
        $this->assertSame(304, $this->mutmc->manifest($request)->getStatusCode());
    }

    public function testDownloadsRejectOtherBrandsDisabledAndHistoricalRevisions(): void
    {
        $this->instance('ysu', 'ysumc');
        $this->instance('mut', 'mutmc');
        $this->assertSame(302, $this->mutmc->pack('mut', 2)->getStatusCode());
        $this->assertSame(404, $this->mutmc->pack('ysu', 2)->getStatusCode());
        $this->assertSame(404, $this->packs->show('mut', 2)->getStatusCode());
        $this->assertSame(404, $this->mutmc->pack('mut', 1)->getStatusCode());
        DB::table(LauncherSchema::INSTANCES)->where('instance_id', 'mut')->update(['enabled' => false]);
        $this->assertSame(404, $this->mutmc->pack('mut', 2)->getStatusCode());
    }

    public function testCloudSettingsAndAdminRoutesAreBrandSpecific(): void
    {
        $this->catalog->saveSettings(['cloud_map_url' => 'https://ysu.example.com/']);
        $this->catalog->saveSettings(['cloud_map_url' => 'https://mut.example.com/'], 'mutmc');
        $request = Request::create('https://skin.example.com/admin/launcher/mutmc');
        $route = $this->app['router']->getRoutes()->getByName('sitmc-launcher.admin.mutmc');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        foreach (['web', 'authorize', 'role:admin'] as $middleware) $this->assertContains($middleware, $route->gatherMiddleware());
        $view = $this->admin->index($request)->getData();
        $this->assertSame('mutmc', $view['distributionBrand']);
        $this->assertSame('/api/launcher/mutmc/manifest', $view['manifestPath']);
        $this->assertSame('https://mut.example.com/', $view['settings']['cloud_map_url']);
        $this->assertSame('https://ysu.example.com/', $this->catalog->settings()['cloud_map_url']);
        $public = $this->app['router']->getRoutes()->getByName('sitmc-launcher.mutmc.manifest');
        $this->assertNotContains('authorize', $public->gatherMiddleware());
    }

    public function testAdminCreateEditAndExternalPackPublishUnderMutmcOnly(): void
    {
        $values = ['instance_id' => 'mut-new', 'name' => 'MUT Server', 'game_version' => '1.21.1',
            'distribution_brand' => 'mutmc', 'enabled' => true];
        $response = $this->admin->store(Request::create('/admin/launcher/instances', 'POST', $values));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('mutmc', $this->catalog->row('mut-new')['distribution_brand']);
        $this->assertSame([], $this->catalog->rows());
        $this->admin->update(Request::create('/admin/launcher/instances/mut-new', 'PUT',
            array_replace($values, ['name' => 'Edited', 'distribution_brand' => 'ysumc'])), 'mut-new');
        $this->assertSame('mutmc', $this->catalog->row('mut-new')['distribution_brand']);
        $this->assertSame('Edited', $this->catalog->row('mut-new')['name']);
        $this->app->instance(PublicAddress::class, new class extends PublicAddress {
            public function resolve(string $host): array { return ['1.1.1.1']; }
        });
        $this->admin->storeExternalPack(Request::create('/packs/external', 'POST', [
            'url' => 'https://cdn.example.com/pack.mrpack', 'sha1' => str_repeat('a', 40),
            'size' => 100, 'format' => 'mrpack',
        ]), 'mut-new');
        $data = json_decode($this->mutmc->manifest(Request::create('/manifest'))->getContent(), true);
        $this->assertSame(['mut-new'], array_column($data['instances'], 'id'));
        $this->assertSame(2, $data['instances'][0]['revision']);
    }
}
