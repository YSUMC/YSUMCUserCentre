<?php

namespace Tests\Security;

use App\Models\User;
use Blessing\Filter;
use Blessing\OAuthCore\AuthController;
use Blessing\OAuthCore\Providers;
use Blessing\Rejection;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Two\GoogleProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OAuthCoreCompatibilityTest extends TestCase
{
    private Filter $filter;
    private Request $request;
    private GoogleProvider $provider;
    private MockHandler $http;
    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('users', function (Blueprint $table) {
            $table->string('ip')->nullable();
            $table->timestamp('register_at')->nullable();
            $table->timestamp('last_sign_at')->nullable();
        });
        option(['regs_per_ip' => 10, 'register_with_player_name' => false, 'user_initial_score' => 100]);
        $this->filter = app(Filter::class);
        $this->filter->add('oauth_providers', fn ($providers) => $providers->put('google', ['displayName' => 'Google', 'icon' => 'google']));
        $this->filter->add('client_ip', fn ($ip) => '192.0.2.1');
        $this->request = Request::create('https://skin.example/auth/login/google/callback', 'GET', ['code' => 'synthetic-code', 'state' => 'synthetic-state']);
        $session = $this->app->make('session.store');
        $session->start();
        $session->put('state', 'synthetic-state');
        $this->request->setLaravelSession($session);
        $this->app->instance('request', $this->request);
        $this->provider = new GoogleProvider($this->request, 'synthetic-client', 'synthetic-secret', 'https://skin.example/auth/login/google/callback');
        $this->http = new MockHandler;
        $this->provider->setHttpClient(new Client(['handler' => HandlerStack::create($this->http)]));
        $this->factory = new class($this->provider) implements Factory {
            public function __construct(private $provider) {}
            public function driver($driver = null) { return $this->provider; }
        };
    }

    private function queueUser(?string $email, mixed $verified = true): void
    {
        $this->http->append(new Response(200, [], json_encode(['access_token' => 'synthetic-access-token', 'expires_in' => 3600])),
            new Response(200, [], json_encode(['sub' => 'synthetic-subject', 'email' => $email, 'email_verified' => $verified, 'name' => 'Synthetic User'])));
    }

    private function oauthCallback()
    {
        return (new AuthController)->callback($this->request, app(Dispatcher::class), $this->filter, $this->factory, 'google');
    }

    private function rejects(int $status, callable $action): void
    {
        try { $action(); $this->fail('Request unexpectedly succeeded'); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
        $this->assertFalse(Auth::check());
    }

    public function testPluginLoaderCannotOverrideAnyHostFrameworkDependency(): void
    {
        $loader = require base_path('plugins/oauth/vendor/autoload.php');
        foreach ([\Illuminate\Http\Response::class, \Symfony\Component\HttpFoundation\Response::class, \GuzzleHttp\Client::class,
            \Carbon\Carbon::class, \Psr\Http\Message\ResponseInterface::class] as $class) {
            $this->assertFalse($loader->findFile($class));
            $this->assertStringNotContainsString('/plugins/oauth/', str_replace('\\', '/', (new \ReflectionClass($class))->getFileName()));
        }
        $this->assertStringContainsString('/plugins/oauth/vendor/', str_replace('\\', '/', (new \ReflectionClass(GoogleProvider::class))->getFileName()));
        $this->assertSame('ok', (new \Illuminate\Http\Response('ok'))->getContent());
        $this->app->register(\SocialiteProviders\Manager\ServiceProvider::class);
        $this->assertInstanceOf(Factory::class, $this->app->make(Factory::class));
    }

    public function testAuthorizationRedirectContainsStateAndUsesConfiguredCallback(): void
    {
        $response = (new AuthController)->login($this->filter, $this->factory, 'google');
        $url = parse_url($response->getTargetUrl());
        parse_str($url['query'], $query);
        $this->assertSame('accounts.google.com', $url['host']);
        $this->assertSame('https://skin.example/auth/login/google/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
        $this->assertSame($query['state'], $this->request->session()->get('state'));
        $this->assertSame('code', $query['response_type']);
    }

    public function testVerifiedExistingAccountLogsInAndPreservesLauncherAuthorizationDestination(): void
    {
        $user = $this->user();
        $password = $user->password;
        $this->queueUser($user->email);
        $original = $this->request->session()->getId();
        $this->request->session()->put('last_requested_path', 'https://skin.example/oauth/authorize?client_id=synthetic');
        $this->filter->add('can_register', fn ($can) => new Rejection('Registration is closed'));
        $response = $this->oauthCallback();
        $this->assertSame($user->uid, Auth::id());
        $this->assertStringEndsWith('/oauth/authorize?client_id=synthetic', $response->getTargetUrl());
        $this->assertNotSame($original, $this->request->session()->getId());
        $this->assertNull($this->request->session()->get('state'));
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame(1, User::count());
    }

    public function testNewVerifiedAccountCanBeCreatedUnderCurrentFramework(): void
    {
        $this->queueUser('new-user@example.invalid');
        $response = $this->oauthCallback();
        $user = User::firstOrFail();
        $this->assertSame($user->uid, Auth::id());
        $this->assertSame('new-user@example.invalid', $user->email);
        $this->assertSame('', $user->password);
        $this->assertTrue($user->verified);
        $this->assertSame(User::NORMAL, $user->permission);
        $this->assertSame(100, $user->score);
        $this->assertStringEndsWith('/user', $response->getTargetUrl());
    }

    public function testInvalidOrConsumedStateCannotCreateAnAccount(): void
    {
        $this->queueUser('new-user@example.invalid');
        $this->request->session()->put('state', 'different-state');
        $this->rejects(419, fn () => $this->oauthCallback());
        $this->assertSame(2, $this->http->count(), 'Invalid state must not call upstream');
        $this->assertNull($this->request->session()->get('state'));
        $this->rejects(419, fn () => $this->oauthCallback());
        $this->assertSame(0, User::count());
    }

    public function testUnverifiedEmailCannotTakeOverAnExistingAccount(): void
    {
        $user = $this->user();
        $this->queueUser($user->email, 'false');
        $this->request->query->set('email_verified', 'true');
        $this->rejects(403, fn () => $this->oauthCallback());
        $this->assertSame(1, User::count());
    }

    public function testMissingEmailReturnsControlledError(): void
    {
        $this->queueUser(null);
        $this->rejects(422, fn () => $this->oauthCallback());
        $this->assertSame(0, User::count());
    }

    public function testBannedAccountsCannotReceiveALocalSession(): void
    {
        $user = $this->user(); $user->permission = User::BANNED; $user->save();
        $this->queueUser($user->email);
        $this->rejects(403, fn () => $this->oauthCallback());
    }

    public function testRegistrationRestrictionsReceiveTheVerifiedEmail(): void
    {
        $this->queueUser('restricted@example.invalid');
        $this->request->query->set('email', 'forged@allowed.example');
        $this->filter->add('can_register', function ($can) {
            $this->assertSame('restricted@example.invalid', request()->input('email'));
            return new Rejection('Email domain or invitation code rejected');
        });
        $this->rejects(403, fn () => $this->oauthCallback());
        $this->assertSame(0, User::count());
    }

    public function testUnknownOrMisconfiguredProvidersDoNotCauseServerErrors(): void
    {
        $this->rejects(404, fn () => (new AuthController)->login($this->filter, $this->factory, 'not-configured'));
        $broken = new class implements Factory {
            public function driver($driver = null) { throw new \InvalidArgumentException('synthetic-sensitive-config'); }
        };
        $this->rejects(503, fn () => (new AuthController)->login($this->filter, $broken, 'google'));
        $this->filter->add('oauth_providers', fn ($providers) => $providers->put('../unsafe', []));
        $this->assertFalse(Providers::available($this->filter)->has('../unsafe'));
    }

    public function testCanceledConsentDoesNotRequestTokens(): void
    {
        $this->queueUser('unused@example.invalid');
        $this->request->query->set('error', 'access_denied');
        $this->rejects(400, fn () => $this->oauthCallback());
        $this->assertSame(2, $this->http->count());
        $this->assertNull($this->request->session()->get('state'));
    }

    public function testMissingCustomProviderConfigurationIsControlled(): void
    {
        $factory = new class implements Factory {
            public function driver($driver = null) { throw new \SocialiteProviders\Manager\Exception\MissingConfigException('synthetic-config'); }
        };
        $this->rejects(503, fn () => (new AuthController)->login($this->filter, $factory, 'google'));
    }

    public function testOAuthOneExpiredSessionAndUpstreamFailureAreControlled(): void
    {
        $this->factory = new class implements Factory {
            public function driver($driver = null) {
                return new class implements \Laravel\Socialite\Contracts\Provider {
                    public function redirect() { throw new \GuzzleHttp\Exception\ConnectException('synthetic-network-error', new \GuzzleHttp\Psr7\Request('POST', 'https://provider.invalid/token')); }
                    public function user() { throw new \Laravel\Socialite\One\MissingTemporaryCredentialsException; }
                };
            }
        };
        $this->rejects(502, fn () => (new AuthController)->login($this->filter, $this->factory, 'google'));
        $this->rejects(419, fn () => $this->oauthCallback());
    }

    public function testUntrustedIntendedDestinationCannotLeaveTheSite(): void
    {
        $user = $this->user();
        $this->queueUser($user->email);
        $this->request->session()->put('last_requested_path', '//outside.example/path');
        $response = $this->oauthCallback();
        $this->assertStringEndsWith('/user', $response->getTargetUrl());
        $this->assertStringNotContainsString('outside.example', $response->getTargetUrl());
    }
}
