<?php

namespace Tests\Security;

use App\Models\Player;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use SITMinecraft\Launcher\Access\Membership;
use SITMinecraft\Launcher\Interop\{InteropRepository, PeerAggregator, PeerFetcher, SiteDocumentService};
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LauncherDepartmentAudienceTest extends TestCase
{
    public static function brands(): array
    {
        return [
            'YSUMCL' => ['SITMinecraft\\Launcher', 'sitmc-launcher'],
            'MUTMC' => ['MUTMC\\Launcher', 'mutmc-launcher'],
        ];
    }

    #[DataProvider('brands')]
    public function testDepartmentAndRoleMustBothMatchAndLegacySelectionsRemainCompatible(string $namespace, string $plugin): void
    {
        $audience = $namespace.'\\Access\\Audience';
        foreach (['member', 'minister'] as $role) {
            foreach (array_keys(Membership::DEPARTMENTS) as $department) {
                $row = ['audience_roles' => json_encode([$role.'_'.$department])];
                foreach (['member', 'minister'] as $otherRole) {
                    foreach (array_keys(Membership::DEPARTMENTS) as $otherDepartment) {
                        $identity = ['role' => $otherRole, 'department' => $otherDepartment];
                        $this->assertSame($role === $otherRole && $department === $otherDepartment, $audience::permits($row, $identity));
                    }
                }
                foreach ([null, ['role' => $role], ['role' => 'admin'], ['role' => 'super_admin']] as $outsider) {
                    $this->assertFalse($audience::permits($row, $outsider));
                }
                $this->assertSame([], $audience::publicRows([$row]));
                $this->assertTrue($audience::permits(['audience_roles' => json_encode([$role])], ['role' => $role, 'department' => $department]));
            }
        }
        $mixed = ['audience_roles' => '["member_technology","minister_operations","admin"]'];
        $this->assertTrue($audience::permits($mixed, ['role' => 'admin']));
        $this->assertTrue($audience::permits($mixed, ['role' => 'minister', 'department' => 'operations']));
        $this->assertFalse($audience::permits($mixed, ['role' => 'member', 'department' => 'operations']));
        foreach (['["member_unknown"]', '["admin_technology"]', '[]', 'invalid', '[1]', '{"group":"member_technology"}'] as $invalid) {
            $this->assertFalse($audience::permits(['audience_roles' => $invalid], ['role' => 'member', 'department' => 'technology']));
        }
        $this->assertTrue($audience::permits(['audience_roles' => null], null));
    }

    #[DataProvider('brands')]
    public function testAdminInputAcceptsDepartmentGroupsAndRejectsInvalidOrEmptyRestrictions(string $namespace, string $plugin): void
    {
        $audience = $namespace.'\\Access\\Audience';
        $groups = $audience::groups();
        $this->assertCount(14, $groups);
        $value = $audience::input(Request::create('/admin', 'POST', ['audience_mode' => 'groups', 'audience_roles' => $groups]));
        $this->assertSame($groups, json_decode($value['audience_roles'], true));
        $this->assertSame([], $audience::input(Request::create('/admin', 'PUT', ['name' => 'old-admin'])));
        $this->assertSame(['audience_roles' => null], $audience::input(Request::create('/admin', 'POST', ['audience_mode' => 'public'])));
        foreach ([[], ['member_unknown'], ['admin_technology'], ['member_technology', 'member_technology']] as $invalid) {
            try {
                $audience::input(Request::create('/admin', 'POST', ['audience_mode' => 'groups', 'audience_roles' => $invalid]));
                $this->fail('Accepted invalid restrictions');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }

    #[DataProvider('brands')]
    public function testEveryDistributionEndpointUsesVerifiedCurrentDepartment(string $namespace, string $plugin): void
    {
        config(['app.url' => 'https://skin.ysumc.net']);
        $memberships = new Membership;
        $super = $this->user(); $super->permission = User::SUPER_ADMIN; $super->save();
        $user = $this->user();
        Player::forceCreate(['uid' => $user->uid, 'name' => 'DepartmentPlayer']);
        $memberships->assign($super, $user, 'member', 'technology');
        $verifier = new class($user->uid) implements \SITMinecraft\Launcher\Auth\VerifiesPlayerToken, \MUTMC\Launcher\Auth\VerifiesPlayerToken {
            public function __construct(private int $uid) {}
            public function verifiedIdentity(string $token): ?array
            {
                return $token === 'synthetic-department-token' ? ['player' => 'DepartmentPlayer', 'uid' => $this->uid] : null;
            }
        };
        $this->app->instance($namespace.'\\Auth\\VerifiesPlayerToken', $verifier);
        $catalogClass = $namespace.'\\Manifest\\LauncherRepository';
        $releaseClass = $namespace.'\\Update\\ReleaseRepository';
        $newsClass = $namespace.'\\Announcement\\AnnouncementRepository';
        $packStoreClass = $namespace.'\\Manifest\\PackStore';
        $packControllerClass = $namespace.'\\Http\\Controllers\\PackController';
        $catalog = new $catalogClass;
        $releases = new $releaseClass;
        $news = new $newsClass;
        foreach ([['public', null, '1.0.0'], ['tech', '["member_technology"]', '2.0.0'], ['ops', '["member_operations"]', '3.0.0']] as [$id, $roles, $version]) {
            $catalog->saveInstance(['instance_id' => $id, 'name' => $id, 'game_version' => '1.21.1',
                'revision' => 1, 'enabled' => true, 'server_address' => 'play.example.com', 'audience_roles' => $roles, 'created_at' => now(), 'updated_at' => now()]);
            $catalog->savePack($id, 2, ['url' => 'https://cdn.example.com/'.$id.'.mrpack', 'sha1' => str_repeat('a', 40), 'size' => 100, 'format' => 'mrpack']);
            $catalog->setRevision($id, 2);
            $news->save(['announcement_id' => $id, 'title' => $id, 'content' => 'test', 'starts_at' => now()->subDay(),
                'published_at' => now()->subDay(), 'audience_roles' => $roles, 'enabled' => true]);
            $releases->save(['channel' => 'release', 'version' => $version, 'notes' => $id, 'pub_date' => now()->subDay(),
                'url' => 'https://skin.ysumc.net/releases/'.$version.'.exe', 'signature' => base64_encode(str_repeat('a', 100)),
                'target' => 'windows', 'arch' => 'x86_64', 'sha256' => str_repeat('a', 64), 'size' => 100, 'audience_roles' => $roles, 'enabled' => true]);
        }
        $store = new $packStoreClass($this->app['filesystem']->disk('local'));
        $documents = null;
        if ($plugin === 'sitmc-launcher') {
            $interop = new InteropRepository;
            $interop->saveSettings(['site_id' => 'ysumc', 'site_domain' => 'skin.ysumc.net']);
            $interop->savePublications(['public', 'tech', 'ops']);
            $documents = new SiteDocumentService($interop, new PeerAggregator($interop, new PeerFetcher));
            $packs = new $packControllerClass($catalog, $store, $documents);
            $manifest = new \SITMinecraft\Launcher\Http\Controllers\ManifestController($catalog, $documents);
        } else {
            $packs = new $packControllerClass($catalog, $store);
            $manifest = new \MUTMC\Launcher\Http\Controllers\MutmcController($catalog, $packs);
        }
        $updatesClass = $namespace.'\\Http\\Controllers\\UpdateController';
        $announcementsClass = $namespace.'\\Http\\Controllers\\AnnouncementController';
        $updates = new $updatesClass($releases);
        $announcements = new $announcementsClass($news);
        $audienceClass = $namespace.'\\Access\\Audience';
        $audience = $this->app->make($audienceClass);
        foreach ([null, 'technology', 'operations'] as $department) {
            if ($department !== null) $memberships->assign($super, $user, 'member', $department);
            $request = Request::create('https://skin.ysumc.net/api/launcher/manifest?department=technology&role=super_admin');
            if ($department !== null) $request->headers->set('Authorization', 'Bearer synthetic-department-token');
            $this->app->instance('request', $request);
            $expected = match ($department) { 'technology' => '2.0.0', 'operations' => '3.0.0', default => '1.0.0' };
            $ids = match ($department) { 'technology' => ['public', 'tech'], 'operations' => ['ops', 'public'], default => ['public'] };
            $response = $plugin === 'sitmc-launcher' ? $manifest->show($request) : $manifest->manifest($request);
            $document = json_decode($response->getContent(), true);
            $visible = array_column($document['instances'], 'id'); sort($visible);
            $this->assertSame($ids, $visible);
            $this->assertSame('Authorization', $response->headers->get('Vary'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            if ($documents) {
                $federated = array_column($documents->document()['document']['packs'], 'id');
                $this->assertSame(['public'], $federated);
                $privateCatalog = array_column($document['catalog']['packs'], 'id'); sort($privateCatalog);
                $this->assertSame($ids, $privateCatalog);
            }
            $this->assertCount(count($ids), json_decode($announcements->index($request)->getContent(), true));
            $this->assertSame($expected, json_decode($updates->latest($request)->getContent(), true)['version']);
            $this->assertSame($expected, json_decode($updates->dynamic($request, 'windows', 'x86_64', '0.9.0')->getContent(), true)['version']);
            $this->assertCount(count($ids), json_decode($updates->versions($request)->getContent(), true)['versions']);
            $this->assertCount(count($ids), json_decode($updates->releases($request)->getContent(), true)['releases']);
            $settings = $audience->settings(['latest_version' => '3.0.0', 'min_version' => '3.0.0', 'force_update' => true], $request);
            $this->assertSame($expected, $settings['latest_version']);
            $this->assertSame($department === 'operations', $settings['force_update']);
            foreach (['tech', 'ops'] as $id) {
                try {
                    $download = $packs->deliver($id, 2, ['format' => 'mrpack']);
                    $this->assertContains($id, $ids, 'Pack endpoint leaked another department');
                    $this->assertSame('https://cdn.example.com/'.$id.'.mrpack', $download->headers->get('Location'));
                } catch (HttpException $error) {
                    $this->assertNotContains($id, $ids);
                    $this->assertSame(404, $error->getStatusCode());
                }
            }
        }
    }

    #[DataProvider('brands')]
    public function testDepartmentSelectionsRenderAndSurviveValidationWithoutSelectingAllMembers(string $namespace, string $plugin): void
    {
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'audience' => file_get_contents(base_path('plugins/'.$plugin.'/views/admin/audience.twig')),
        ]));
        $twig->addFunction(new \Twig\TwigFunction('trans', fn ($key) => $key));
        $session = $this->app->make('session.store');
        $twig->addExtension(new \TwigBridge\Extension\Laravel\Session($session));
        $stored = ['audience' => ['audience_roles' => '["member_technology","minister_operations"]']];
        $html = $twig->render('audience', $stored);
        $this->assertSame(14, substr_count($html, 'type="checkbox"'));
        foreach (['groups', 'member_technology', 'minister_operations'] as $value) $this->assertStringContainsString('value="'.$value.'" checked', $html);
        foreach (['member', 'minister', 'member_operations'] as $value) $this->assertStringNotContainsString('value="'.$value.'" checked', $html);
        $session->flashInput(['audience_mode' => 'groups', 'audience_roles' => ['member_building']]);
        $html = $twig->render('audience', $stored);
        $this->assertStringContainsString('value="member_building" checked', $html);
        $this->assertStringNotContainsString('value="member_technology" checked', $html);
        $session->flashInput(['audience_mode' => 'groups']);
        $this->assertStringNotContainsString('value="member_technology" checked', $twig->render('audience', $stored));
    }
}
