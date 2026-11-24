<?php

namespace Tests\Security;

use App\Models\User;
use App\Services\{Plugin, PluginManager};
use Blessing\HideAdvancedMenu\ConfigController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use SITMinecraft\Launcher\Access\BackendPolicy;
use SITMinecraft\Launcher\Database\LauncherSchema;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AdminOAuthApplicationsTest extends TestCase
{
    private ConfigController $controller;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $loader = require base_path('vendor/autoload.php');
        $loader->addPsr4('Blessing\\HideAdvancedMenu\\', base_path('plugins/hide-advanced-menu/src'), true);
        $this->controller = new ConfigController;
        $this->admin = $this->user();
        $this->admin->forceFill(['permission' => User::SUPER_ADMIN])->save();
        Schema::create('oauth_clients', function (Blueprint $t) {
            $t->increments('id'); $t->integer('user_id')->nullable(); $t->string('name');
            $t->string('secret')->nullable(); $t->string('provider')->nullable(); $t->text('redirect');
            $t->boolean('personal_access_client')->default(false); $t->boolean('password_client')->default(false);
            $t->boolean('revoked')->default(false); $t->timestamps();
        });
    }

    private function client(array $changes = []): string
    {
        return (string) DB::table('oauth_clients')->insertGetId(array_replace([
            'name' => 'Synthetic App', 'secret' => 'synthetic-secret-not-live', 'redirect' => 'https://app.invalid/callback',
        ], $changes));
    }

    private function req(?User $user, array $query = [], string $method = 'GET'): Request
    {
        $request = Request::create('https://skin.invalid/'.ConfigController::PATH, $method, $query);
        $request->setUserResolver(fn () => $user);
        return $request;
    }

    private function denied(callable $action, int $status = 403): void
    {
        try { $action(); $this->fail('Unauthorized request succeeded'); }
        catch (HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
    }

    private function policy(): BackendPolicy
    {
        $path = base_path('plugins/hide-advanced-menu');
        $plugin = (new Plugin($path, json_decode(file_get_contents($path.'/package.json'), true)))->setEnabled(true);
        $manager = $this->createStub(PluginManager::class);
        $manager->method('getEnabledPlugins')->willReturn(collect([$plugin]));
        $this->app->instance(PluginManager::class, $manager);
        $this->app->singleton(BackendPolicy::class);
        return $this->app->make(BackendPolicy::class);
    }

    public function testListsOtherOwnersSystemAndRevokedClientsWithoutSecrets(): void
    {
        $owner = $this->user();
        $this->client(['user_id' => $owner->uid]);
        $this->client(['revoked' => true, 'personal_access_client' => true]);
        $this->client(['user_id' => 999999, 'secret' => null]);
        $result = $this->controller->applications($this->req($this->admin));
        $this->assertSame(3, $result['total']);
        $this->assertSame($owner->email, $result['clients']->last()->owner_email);
        $this->assertNull($result['clients']->first()->owner_email);
        $this->assertSame(0, $result['clients']->first()->has_secret);
        foreach ($result['clients'] as $client) $this->assertFalse(property_exists($client, 'secret'));
        $this->assertStringNotContainsString('synthetic-secret-not-live', json_encode($result));
    }

    public function testSearchTreatsWildcardsLiterallyAndCombinesStatus(): void
    {
        $this->client(['name' => 'App_100%', 'revoked' => true]);
        $this->client(['name' => 'AppX100X']);
        $request = $this->req($this->admin, ['q' => '_100%', 'status' => 'revoked']);
        $this->assertSame(1, $this->controller->applications($request)['total']);
        $this->assertSame(0, $this->controller->applications($this->req($this->admin, ['q' => "' OR 1=1 --"]))['total']);
        $owner = $this->user();
        $id = $this->client(['user_id' => $owner->uid]);
        $data = $this->controller->applications($this->req($this->admin, ['q' => $owner->email]));
        $this->assertSame([$id], $data['clients']->map(fn ($client) => (string) $client->id)->all());
    }

    public function testPaginationIncludesEveryApplication(): void
    {
        for ($i = 0; $i < 27; $i++) $this->client();
        $first = $this->controller->applications($this->req($this->admin));
        $second = $this->controller->applications($this->req($this->admin, ['page' => 2]));
        $this->assertSame(27, $first['total']);
        $this->assertCount(25, $first['clients']);
        $this->assertCount(2, $second['clients']);
        $this->assertEmpty(array_intersect($first['clients']->pluck('id')->all(), $second['clients']->pluck('id')->all()));
        $this->assertNull($second['next_url']);
    }

    public function testGuestNormalAndBannedUsersCannotReadGlobalAppsOrSecrets(): void
    {
        $id = $this->client();
        $banned = $this->user(); $banned->permission = User::BANNED;
        foreach ([null, $this->user(), $banned] as $user) {
            $this->denied(fn () => $this->controller->applications($this->req($user)));
            $this->denied(fn () => $this->controller->secret($this->req($user, [], 'POST'), $id));
        }
        $this->denied(fn () => $this->controller->secret($this->req($this->admin), $id), 405);
    }

    public function testPolicyAllowsReadOnlyMetadataAndSeparateSecretPermission(): void
    {
        $id = $this->client(); $user = $this->user(); $policy = $this->policy();
        $this->assertSame(ConfigController::CAPABILITY, $policy->capability(ConfigController::PATH.'/'.$id.'/secret'));
        DB::table(LauncherSchema::SETTINGS)->updateOrInsert(['name' => BackendPolicy::SETTING], ['value' => json_encode(['normal' => [ConfigController::CAPABILITY => 1]])]);
        $result = $this->controller->applications($this->req($user));
        $this->assertSame(1, $result['total']); $this->assertFalse($result['can_reveal']);
        $this->denied(fn () => $this->controller->secret($this->req($user, [], 'POST'), $id));
        DB::table(LauncherSchema::SETTINGS)->where('name', BackendPolicy::SETTING)->update(['value' => json_encode(['normal' => [ConfigController::CAPABILITY => 2]])]);
        $policy->refresh();
        $response = $this->controller->secret($this->req($user, [], 'POST'), $id);
        $this->assertSame('synthetic-secret-not-live', $response->getData(true)['client_secret']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function testNewModuleIsDeniedInExistingMatrixExceptForSuperAdmin(): void
    {
        $this->policy();
        DB::table(LauncherSchema::SETTINGS)->updateOrInsert(['name' => BackendPolicy::SETTING], ['value' => '{"admin":{"users":2}}']);
        $ordinaryAdmin = $this->user(); $ordinaryAdmin->permission = User::ADMIN;
        $this->denied(fn () => $this->controller->applications($this->req($ordinaryAdmin)));
        $this->assertTrue($this->controller->allowed($this->req($this->admin), 2));
    }

    public function testPublicHashedAndUnknownClientSecretsAreNotReturned(): void
    {
        $hash = password_hash('synthetic-hashed-secret', PASSWORD_BCRYPT);
        foreach ([null, $hash] as $secret) {
            $id = $this->client(['secret' => $secret]);
            $response = $this->controller->secret($this->req($this->admin, [], 'POST'), $id);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertArrayNotHasKey('client_secret', $response->getData(true));
            $this->assertStringNotContainsString($hash, $response->getContent());
        }
        $this->denied(fn () => $this->controller->secret($this->req($this->admin, [], 'POST'), '9999'), 404);
    }

    public function testTwigEscapesApplicationMetadataAndNeverEmbedsSecrets(): void
    {
        $this->client(['name' => '<script>alert(1)</script>', 'redirect' => 'https://app.invalid/<img src=x onerror=alert(1)>']);
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'config' => file_get_contents(base_path('plugins/hide-advanced-menu/views/config.twig')),
            'admin.base' => '{% block content %}{% endblock %}',
        ]), ['autoescape' => 'html', 'strict_variables' => true]);
        $twig->addFunction(new \Twig\TwigFunction('url', fn ($path) => 'https://skin.invalid/'.$path));
        $html = $twig->render('config', $this->controller->applications($this->req($this->admin)));
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('synthetic-secret-not-live', $html);
        $this->assertStringContainsString('data-reveal-url', $html);
    }
}
