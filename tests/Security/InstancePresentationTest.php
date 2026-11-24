<?php

namespace Tests\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SITMinecraft\Launcher\Database\LauncherSchema;
use SITMinecraft\Launcher\Interop\{InteropRepository, PeerAggregator, PeerFetcher, PeerPresentation, SiteDocumentService};
use SITMinecraft\Launcher\Manifest\{InstanceTags, LauncherRepository};

final class InstancePresentationTest extends TestCase
{
    private InteropRepository $repository;
    private SiteDocumentService $documents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new InteropRepository;
        $this->repository->saveSettings(['site_id' => 'ysumc', 'site_domain' => 'skin.ysumc.net']);
        $this->documents = new SiteDocumentService($this->repository, new PeerAggregator($this->repository, new PeerFetcher));
    }

    private function peer(string $id = 'sitmc', string $name = 'Game'): void
    {
        $origin = 'https://'.$id.'.example.com';
        $this->repository->savePeer(['site_id' => $id, 'origin' => $origin, 'name' => $id]);
        $this->repository->storeSnapshot($id, $origin, null, json_encode([
            'site' => ['id' => $id, 'name' => $id, 'domain' => $id.'.example.com'],
            'packs' => [['id' => 'game', 'name' => $name, 'gameVersion' => '1.21.1',
                'loader' => 'vanilla', 'server' => ['address' => 'play.example.com']]],
            'peers' => [], 'updatedAt' => '2026-11-01T00:00:00Z',
        ], JSON_THROW_ON_ERROR));
    }

    private function friends(): array
    {
        return $this->documents->friends('https://skin.ysumc.net')['document'];
    }

    public function testPinsAndTagsSurviveRefreshAndStayScopedToTheirPeer(): void
    {
        $this->peer(); $this->peer('other');
        $presentation = new PeerPresentation;
        $before = $this->friends();
        $presentation->save('sitmc', 'sitmc:game', true, ['minigame', 'event', 'event']);
        $this->peer('sitmc', 'Renamed remote game');
        $after = $this->friends();
        $instances = [];
        foreach ($after['sites'] as $site) foreach ($site['instances'] as $instance) $instances[$instance['id']] = $instance;
        $this->assertTrue($instances['sitmc:game']['pinned']);
        $this->assertSame(['event', 'minigame'], $instances['sitmc:game']['tags']);
        $this->assertSame('Renamed remote game', $instances['sitmc:game']['name']);
        $this->assertFalse($instances['other:game']['pinned']);
        $this->assertSame([], $instances['other:game']['tags']);
        $this->assertNotSame(json_encode($before), json_encode($after));
        $presentation->save('sitmc', 'sitmc:game', false, []);
        $this->assertFalse($this->friends()['sites'][1]['instances'][0]['pinned']);
        $this->assertSame([], $this->friends()['sites'][1]['instances'][0]['tags']);
        $this->repository->deletePeer('sitmc');
        $this->assertSame(0, DB::table(LauncherSchema::PEER_PRESENTATION)->count());
    }

    public function testChangingPeerOriginDropsPresentationAndDisabledPeersAreNotPublished(): void
    {
        $this->peer();
        (new PeerPresentation)->save('sitmc', 'sitmc:game', true, ['event']);
        $this->repository->savePeer(['site_id' => 'sitmc', 'origin' => 'https://sitmc.example.com', 'enabled' => false]);
        $this->assertSame([], $this->friends()['sites']);
        $this->repository->savePeer(['site_id' => 'sitmc', 'origin' => 'https://new.example.com']);
        $this->assertSame(0, DB::table(LauncherSchema::PEER_PRESENTATION)->count());
        $this->assertSame([], $this->friends()['sites']);
    }

    public function testOwnTagsReachPrivateCatalogWithoutRequiringFederationListing(): void
    {
        $catalog = new LauncherRepository;
        $catalog->saveInstance(['instance_id' => 'home', 'name' => 'Home', 'game_version' => '1.21.1',
            'server_address' => 'play.example.com', 'enabled' => true, 'tags' => '["modded","permanent"]',
            'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['permanent', 'modded'], $catalog->row('home')['tags']);
        $doc = $this->documents->audienceDocument(Request::create('https://skin.ysumc.net/api/launcher/manifest'));
        $this->assertSame(['permanent', 'modded'], $doc['packs'][0]['tags']);
        $this->assertSame([], $this->documents->document('https://skin.ysumc.net')['document']['packs']);
    }

    public function testTagInputPreservesOlderFormsClearsExplicitlyAndRejectsUnknownTags(): void
    {
        foreach ([InstanceTags::class, \MUTMC\Launcher\Manifest\InstanceTags::class] as $tags) {
            $this->assertSame([], $tags::input(Request::create('/')));
            $this->assertSame(['tags' => '[]'], $tags::input(Request::create('/', 'POST', ['tags_present' => '1'])));
            $this->assertSame(['permanent', 'modded'], $tags::normalize('["modded","bad","permanent","modded"]'));
            try { $tags::input(Request::create('/', 'POST', ['tags' => ['other']])); $this->fail('Unknown tag accepted'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('tags.0', $error->errors()); }
        }
    }

    public function testPeerPresentationRequiresWritePermission(): void
    {
        $policy = $this->app->make(\SITMinecraft\Launcher\Access\BackendPolicy::class);
        $super = $this->user();
        $super->forceFill(['permission' => \App\Models\User::SUPER_ADMIN])->save();
        $reader = $this->user();
        $permissions = $policy->defaults()['normal'];
        $permissions['interop'] = 1;
        $policy->save($super, 'normal', $permissions, $policy->revision());
        $request = Request::create('/admin/launcher/interop/peers/sitmc/presentation', 'PUT');
        $request->setUserResolver(fn () => $reader);
        $this->assertSame('interop', $policy->capability($request->path()));
        try {
            (new \SITMinecraft\Launcher\Http\BackendGuard($policy))->handle($request, fn () => $this->fail('Read-only user wrote settings'), 'admin');
            $this->fail('Read-only user authorized');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $request->setUserResolver(fn () => $super);
        $this->assertSame('allowed', (new \SITMinecraft\Launcher\Http\BackendGuard($policy))->handle($request, fn () => 'allowed', 'admin'));
    }

    public function testCannotSaveUnknownOrAnotherPeersInstance(): void
    {
        $this->peer(); $this->peer('other');
        $fetcher = new PeerFetcher;
        $controller = new \SITMinecraft\Launcher\Http\Controllers\Admin\InteropController(
            $this->repository, new PeerAggregator($this->repository, $fetcher),
            new \SITMinecraft\Launcher\Interop\PeerDiscovery($fetcher), $this->documents
        );
        foreach (['other:game', 'sitmc:missing'] as $id) {
            $request = Request::create('https://skin.ysumc.net/admin/launcher/interop/peers/sitmc/presentation', 'PUT',
                ['instance_id' => $id, 'pinned' => '1', 'tags' => ['event']]);
            try { $controller->savePresentation($request, 'sitmc'); $this->fail('Unknown instance accepted'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(404, $error->getStatusCode()); }
        }
        $this->assertSame(0, DB::table(LauncherSchema::PEER_PRESENTATION)->count());
    }

    public function testBothLegacyManifestsIncludeTagsWithoutChangingPackRevisions(): void
    {
        $row = ['id' => 'game', 'name' => 'Game', 'game_version' => '1.21.1', 'revision' => 3,
            'pack_url' => 'https://cdn.example.com/game.mrpack', 'pack_sha1' => str_repeat('a', 40),
            'pack_size' => 100, 'tags' => '["event","minigame"]'];
        foreach ([\SITMinecraft\Launcher\Manifest\ManifestBuilder::class, \MUTMC\Launcher\Manifest\ManifestBuilder::class] as $builder) {
            $doc = (new $builder('https://skin.ysumc.net'))->build([$row]);
            $this->assertSame(['event', 'minigame'], $doc['instances'][0]['tags']);
            $this->assertSame(3, $doc['instances'][0]['revision']);
        }
    }

    public function testPeerPresentationTemplateRendersAndEscapesPeerNames(): void
    {
        $root = base_path('plugins/sitmc-launcher/views/admin/');
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'peer' => file_get_contents($root.'peer-presentation.twig'),
            'sitmc-launcher::admin.instance-tags' => file_get_contents($root.'instance-tags.twig'),
        ]), ['autoescape' => 'html']);
        $twig->addExtension(new \TwigBridge\Extension\Laravel\Session($this->app->make('session.store')));
        foreach (['route', 'trans', 'csrf_field', 'method_field'] as $function) {
            $twig->addFunction(new \Twig\TwigFunction($function, fn ($key = '') => $key));
        }
        $html = $twig->render('peer', ['friendSites' => [['site_id' => 'sitmc', 'name' => '<script>bad</script>',
            'instances' => [['id' => 'sitmc:game', 'name' => '<b>game</b>', 'tags' => ['event'], 'pinned' => true,
                'server' => ['address' => 'play.example.com', 'port' => null]]]]]]);
        $this->assertStringContainsString('name="pinned" value="1" checked', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testAdditiveUpgradePreservesInstancesAndIsIdempotent(): void
    {
        DB::table(LauncherSchema::INSTANCES)->insert(['instance_id' => 'existing', 'name' => 'Keep me', 'game_version' => '1.21.1']);
        Schema::table(LauncherSchema::INSTANCES, fn ($table) => $table->dropColumn('tags'));
        Schema::drop(LauncherSchema::PEER_PRESENTATION);
        (new \ReflectionProperty(LauncherSchema::class, 'installed'))->setValue(null, false);
        LauncherSchema::install(); LauncherSchema::install();
        $this->assertSame('Keep me', (new LauncherRepository)->row('existing')['name']);
        $this->assertSame([], (new LauncherRepository)->row('existing')['tags']);
        $this->assertTrue(Schema::hasTable(LauncherSchema::PEER_PRESENTATION));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function testAdminTagTemplatesUseAvailableTwigFunctionsAndEscapeNames(): void
    {
        foreach (['sitmc', 'mutmc'] as $brand) {
            $prefix = $brand.'-launcher';
            $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
                'tags' => file_get_contents(base_path('plugins/'.$prefix.'/views/admin/instance-tags.twig')),
            ]), ['autoescape' => 'html']);
            $twig->addExtension(new \TwigBridge\Extension\Laravel\Session($this->app->make('session.store')));
            $twig->addFunction(new \Twig\TwigFunction('trans', fn ($key) => $key));
            $html = $twig->render('tags', ['instance' => ['id' => 'game', 'tags' => ['event']]]);
            $this->assertSame(1, substr_count($html, 'checked'));
            $this->assertSame(4, substr_count($html, 'name="tags[]"'));
        }
    }
}
