<?php

namespace Tests\Interop;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Database\LauncherSchema;
use SITMinecraft\Launcher\Http\Controllers\InteropController;
use SITMinecraft\Launcher\Http\Controllers\ManifestController;
use SITMinecraft\Launcher\Http\Controllers\PackController;
use SITMinecraft\Launcher\Interop\InteropRepository;
use SITMinecraft\Launcher\Interop\InteropRules;
use SITMinecraft\Launcher\Interop\PeerAggregator;
use SITMinecraft\Launcher\Interop\PeerDiscovery;
use SITMinecraft\Launcher\Interop\PeerFetcher;
use SITMinecraft\Launcher\Interop\PeerMerger;
use SITMinecraft\Launcher\Interop\PeerTransport;
use SITMinecraft\Launcher\Interop\PublicAddress;
use SITMinecraft\Launcher\Interop\SiteDocumentBuilder;
use SITMinecraft\Launcher\Interop\SiteDocumentService;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\PackStore;

final class InteropTest extends \Tests\Security\TestCase
{
    private InteropRepository $repository;
    private SiteDocumentService $documents;
    private PeerAggregator $aggregator;
    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        option(['site_name' => 'YSUMC Test']);
        $this->app['config']->set('app.url', 'https://skin.example.com');
        $this->repository = new InteropRepository;
        $this->repository->saveSettings(['site_id' => 'ysumc', 'site_domain' => 'skin.example.com', 'peer_ttl' => 300]);
        $this->transport = new FakeTransport;
        $this->aggregator = new PeerAggregator($this->repository, new PeerFetcher(transport: $this->transport));
        $this->documents = new SiteDocumentService($this->repository, $this->aggregator);
    }

    private function instance(string $id = 'survival', bool $artifact = true): void
    {
        DB::table(LauncherSchema::INSTANCES)->insert(['instance_id' => $id, 'name' => 'Survival',
            'game_version' => '26.2', 'loader' => 'fabric', 'loader_version' => '0.19.5',
            'revision' => 2, 'server_address' => 'play.example.com', 'server_port' => null,
            'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        if ($artifact) DB::table(LauncherSchema::PACKS)->insert(['instance_id' => $id, 'revision' => 2,
            'url' => 'https://cdn.example.com/pack.mrpack', 'size' => 1234, 'sha1' => str_repeat('a', 40),
            'sha512' => str_repeat('b', 128), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function response(?string $etag = null, bool $legacy = false): \Illuminate\Http\Response
    {
        $request = Request::create('https://skin.example.com/.well-known/mc-interop-v1.json');
        if ($etag !== null) $request->headers->set('If-None-Match', $etag);
        if ($legacy) $request->headers->set('User-Agent', 'ysumc/launcher/1.0.6 (windows)');
        return (new InteropController($this->documents, $this->repository, $this->aggregator))->site($request);
    }

    private function peerDocument(array $packs = []): array
    {
        return ['site' => ['id' => 'sitmc', 'name' => 'SIT', 'description' => '', 'domain' => 'peer.example.com'],
            'packs' => $packs, 'peers' => [], 'updatedAt' => '2026-10-21T08:00:00Z'];
    }

    private function foreign(string $id = 'foreign'): array
    {
        return ['id' => $id, 'name' => 'Foreign', 'gameVersion' => '26.2', 'loader' => 'vanilla', 'server' => ['address' => 'play.example.com']];
    }

    private function peer(): void
    {
        $this->repository->savePeer(['site_id' => 'sitmc', 'origin' => 'https://peer.example.com', 'enabled' => true]);
    }

    public function testCanonicalOwnOnlyFriendsAndLegacyViewsRemainConsistent(): void
    {
        $this->instance();
        $this->repository->savePublications(['survival']);
        $this->peer();
        $foreign = $this->foreign('survival');
        $foreign['revision'] = 12;
        $foreign['artifact'] = ['format' => 'mrpack', 'url' => 'https://peer.example.com/pack.mrpack',
            'size' => 123, 'sha512' => str_repeat('b', 128)];
        $forwarded = $this->foreign('third:pack');
        $forwarded['source'] = ['siteId' => 'third', 'domain' => 'third.example.com'];
        $this->repository->storeSnapshot('sitmc', 'https://peer.example.com', '"peer"',
            InteropRules::encode($this->peerDocument([$foreign, $this->foreign('server'), $forwarded])), time() - 301);
        $controller = new InteropController($this->documents, $this->repository, $this->aggregator);
        $canonical = $this->response();
        $own = json_decode($canonical->getContent(), true);
        $this->assertSame(['survival'], array_column($own['packs'], 'id'));
        $this->assertArrayNotHasKey('source', $own['packs'][0]);
        $legacy = $this->response(legacy: true);
        $old = json_decode($legacy->getContent(), true);
        $this->assertSame(['survival', 'sitmc:server', 'sitmc:survival'], array_column($old['packs'], 'id'));
        $friends = $this->documents->friends()['document']['sites'];
        $this->assertCount(1, $friends);
        $this->assertTrue($friends[0]['stale']);
        $this->assertSame(['sitmc:server', 'sitmc:survival'], array_column($friends[0]['instances'], 'id'));
        $this->assertNull($friends[0]['instances'][0]['pack']);
        $this->assertNull($friends[0]['instances'][1]['pack']['sha1']);
        $this->assertSame(str_repeat('b', 128), $friends[0]['instances'][1]['pack']['sha512']);
        foreach (['/.well-known/mc-interop-v1.json', '/api/launcher/interop/v1/site'] as $path) {
            $request = Request::create('https://skin.example.com'.$path);
            $request->headers->set('User-Agent', 'ysumc/launcher/1.0.6 (windows)');
            $this->assertSame($legacy->getContent(), $controller->site($request)->getContent());
            $request->headers->set('X-YSUMC-Catalog', 'split-v1');
            $request->headers->set('If-None-Match', $legacy->headers->get('ETag'));
            $split = $controller->site($request);
            $this->assertSame(200, $split->status());
            $this->assertSame($canonical->getContent(), $split->getContent());
            $this->assertSame('User-Agent, X-YSUMC-Catalog', $split->headers->get('Vary'));
        }
        $cached = $this->response($canonical->headers->get('ETag'));
        $this->assertSame(304, $cached->status());
        $this->assertSame('User-Agent, X-YSUMC-Catalog', $cached->headers->get('Vary'));
        $this->assertSame(304, $this->response($legacy->headers->get('ETag'), true)->status());
        $this->assertSame([], $this->transport->calls);
    }

    public function testPeerChangeDoesNotInvalidateCanonicalOwnCatalog(): void
    {
        $this->instance(); $this->repository->savePublications(['survival']); $this->peer();
        $this->repository->storeSnapshot('sitmc', 'https://peer.example.com', null,
            InteropRules::encode($this->peerDocument([$this->foreign()])));
        $own = $this->response();
        $legacy = $this->response(legacy: true);
        $this->repository->storeSnapshot('sitmc', 'https://peer.example.com', null,
            InteropRules::encode($this->peerDocument([])));
        $this->assertSame([], $this->documents->friends()['document']['sites']);
        $this->assertSame(304, $this->response($own->headers->get('ETag'))->status());
        $this->assertSame(200, $this->response($legacy->headers->get('ETag'), true)->status());
    }

    public function testDefaultsDoNotPublishAndPublicReadsDoNotFetch(): void
    {
        $this->instance();
        $this->peer();
        $data = json_decode($this->response()->getContent(), true);
        $this->assertSame([], $data['packs']);
        $this->assertSame([], $this->transport->calls);
        $this->assertSame(['site', 'packs', 'peers', 'updatedAt'], array_keys($data));
        $this->assertSame('YSUMC Test', $data['site']['name']);
    }

    public function testRecreatedInstanceDoesNotInheritDeletedPublication(): void
    {
        $this->instance();
        $this->repository->savePublications(['survival']);
        DB::table(LauncherSchema::INTEROP_ENDPOINTS)->insert(['instance_id' => 'survival', 'address' => 'play.example.com']);
        (new LauncherRepository)->deleteInstance('survival');
        $this->instance();
        $this->assertSame([], json_decode($this->response()->getContent(), true)['packs']);
        $this->assertSame(0, DB::table(LauncherSchema::INTEROP_SERVERS)->count());
        $this->assertSame(0, DB::table(LauncherSchema::INTEROP_ENDPOINTS)->count());
    }

    public function testPackResponseStreamsUnderSymfony8ResponseContract(): void
    {
        $file = tempnam(storage_path(), 'interop-stream-');
        $bytes = "PK\x03\x04".str_repeat('fixture', 40000);
        file_put_contents($file, $bytes);
        try {
            $response = new \SITMinecraft\Launcher\Http\PackStreamResponse($file, 'test.mrpack');
            ob_start();
            try {
                $returned = $response->sendContent();
                $streamed = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertSame($response, $returned);
            $this->assertSame($bytes, $streamed);
            $this->assertSame((string) strlen($bytes), $response->headers->get('Content-Length'));
        } finally {
            unlink($file);
        }
    }

    public function testOnlySelectedCurrentRevisionIsSerializedWithBothHashes(): void
    {
        $this->instance(); $this->instance('private');
        DB::table(LauncherSchema::PACKS)->insert(['instance_id' => 'survival', 'revision' => 1, 'sha1' => str_repeat('c', 40), 'size' => 1]);
        $this->repository->savePublications(['survival']);
        $data = json_decode($this->response()->getContent(), true);
        $this->assertCount(1, $data['packs']);
        $pack = $data['packs'][0];
        $this->assertSame(2, $pack['revision']);
        $this->assertSame(str_repeat('b', 128), $pack['artifact']['sha512']);
        $this->assertSame(['address' => 'play.example.com'], $pack['server']);
        $this->assertArrayNotHasKey('source', $pack);
        $this->assertArrayNotHasKey('required', $pack);
    }

    public function testVanillaOmitsArtifactRevisionLoaderVersionAndPort(): void
    {
        $this->instance('creative', false);
        DB::table(LauncherSchema::INSTANCES)->update(['loader' => 'vanilla']);
        $this->repository->savePublications(['creative']);
        $pack = $this->documents->document()['document']['packs'][0];
        foreach (['artifact', 'revision', 'loaderVersion', 'source'] as $field) $this->assertArrayNotHasKey($field, $pack);
        $this->assertArrayNotHasKey('port', $pack['server']);
    }

    public function testInvalidLoaderMissingServerAndDisabledInstanceFailClosed(): void
    {
        foreach (['typo', 'missing', 'disabled'] as $id) $this->instance($id);
        DB::table(LauncherSchema::INSTANCES)->where('instance_id', 'typo')->update(['loader' => 'fabirc']);
        DB::table(LauncherSchema::INSTANCES)->where('instance_id', 'missing')->update(['server_address' => null]);
        DB::table(LauncherSchema::INSTANCES)->where('instance_id', 'disabled')->update(['enabled' => false]);
        $this->repository->savePublications(['typo', 'missing', 'disabled']);
        $this->assertSame([], $this->documents->document()['document']['packs']);
    }

    public function testEtagsAreStableAndAcceptWeakListsAndWildcard(): void
    {
        $first = $this->response(); $second = $this->response();
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame('max-age=300, public', $first->headers->get('Cache-Control'));
        foreach ([$first->getEtag(), 'W/'.$first->getEtag(), '"other", W/'.$first->getEtag(), '*'] as $etag) {
            $response = $this->response($etag);
            $this->assertSame(304, $response->getStatusCode());
            $this->assertSame('', $response->getContent());
        }
    }

    public function testUnpublishAndSiteRenameChangeUpdatedAtAndEtag(): void
    {
        $this->instance(); $this->repository->savePublications(['survival']);
        $first = $this->response();
        $this->repository->savePublications([]);
        $second = $this->response($first->getEtag());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertNotSame(json_decode($first->getContent(), true)['updatedAt'], json_decode($second->getContent(), true)['updatedAt']);
        option(['site_name' => 'Renamed']);
        $this->assertNotSame($second->getEtag(), $this->response()->getEtag());
    }

    public function testHostHeaderCannotChangePublishedIdentity(): void
    {
        $this->repository->saveSettings(['site_domain' => null]);
        $this->assertSame('skin.example.com', $this->documents->document('https://attacker.example.com')['document']['site']['domain']);
    }

    public function testOwnManifestPublicationIsIndependentOfPublicListing(): void
    {
        $this->instance();
        $legacy = new LauncherRepository;
        $manifest = new ManifestController($legacy, $this->documents);
        $disk = new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter(storage_path('app'))), new \League\Flysystem\Local\LocalFilesystemAdapter(storage_path('app')));
        $download = new PackController($legacy, new PackStore($disk), $this->documents);
        $request = Request::create('https://skin.example.com/api/launcher/manifest');
        $first = $manifest->show($request);
        $this->assertCount(1, json_decode($first->getContent(), true)['instances']);
        $this->assertSame([], json_decode($this->response()->getContent(), true)['packs']);
        $this->assertSame(302, $download->show('survival', 2)->getStatusCode());
        $conditional = clone $request;
        $conditional->headers->set('If-None-Match', $first->headers->get('ETag'));
        $this->assertSame(304, $manifest->show($conditional)->getStatusCode());
        $conditional->headers->set('If-None-Match', '"old", W/'.$first->headers->get('ETag'));
        $this->assertSame(304, $manifest->show($conditional)->getStatusCode());
        $this->repository->savePublications(['survival']);
        $this->assertCount(1, json_decode($manifest->show($request)->getContent(), true)['instances']);
        $this->assertSame(302, $download->show('survival', 2)->getStatusCode());
        $this->assertSame(404, $download->show('survival', 1)->getStatusCode());
        $this->repository->savePublications([]);
        $this->assertSame($first->getContent(), $manifest->show($request)->getContent());
        $this->assertSame(302, $download->show('survival', 2)->getStatusCode());
        $this->assertSame([], json_decode($this->response()->getContent(), true)['packs']);
        DB::table(LauncherSchema::INSTANCES)->where('instance_id', 'survival')->update(['enabled' => false]);
        $this->assertSame([], json_decode($manifest->show($request)->getContent(), true)['instances']);
        $this->assertSame(404, $download->show('survival', 2)->getStatusCode());
    }

    public function testOwnManifestRejectsInvalidArtifactsAndForeignFormats(): void
    {
        $this->instance('valid');
        $this->instance('missing', false);
        $this->instance('invalid');
        $this->instance('zip');
        DB::table(LauncherSchema::PACKS)->where('instance_id', 'invalid')->update(['sha1' => 'bad']);
        DB::table(LauncherSchema::PACKS)->where('instance_id', 'zip')->update(['format' => 'curseforge-zip']);
        $manifest = new ManifestController(new LauncherRepository, $this->documents);
        $data = json_decode($manifest->show(Request::create('https://skin.example.com/api/launcher/manifest'))->getContent(), true);
        $this->assertSame(['valid'], array_column($data['instances'], 'id'));
    }

    public function testPeerFailurePreservesSnapshotAndConditional304Recovers(): void
    {
        $this->peer();
        $document = $this->peerDocument([$this->foreign()]);
        $this->transport->queue[] = FakeTransport::ok($document);
        $this->assertSame(1, $this->aggregator->sync()['ok']);
        $first = $this->response(legacy: true);
        $this->transport->queue = array_fill(0, 4, new \RuntimeException('offline'));
        $this->assertSame(1, $this->aggregator->sync()['failed']);
        $failed = json_decode($this->response(legacy: true)->getContent(), true);
        $this->assertCount(1, $failed['packs']);
        $this->assertTrue($failed['packs'][0]['source']['stale']);
        $this->assertNotSame(json_decode($first->getContent(), true)['updatedAt'], $failed['updatedAt']);
        $this->transport->queue[] = ['status' => 304, 'headers' => [], 'body' => ''];
        $this->assertSame(1, $this->aggregator->sync()['ok']);
        $recovered = $this->documents->legacyDocument()['document']['packs'][0];
        $this->assertArrayNotHasKey('stale', $recovered['source']);
        $this->assertSame('"peer-v1"', $this->transport->calls[array_key_last($this->transport->calls)]['etag']);
    }

    public function testExpiredSnapshotIsStaleWithoutAnotherRequest(): void
    {
        $this->peer();
        $this->repository->storeSnapshot('sitmc', 'https://peer.example.com', '"old"', InteropRules::encode($this->peerDocument([$this->foreign()])), time() - 301);
        $this->assertTrue($this->documents->legacyDocument()['document']['packs'][0]['source']['stale']);
    }

    public function testMalformedPeerAndChangedIdentityKeepLastGoodSnapshot(): void
    {
        $this->peer();
        $this->transport->queue[] = FakeTransport::ok($this->peerDocument([$this->foreign()]));
        $this->aggregator->sync();
        foreach ([['packs' => []], array_replace_recursive($this->peerDocument([$this->foreign()]), ['site' => ['domain' => 'other.example.com']]), $this->peerDocument([array_replace($this->foreign(), ['loader' => 'fabirc'])])] as $bad) {
            $this->transport->queue[] = FakeTransport::ok($bad);
            $this->assertSame(1, $this->aggregator->sync()['failed']);
            $this->assertCount(1, $this->documents->legacyDocument()['document']['packs']);
        }
    }

    public function testMergeNamespacesDirectPeersAndSkipsTransitiveEntries(): void
    {
        $this->instance('private', false);
        $this->peer();
        $foreign = $this->foreign('third:foreign');
        $foreign['source'] = ['siteId' => 'third', 'domain' => 'third.example.com', 'stale' => true];
        $this->repository->storeSnapshot('sitmc', 'https://peer.example.com', null, InteropRules::encode($this->peerDocument([$this->foreign('private'), $foreign, $foreign])));
        $result = $this->documents->legacyDocument();
        $this->assertSame(['sitmc:private'], array_column($result['document']['packs'], 'id'));
        $this->assertSame('sitmc', $result['document']['packs'][0]['source']['siteId']);
    }

    public function testRefreshRequiresHeaderAndRejectsQueryToken(): void
    {
        $controller = new InteropController($this->documents, $this->repository, $this->aggregator);
        $token = $this->repository->syncToken();
        $request = Request::create('https://skin.example.com/api/launcher/interop/v1/refresh?token='.$token, 'POST');
        $this->assertSame(403, $controller->refresh($request)->getStatusCode());
        $request->headers->set('X-Interop-Token', $token);
        $this->assertSame(200, $controller->refresh($request)->getStatusCode());
    }

    public function testFetcherRejectsNewMajorRedirectsAndOversizedBodies(): void
    {
        $fetcher = new PeerFetcher(transport: $this->transport);
        $this->assertSame('peer-url-invalid', $fetcher->get('https://peer.example.com/.well-known/mc-interop-v2.json')['error']);
        $this->assertSame([], $this->transport->calls);
        $this->transport->queue[] = ['status' => 302, 'headers' => ['location' => 'https://127.0.0.1/'], 'body' => ''];
        $this->assertSame('peer-redirect-invalid', $fetcher->get('https://peer.example.com'.InteropRules::WELL_KNOWN_PATH)['error']);
        $this->transport->queue[] = ['status' => 200, 'headers' => [], 'body' => str_repeat('a', 1048577)];
        $this->assertSame('peer-document-too-large', $fetcher->get('https://peer.example.com'.InteropRules::WELL_KNOWN_PATH)['error']);
    }

    public function testPrivateAddressesAndAmbiguousNumericHostsAreRejected(): void
    {
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '2130706433', '0177.1', 'localhost', 'node.local', 'fd00::1', '::ffff:127.0.0.1', '2002:7f00:1::'] as $host) {
            $this->assertFalse(InteropRules::publicHost($host), $host);
        }
        $this->assertTrue(InteropRules::publicHost('play.example.com'));
        $this->assertTrue(InteropRules::publicHost('2001:4860:4860::8888'));
    }

    public function testTransportChecksDnsBeforeNetworkAndRejectsRebinding(): void
    {
        $addresses = new class extends PublicAddress {
            public function resolve(string $host): array { throw new \InvalidArgumentException('DNS contains a non-public address.'); }
        };
        $fetcher = new PeerFetcher(transport: new PeerTransport($addresses));
        $this->assertSame('peer-url-invalid', $fetcher->get('https://rebind.example.com'.InteropRules::WELL_KNOWN_PATH)['error']);
    }

    public function testStrictNumbersAndSourceFieldsDoNotLeakUnknownData(): void
    {
        foreach ([1.5, true, '1.1', '1e3'] as $value) {
            $this->assertNull(InteropRules::revision($value));
            $this->assertNull(InteropRules::size($value));
            $this->assertNull(InteropRules::port($value));
        }
        $entry = $this->foreign(); $entry['token'] = 'secret'; $entry['server']['password'] = 'secret';
        $result = (new PeerMerger)->merge([['document' => $this->peerDocument([$entry])]]);
        $this->assertStringNotContainsString('secret', InteropRules::encode($result['packs']));
    }

    public function testRouteRegistrationProtectsAdminAndKeepsCanonicalPublic(): void
    {
        $plugin = new class { public function getPath() { return base_path('plugins/sitmc-launcher'); } };
        (require $plugin->getPath().'/bootstrap.php')($plugin);
        $router = $this->app['router'];
        $this->app['events']->dispatch(new \App\Events\ConfigureRoutes($router));
        $router->getRoutes()->refreshNameLookups();
        $public = $router->getRoutes()->getByName('sitmc-launcher.interop.well-known');
        $this->assertSame('.well-known/mc-interop-v1.json', $public->uri());
        $this->assertNotContains('authorize', $public->gatherMiddleware());
        $admin = $router->getRoutes()->getByName('sitmc-launcher.admin.interop.publications');
        foreach (['web', 'authorize', 'role:admin'] as $middleware) $this->assertContains($middleware, $admin->gatherMiddleware());
        $this->assertSame(['POST'], $router->getRoutes()->getByName('sitmc-launcher.interop.refresh')->methods());
    }

    public function testLongDottedIdsAndSha512OnlyArtifacts(): void
    {
        $id = 'pack.'.str_repeat('x', 123);
        $this->instance($id);
        DB::table(LauncherSchema::PACKS)->update(['sha1' => '', 'format' => 'multimc']);
        $this->repository->savePublications([$id]);
        $pack = $this->documents->document()['document']['packs'][0];
        $this->assertSame($id, $pack['id']);
        $this->assertSame('multimc', $pack['artifact']['format']);
        $this->assertNull($pack['artifact']['sha1']);
        $this->assertNotNull($pack['artifact']['sha512']);
    }

    public function testDeletedPeerCannotBeResurrectedByInflightSnapshot(): void
    {
        $this->peer(); $this->repository->deletePeer('sitmc');
        $this->repository->storeSnapshot('sitmc', 'https://peer.example.com', null, InteropRules::encode($this->peerDocument()));
        $this->assertNull($this->repository->snapshot('sitmc'));
    }

    public function testPeerCannotSilentlySwitchMajorPathOrUseUnexpected304(): void
    {
        $this->peer();
        $this->transport->queue[] = ['status' => 304, 'body' => '', 'headers' => []];
        $this->assertSame(1, $this->aggregator->sync()['failed']);
        DB::table(LauncherSchema::INTEROP_PEERS)->update(['manifest_url' => 'https://peer.example.com/.well-known/mc-interop-v2.json']);
        $this->assertSame(1, $this->aggregator->sync()['failed']);
        $this->assertCount(1, $this->transport->calls);
    }

    public function testTwigTemplatesCompile(): void
    {
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader);
        $twig->registerUndefinedFunctionCallback(static fn ($name) => new \Twig\TwigFunction($name, static fn () => ''));
        $twig->registerUndefinedFilterCallback(static fn ($name) => new \Twig\TwigFilter($name, static fn ($value) => $value));
        foreach (glob(base_path('plugins/sitmc-launcher/views/admin/*.twig')) as $file) {
            $twig->parse($twig->tokenize(new \Twig\Source(file_get_contents($file), basename($file))));
            $this->addToAssertionCount(1);
        }
    }

    public function testPublicCatalogDoesNotReceiveLocaleCookieFromGlobalMiddleware(): void
    {
        $middleware = new \App\Http\Middleware\DetectLanguagePrefer;
        $request = Request::create('https://skin.example.com/.well-known/mc-interop-v1.json');
        $public = $middleware->handle($request, fn () => $this->response());
        $this->assertSame([], $public->headers->getCookies());
        $private = $middleware->handle($request, fn () => new \Illuminate\Http\Response('private'));
        $this->assertCount(1, $private->headers->getCookies());
    }

    public function testProtocolRequestsRequireHttpsAndSelectJsonErrors(): void
    {
        $middleware = new \SITMinecraft\Launcher\Http\InteropRequest;
        $http = Request::create('http://skin.example.com/.well-known/mc-interop-v1.json');
        $response = $middleware->handle($http, static function () { throw new \LogicException('Must not run.'); });
        $this->assertSame(426, $response->getStatusCode());
        $this->assertSame(['message'], array_keys(json_decode($response->getContent(), true)));
        $https = Request::create('https://skin.example.com/.well-known/mc-interop-v1.json');
        $middleware->handle($https, static fn ($request) => new \Illuminate\Http\Response('ok'));
        $this->assertTrue($https->expectsJson());
    }
}

final class FakeTransport extends PeerTransport
{
    public array $queue = [];
    public array $calls = [];
    public function __construct() {}
    public function get(string $url, ?string $etag): array
    {
        $this->calls[] = compact('url', 'etag');
        if ($this->queue === []) throw new \LogicException('Unexpected network request.');
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) throw $next;
        return $next;
    }
    public function backoff(int $attempt): void {}
    public static function ok(array $document): array { return ['status' => 200, 'headers' => ['etag' => '"peer-v1"'], 'body' => InteropRules::encode($document)]; }
}
