<?php

namespace Tests\Security;

use App\Models\Player;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SITMinecraft\Launcher\Access\Audience;
use SITMinecraft\Launcher\Access\Membership;
use SITMinecraft\Launcher\Auth\VerifiesPlayerToken;
use SITMinecraft\Launcher\Database\LauncherSchema;
use SITMinecraft\Launcher\Http\Controllers\AnnouncementController;
use SITMinecraft\Launcher\Http\Controllers\ManifestController;
use SITMinecraft\Launcher\Http\Controllers\PackController;
use SITMinecraft\Launcher\Http\Controllers\UpdateController;
use SITMinecraft\Launcher\Announcement\AnnouncementRepository;
use SITMinecraft\Launcher\Interop\{InteropRepository, PeerAggregator, PeerFetcher, PeerDiscovery, SiteDocumentService};
use SITMinecraft\Launcher\Manifest\{LauncherRepository, PackStore};
use SITMinecraft\Launcher\Update\ReleaseRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LauncherAudienceTest extends TestCase
{
    private Membership $memberships;
    private Audience $audience;
    private User $super;
    private User $member;
    private SiteDocumentService $documents;
    private LauncherRepository $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberships = new Membership;
        $this->audience = new Audience($this->memberships);
        $this->super = $this->user();
        $this->super->permission = User::SUPER_ADMIN;
        $this->super->save();
        $this->member = $this->user();
        Player::forceCreate(['uid' => $this->member->uid, 'name' => 'MemberPlayer']);
        $this->memberships->assign($this->super, $this->member, 'member', 'technology');
        $this->app->instance(VerifiesPlayerToken::class, new class($this->member->uid) implements VerifiesPlayerToken {
            public function __construct(private int $uid) {}
            public function verifiedIdentity(string $token): ?array { return $token === 'test-valid-token' ? ['player' => 'MemberPlayer', 'uid' => $this->uid] : null; }
        });
        $this->catalog = new LauncherRepository;
        $interop = new InteropRepository;
        $interop->saveSettings(['site_id' => 'ysumc', 'site_domain' => 'skin.ysumc.net']);
        $this->documents = new SiteDocumentService($interop, new PeerAggregator($interop, new PeerFetcher));
    }

    private function request(bool $signedIn = false): Request
    {
        $request = Request::create('https://skin.ysumc.net/api/launcher/manifest');
        if ($signedIn) $request->headers->set('Authorization', 'Bearer test-valid-token');
        $this->app->instance('request', $request);
        return $request;
    }

    private function assertHttpStatus(int $status, callable $action): void
    {
        try { $action(); $this->fail('Request unexpectedly succeeded'); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    private function instance(string $id, ?string $audience, bool $pack = true): void
    {
        $this->catalog->saveInstance(['instance_id' => $id, 'name' => $id, 'game_version' => '1.21.1',
            'revision' => 1, 'enabled' => true, 'server_address' => 'play.example.com',
            'audience_roles' => $audience, 'created_at' => now(), 'updated_at' => now()]);
        if ($pack) $this->catalog->savePack($id, 2, ['url' => 'https://cdn.example.com/'.$id.'.mrpack',
            'sha1' => str_repeat('a', 40), 'size' => 100, 'format' => 'mrpack']);
        if ($pack) $this->catalog->setRevision($id, 2);
    }

    public function testMembershipDoesNotElevateCorePrivilegesAndHasOneDepartment(): void
    {
        $this->assertSame(User::NORMAL, $this->member->fresh()->permission);
        $this->assertSame(['社员', '技术部'], $this->memberships->describe($this->member)['tags']);
        $this->memberships->assign($this->super, $this->member, 'minister', 'operations');
        $this->assertSame(['部长', '运维部'], $this->memberships->describe($this->member)['tags']);
        $this->assertSame(1, DB::table(LauncherSchema::MEMBERSHIPS)->where('uid', $this->member->uid)->count());
        $this->memberships->assign($this->super, $this->member, 'president', null);
        $this->assertSame(['社长'], $this->memberships->describe($this->member)['tags']);
        $this->assertSame(User::NORMAL, $this->member->fresh()->permission);
        $this->assertSame(3, DB::table(LauncherSchema::MEMBERSHIP_AUDIT)->count());
    }

    public function testMissingOrMultipleDepartmentsAreRejected(): void
    {
        foreach ([null, '', 'technology,operations', 'unknown'] as $department) {
            try { $this->memberships->assign($this->super, $this->member, 'member', $department); $this->fail('Accepted invalid department'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('department', $error->errors()); }
        }
        $this->assertSame('technology', $this->memberships->describe($this->member)['department']);
    }

    public function testExistingCoreAdminsRemainAuthoritative(): void
    {
        $user = $this->user();
        $this->assertSame('normal', $this->memberships->describe($user)['role']);
        $user->permission = User::ADMIN;
        $user->save();
        $this->assertSame('admin', $this->memberships->describe($user)['role']);
        $this->assertSame('super_admin', $this->memberships->describe($this->super)['role']);
    }

    public function testOrdinaryUsersAndAdminsCannotEscalatePrivileges(): void
    {
        $target = $this->user();
        $this->assertHttpStatus(403, fn () => $this->memberships->assign($this->member, $target, 'president', null));
        $admin = $this->user(); $admin->permission = User::ADMIN; $admin->save();
        $this->assertHttpStatus(403, fn () => $this->memberships->assign($admin, $target, 'admin', null));
        $this->assertHttpStatus(403, fn () => $this->memberships->assign($admin, $target, 'super_admin', null));
        $this->assertHttpStatus(403, fn () => $this->memberships->assign($admin, $this->super, 'normal', null));
        $this->assertHttpStatus(403, fn () => $this->memberships->assign($this->super, $this->super, 'normal', null));
        $this->memberships->assign($admin, $target, 'minister', 'building');
        $this->assertSame('minister', $this->memberships->describe($target)['role']);
    }

    public function testStaleActorPermissionCannotAuthorizeAWrite(): void
    {
        $admin = $this->user(); $admin->permission = User::ADMIN; $admin->save();
        DB::table('users')->where('uid', $admin->uid)->update(['permission' => User::NORMAL]);
        $this->assertHttpStatus(403, fn () => $this->memberships->assign($admin, $this->member, 'president', null));
    }

    public function testEmptyFastCgiAuthorizationRemainsAnAnonymousRequest(): void
    {
        foreach (['', '   '] as $header) {
            $request = $this->request();
            $request->headers->set('Authorization', $header);
            $rows = [['id' => 'public'], ['id' => 'restricted', 'audience_roles' => '["normal"]']];
            $this->assertSame([['id' => 'public']], $this->audience->filter($rows, $request));
            $this->assertHttpStatus(401, fn () => $this->audience->identity($request, true));
        }
        $request = $this->request();
        $request->headers->set('Authorization', 'Bearer ');
        $this->assertHttpStatus(401, fn () => $this->audience->identity($request));
    }

    public function testIdentityRequiresVerifiedTokenAndReadsCurrentDatabaseMembership(): void
    {
        $this->assertHttpStatus(401, fn () => $this->audience->identity($this->request(), true));
        $request = $this->request(); $request->headers->set('Authorization', 'Bearer expired-token');
        $this->assertHttpStatus(401, fn () => $this->audience->identity($request));
        $request = $this->request(true); $request->query->set('role', 'super_admin');
        $this->assertSame('member', $this->audience->identity($request)['role']);
        $this->memberships->assign($this->super, $this->member, 'normal', null);
        $this->assertSame('normal', $this->audience->identity($this->request(true))['role']);
        DB::table('users')->where('uid', $this->member->uid)->update(['permission' => User::BANNED]);
        $this->assertHttpStatus(403, fn () => $this->audience->identity($this->request(true)));
    }

    public function testVerifiedPlayerNameCannotSelectAnotherOwner(): void
    {
        $other = $this->user();
        Player::where('name', 'MemberPlayer')->update(['uid' => $other->uid]);
        $this->assertHttpStatus(403, fn () => $this->audience->identity($this->request(true)));
        $parser = \SITMinecraft\Launcher\Auth\TokenVerifier::class;
        $this->assertNull($parser::identityFromResponse(['selectedProfile' => ['name' => 'MemberPlayer']]));
        $this->assertNull($parser::identityFromResponse(['sub' => ['1'], 'selectedProfile' => ['name' => 'MemberPlayer']]));
        $this->assertSame(['uid' => 123, 'player' => 'MemberPlayer'], $parser::identityFromResponse(['sub' => '123', 'selectedProfile' => ['name' => 'MemberPlayer']]));
    }

    public function testRestrictionIsAnExactAllowlistAndMalformedValuesFailClosed(): void
    {
        $rows = [['id' => 'public'], ['id' => 'normal', 'audience_roles' => '["normal"]'],
            ['id' => 'member', 'audience_roles' => '["member"]'], ['id' => 'admin', 'audience_roles' => '["admin"]']];
        $this->assertSame(['public'], array_column($this->audience->filter($rows, $this->request()), 'id'));
        $this->assertSame(['public', 'member'], array_column($this->audience->filter($rows, $this->request(true)), 'id'));
        foreach (['[]', '{"role":"member"}', 'invalid', '[1]', '["unknown"]'] as $value) {
            $this->assertFalse(Audience::permits(['audience_roles' => $value], ['role' => 'member']));
        }
        $this->assertSame([], Audience::input(Request::create('/admin', 'PUT', ['name' => 'old-client'])));
        $this->expectException(ValidationException::class);
        Audience::input(Request::create('/admin', 'POST', ['audience_mode' => 'groups', 'audience_roles' => []]));
    }

    public function testAuthenticatedCatalogIncludesPrivateServerOnlyEntriesWithoutFederatingThem(): void
    {
        $this->instance('public', null);
        $this->instance('members', '["member"]');
        $this->instance('server', '["member"]', false);
        (new InteropRepository)->savePublications(['public', 'members', 'server']);
        $controller = new ManifestController($this->catalog, $this->documents);
        $guest = $controller->show($this->request());
        $member = $controller->show($this->request(true));
        $this->assertNotSame($guest->headers->get('ETag'), $member->headers->get('ETag'));
        $this->assertSame(['public'], array_column(json_decode($guest->getContent(), true)['instances'], 'id'));
        $document = json_decode($member->getContent(), true);
        $ids = array_column($document['catalog']['packs'], 'id'); sort($ids);
        $this->assertSame(['members', 'public', 'server'], $ids);
        $private = array_values(array_filter($document['instances'], fn ($entry) => $entry['id'] === 'members'))[0];
        $this->assertSame('https://skin.ysumc.net/api/launcher/packs/members/2', $private['pack']['url']);
        $this->assertSame(['public'], array_column($this->documents->document()['document']['packs'], 'id'));
        $this->assertStringContainsString('no-store', $member->headers->get('Cache-Control'));
        $this->assertSame('Authorization', $member->headers->get('Vary'));
        $request = $this->request(); $request->headers->set('If-None-Match', $member->headers->get('ETag'));
        $this->assertSame(200, $controller->show($request)->getStatusCode());
    }

    public function testPackEndpointRejectsGuestsEvenWithKnownUrl(): void
    {
        $this->instance('members', '["member"]');
        $store = new PackStore($this->app['filesystem']->disk('local'));
        $controller = new PackController($this->catalog, $store, $this->documents);
        $this->request();
        $this->assertHttpStatus(404, fn () => $controller->show('members', 2));
        $this->request(true);
        $response = $controller->show('members', 2);
        $this->assertSame('https://cdn.example.com/members.mrpack', $response->headers->get('Location'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function testAnnouncementsAndEveryUpdateProjectionFilterBeforeChoosingLatest(): void
    {
        $releases = new ReleaseRepository;
        $announcements = new AnnouncementRepository;
        foreach ([['public', '1.0.0', null], ['member', '2.0.0', '["member"]']] as [$id, $version, $roles]) {
            $announcements->save(['announcement_id' => $id, 'title' => $id, 'content' => 'test',
                'starts_at' => now()->subDay(), 'published_at' => now()->subDay(), 'audience_roles' => $roles, 'enabled' => true]);
            $releases->save(['channel' => 'release', 'version' => $version, 'notes' => $id,
                'pub_date' => now()->subDay(), 'url' => 'https://skin.ysumc.net/releases/'.$version.'.exe',
                'signature' => base64_encode(str_repeat('a', 100)), 'target' => 'windows', 'arch' => 'x86_64',
                'sha256' => str_repeat('a', 64), 'size' => 100, 'audience_roles' => $roles, 'enabled' => true]);
        }
        $updates = new UpdateController($releases);
        $news = new AnnouncementController($announcements);
        foreach ([false, true] as $signedIn) {
            $request = $this->request($signedIn);
            $expected = $signedIn ? '2.0.0' : '1.0.0';
            $this->assertCount($signedIn ? 2 : 1, json_decode($news->index($request)->getContent(), true));
            $this->assertSame($expected, json_decode($updates->latest($request)->getContent(), true)['version']);
            $this->assertSame($expected, json_decode($updates->dynamic($request, 'windows', 'x86_64', '0.9.0')->getContent(), true)['version']);
            $this->assertCount($signedIn ? 2 : 1, json_decode($updates->releases($request)->getContent(), true)['releases']);
            $this->assertCount($signedIn ? 2 : 1, json_decode($updates->versions($request)->getContent(), true)['versions']);
            $settings = $this->audience->settings(['latest_version' => '2.0.0', 'min_version' => '2.0.0', 'force_update' => true], $request);
            $this->assertSame($expected, $settings['latest_version']);
            $this->assertSame($signedIn, $settings['force_update']);
        }
    }

    public function testFullPeerManifestUrlsPreserveProxyPathsAndRejectPrivateTargets(): void
    {
        $discovery = new PeerDiscovery(new PeerFetcher);
        $url = 'https://peer.example.com/plugins/mc/.well-known/mc-interop-v1.json';
        $this->assertSame($url, $discovery->normalize($url));
        $this->assertSame('https://peer.example.com/.well-known/mc-interop-v1.json', $discovery->normalize('peer.example.com'));
        $this->assertNotSame($discovery->pendingId($url), $discovery->pendingId('https://peer.example.com/another.json'));
        foreach (['http://peer.example.com/a.json', 'https://127.0.0.1/a.json', 'https://10.0.0.1/a.json', 'https://user:pass@peer.example.com/a.json'] as $target) {
            $this->assertNull($discovery->normalize($target));
        }
    }

    public function testUpgradePreservesExistingRecordsAndUninstallDoesNotAlterUsers(): void
    {
        $this->instance('existing', null);
        foreach ([LauncherSchema::INSTANCES, LauncherSchema::ANNOUNCEMENTS, LauncherSchema::RELEASES] as $table) {
            \Illuminate\Support\Facades\Schema::table($table, fn ($schema) => $schema->dropColumn('audience_roles'));
        }
        \Illuminate\Support\Facades\Schema::drop(LauncherSchema::MEMBERSHIPS);
        \Illuminate\Support\Facades\Schema::drop(LauncherSchema::MEMBERSHIP_AUDIT);
        (new \ReflectionProperty(LauncherSchema::class, 'installed'))->setValue(null, false);
        LauncherSchema::install();
        LauncherSchema::install();
        $this->assertSame('existing', $this->catalog->row('existing')['name']);
        $this->assertNull($this->catalog->row('existing')['audience_roles']);
        $this->assertSame(User::SUPER_ADMIN, $this->super->fresh()->permission);
        LauncherSchema::uninstall();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable(LauncherSchema::MEMBERSHIPS));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable(LauncherSchema::MEMBERSHIP_AUDIT));
        $this->assertSame(User::SUPER_ADMIN, $this->super->fresh()->permission);
    }

    public function testAdminTemplatesRenderOneDepartmentAndSixRecipientGroups(): void
    {
        $root = base_path('plugins/sitmc-launcher/views/admin/');
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'admin.base' => '{% block content %}{% endblock %}',
            'memberships' => file_get_contents($root.'memberships.twig'),
            'audience' => file_get_contents($root.'audience.twig'),
        ]));
        foreach (['route', 'trans'] as $name) $twig->addFunction(new \Twig\TwigFunction($name, fn ($key = '') => $key));
        $twigConfig = require base_path('config/twigbridge.php');
        $this->assertContains(\TwigBridge\Extension\Laravel\Session::class, $twigConfig['extensions']['enabled']);
        $session = $this->app->make('session.store');
        $twig->addExtension(new \TwigBridge\Extension\Laravel\Session($session));
        $this->assertNull($twig->getFunction('old'));
        $html = $twig->render('memberships', ['rows' => [['uid' => 1, 'email' => 'user@example.invalid',
            'nickname' => '<script>alert(1)</script>', 'editable' => true, 'membership' => $this->memberships->describe($this->member)]],
            'roles' => Membership::ROLES, 'departments' => Membership::DEPARTMENTS]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('name="department"', $html);
        $this->assertStringNotContainsString('multiple', $html);
        $html = $twig->render('audience', ['audience' => ['audience_roles' => '["member"]']]);
        $this->assertSame(6, substr_count($html, 'type="checkbox"'));
        $this->assertStringContainsString('value="member" checked', $html);
        $this->assertStringContainsString('value="groups" checked', $html);
        $session->flashInput(['audience_mode' => 'groups', 'audience_roles' => ['minister']]);
        $html = $twig->render('audience', ['audience' => ['audience_roles' => '["member"]']]);
        $this->assertStringContainsString('value="minister" checked', $html);
        $this->assertStringNotContainsString('value="member" checked', $html);
        $session->flashInput(['audience_mode' => 'groups']);
        $html = $twig->render('audience', ['audience' => ['audience_roles' => '["member"]']]);
        $this->assertStringContainsString('value="groups" checked', $html);
        $this->assertStringNotContainsString('value="member" checked', $html);
        $session->flashInput(['audience_mode' => 'public']);
        $html = $twig->render('audience', ['audience' => ['audience_roles' => '["member"]']]);
        $this->assertStringContainsString('value="public" checked', $html);
        $this->assertStringNotContainsString('value="member" checked', $html);
    }
}
