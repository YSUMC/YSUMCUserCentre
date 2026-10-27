<?php

namespace Tests\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Access\Membership;
use SITMinecraft\Launcher\Http\Controllers\DownloadController;
use SITMinecraft\Launcher\Update\DownloadCatalog;
use SITMinecraft\Launcher\Update\ReleaseRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LauncherDownloadTest extends TestCase
{
    private function release(array $overrides = []): array
    {
        return array_replace(['id' => 1, 'distribution_brand' => 'ysumc', 'channel' => 'release',
            'version' => '1.1.1', 'pub_date' => '2026-10-27 12:00:00', 'enabled' => true,
            'target' => 'windows', 'arch' => 'x86_64', 'artifact_kind' => 'nsis',
            'url' => 'https://cloud.example.com/f/installer.exe?download=1', 'signature' => 'test-signature',
            'size' => 74215315, 'sha256' => str_repeat('a', 64), 'notes' => 'Release notes',
            'audience_roles' => null], $overrides);
    }

    public function testLatestUsesVersionOrderAndKeepsTheConfiguredArtifactUrl(): void
    {
        $latest = (new DownloadCatalog)->latest([
            $this->release(['version' => '1.2.0', 'pub_date' => '2026-10-28 00:00:00']),
            $this->release(['version' => '1.10.0', 'pub_date' => '2026-10-26 00:00:00']),
        ], ['role' => 'normal']);
        $this->assertSame('1.10.0', $latest['version']);
        $this->assertSame('https://cloud.example.com/f/installer.exe?download=1', $latest['url']);
        $this->assertSame('cloud.example.com', $latest['host']);
        $this->assertSame('74.2 MB', $latest['size']);
    }

    public function testOnlyEligibleStableWindowsReleasesAreShown(): void
    {
        $invalid = [['enabled' => false], ['channel' => 'beta'], ['version' => '9.0.0-beta.1'],
            ['distribution_brand' => 'mutmc'], ['target' => 'darwin'], ['arch' => 'aarch64'],
            ['artifact_kind' => 'deb'], ['audience_roles' => '["member"]'],
            ['audience_roles' => 'invalid'], ['pub_date' => 'bad-date']];
        foreach ($invalid as $values) {
            $rows = [$this->release(), $this->release(array_replace(['id' => 2, 'version' => '9.0.0'], $values))];
            $this->assertSame('1.1.1', (new DownloadCatalog)->latest($rows, ['role' => 'normal'])['version']);
        }
        $member = $this->release(['audience_roles' => '["member"]']);
        $this->assertNotNull((new DownloadCatalog)->latest([$member], ['role' => 'member']));
        $this->assertNotNull((new DownloadCatalog)->latest([$this->release(['target' => null, 'arch' => null])], ['role' => 'normal']));
    }

    public function testUnavailableLatestNeverFallsBackToAnOlderDownload(): void
    {
        foreach (['', 'javascript:alert(1)', "https://cloud.example.com/a\r\nX-Test:yes", 'https://user:password@cloud.example.com/a'] as $url) {
            $latest = (new DownloadCatalog)->latest([
                $this->release(), $this->release(['id' => 2, 'version' => '1.2.0', 'url' => $url]),
            ], ['role' => 'normal']);
            $this->assertSame('1.2.0', $latest['version']);
            $this->assertFalse($latest['downloadable']);
            $this->assertNull($latest['url']);
        }
        $this->assertNull((new DownloadCatalog)->latest([], ['role' => 'normal']));
    }

    private function controller(): DownloadController
    {
        return new DownloadController(new ReleaseRepository, new Membership);
    }

    private function request(?User $user): Request
    {
        $request = Request::create('https://skin.ysumc.net/user/launcher/download?url=https://untrusted.invalid');
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $user);
        return $request;
    }

    public function testAnonymousAndBannedUsersCannotGetTheArtifactFromTheController(): void
    {
        $controller = $this->controller();
        $banned = $this->user(); $banned->permission = User::BANNED;
        foreach ([[null, 401], [$banned, 403]] as [$user, $status]) {
            try { $controller->download($this->request($user)); $this->fail('Unauthorized download'); }
            catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
        }
    }

    public function testDownloadRechecksCurrentReleaseAndDoesNotAcceptRedirectParameters(): void
    {
        $controller = $this->controller();
        $repository = new ReleaseRepository;
        $repository->save($this->release());
        $user = $this->user();
        $response = $controller->download($this->request($user));
        $this->assertSame($this->release()['url'], $response->headers->get('Location'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('Cookie', $response->headers->get('Vary'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $repository->save($this->release(['id' => 2, 'version' => '1.2.0', 'url' => 'https://cdn.example.com/new.exe']));
        $this->assertSame('https://cdn.example.com/new.exe', $controller->download($this->request($user))->headers->get('Location'));
        DB::table(ReleaseRepository::table())->where('version', '1.2.0')->update(['enabled' => false]);
        $this->assertSame($this->release()['url'], $controller->download($this->request($user))->headers->get('Location'));
    }

    public function testUnavailableDownloadReturnsToThePageWithoutAnExternalLocation(): void
    {
        $controller = $this->controller();
        $this->app['router']->get('user/launcher', fn () => '')->name('sitmc-launcher.download.index');
        $this->app['router']->getRoutes()->refreshNameLookups();
        (new ReleaseRepository)->save($this->release(['url' => '']));
        $response = $controller->download($this->request($this->user()));
        $this->assertSame('https://skin.ysumc.net/user/launcher', $response->headers->get('Location'));
        $this->assertTrue($this->app['session.store']->get('launcher_download_unavailable'));
    }

    public function testReleaseNotesRemainEscapedAndTheDownloadLinkStaysBehindTheRoute(): void
    {
        $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2).'/plugins/sitmc-launcher/views');
        $parents = new \Twig\Loader\ArrayLoader(['user.base' => '{% block content %}{% endblock %}']);
        $twig = new \Twig\Environment(new \Twig\Loader\ChainLoader([$loader, $parents]), ['autoescape' => 'html', 'strict_variables' => true]);
        foreach (['trans' => fn ($value) => $value, 'url' => fn ($value) => $value,
            'route' => fn ($value) => '/user/launcher/download', 'session_get' => fn ($value) => null] as $name => $callback) {
            $twig->addFunction(new \Twig\TwigFunction($name, $callback));
        }
        $release = (new DownloadCatalog)->latest([$this->release(['notes' => '<script>alert(1)</script>'])], ['role' => 'normal']);
        $html = $twig->render('download.twig', ['release' => $release]);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('href="/user/launcher/download"', $html);
        $this->assertStringNotContainsString($release['url'], $html);
        $empty = $twig->render('download.twig', ['release' => null]);
        $this->assertStringContainsString('download.empty-title', $empty);
        $this->assertStringNotContainsString('href="/user/launcher/download"', $empty);
    }
}
