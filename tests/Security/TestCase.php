<?php

namespace Tests\Security;

use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected Application $app;
    private string $testStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        $this->app = new Application($root);
        $this->app->instance('env', 'testing');
        $this->testStorage = $root.'/.runtime/security-tests/'.bin2hex(random_bytes(8));
        mkdir($this->testStorage.'/framework/views', 0777, true);
        $this->app->useStoragePath($this->testStorage);
        $config = [];
        foreach (['app', 'auth', 'secure', 'options', 'filesystems', 'view', 'hashing', 'session'] as $name) {
            $config[$name] = require $root.'/config/'.$name.'.php';
        }
        $config['app'] = array_replace($config['app'], ['env' => 'testing', 'debug' => false,
            'key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'url' => 'http://localhost', 'locale' => 'en']);
        // No .env is loaded. Only a fresh in-memory database can be selected.
        $config['database'] = ['default' => 'sqlite', 'connections' => ['sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]];
        $config['cache'] = ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];
        $config['session']['driver'] = 'array';
        $config['logging'] = ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]];
        $config['view']['compiled'] = $this->testStorage.'/framework/views';
        $config['filesystems']['disks']['textures'] = ['driver' => 'local', 'root' => $this->testStorage.'/textures', 'throw' => true];
        $this->app->instance('config', new Repository($config));
        $this->app->instance('request', Request::create('http://localhost/'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        AliasLoader::getInstance($config['app']['aliases'])->register();
        foreach ([
            \Illuminate\Database\DatabaseServiceProvider::class,
            \Illuminate\Cache\CacheServiceProvider::class,
            \Illuminate\Filesystem\FilesystemServiceProvider::class,
            \Illuminate\Translation\TranslationServiceProvider::class,
            \Illuminate\Validation\ValidationServiceProvider::class,
            \Illuminate\Session\SessionServiceProvider::class,
            \Illuminate\Cookie\CookieServiceProvider::class,
            \Illuminate\Auth\AuthServiceProvider::class,
            \Illuminate\Hashing\HashServiceProvider::class,
            \Illuminate\Encryption\EncryptionServiceProvider::class,
            \Illuminate\View\ViewServiceProvider::class,
            \Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        ] as $provider) $this->app->register($provider);
        $this->app->singleton('options', \App\Services\Option::class);
        $this->app->singleton(\Blessing\Filter::class);
        $this->app->singleton('cipher', \App\Services\Cipher\BCRYPT::class);
        $loader = require $root.'/vendor/autoload.php';
        $loader->addPsr4('LittleSkin\\YggdrasilConnect\\', $root.'/plugins/yggdrasil-connect/src');
        $loader->addPsr4('SITMinecraft\\Launcher\\', $root.'/plugins/sitmc-launcher/src');
        $loader->addPsr4('MUTMC\\Launcher\\', $root.'/plugins/mutmc-launcher/src');
        $this->app->boot();
        \Illuminate\Database\Eloquent\Model::clearBootedModels();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        $this->schema();
        option(['union_api_root' => 'https://alliance.invalid', 'union_member_key' => 'synthetic',
            'require_verification' => false, 'max_upload_file_size' => 1024,
            'texture_name_regexp' => '', 'score_per_storage' => 2, 'private_score_per_storage' => 3,
            'score_per_closet_item' => 5, 'score_award_per_texture' => 0, 'score_award_per_like' => 0]);
    }

    private function schema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->increments('uid'); $t->string('email')->unique(); $t->string('password');
            $t->string('nickname')->default('Test'); $t->integer('score')->default(1000);
            $t->integer('avatar')->default(0); $t->integer('permission')->default(0);
            $t->boolean('verified')->default(true); $t->rememberToken();
        });
        Schema::create('players', function (Blueprint $t) {
            $t->increments('pid'); $t->integer('uid'); $t->string('name')->unique();
            $t->integer('tid_skin')->default(0); $t->integer('tid_cape')->default(0);
            $t->timestamp('last_modified')->nullable();
        });
        Schema::create('uuid', function (Blueprint $t) {
            $t->increments('id'); $t->integer('pid')->nullable(); $t->string('name'); $t->string('uuid'); $t->timestamps();
        });
        Schema::create('textures', function (Blueprint $t) {
            $t->increments('tid'); $t->string('name'); $t->string('type'); $t->string('hash');
            $t->integer('size'); $t->boolean('public'); $t->integer('uploader');
            $t->integer('likes')->default(0); $t->timestamp('upload_at')->nullable();
        });
        Schema::create('user_closet', function (Blueprint $t) {
            $t->integer('user_uid'); $t->integer('texture_tid'); $t->string('item_name');
            $t->unique(['user_uid', 'texture_tid']);
        });
        Schema::create('options', function (Blueprint $t) { $t->string('option_name')->primary(); $t->text('option_value')->nullable(); });
        Schema::create('oauth_access_tokens', function (Blueprint $t) {
            $t->string('id')->primary(); $t->integer('user_id'); $t->integer('client_id');
            $t->boolean('revoked'); $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('oauth_refresh_tokens', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('access_token_id'); $t->boolean('revoked');
        });
        Schema::create('oauth_auth_codes', function (Blueprint $t) {
            $t->string('id')->primary(); $t->integer('user_id'); $t->boolean('revoked');
        });
        Schema::create('yggc_grants', function (Blueprint $t) { $t->string('id')->primary(); });
    }

    protected function user(): User
    {
        return User::forceCreate(['email' => bin2hex(random_bytes(5)).'@example.invalid',
            'password' => password_hash('Old-Test-Password', PASSWORD_BCRYPT), 'nickname' => 'Test', 'score' => 1000]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        if (isset($this->app)) {
            DB::disconnect();
            // This path is generated under the test-only .runtime directory.
            $allowedRoot = realpath(dirname(__DIR__, 2).'/.runtime/security-tests').DIRECTORY_SEPARATOR;
            $target = realpath($this->testStorage);
            if ($target !== false && str_starts_with($target, $allowedRoot)) {
                (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($target);
            }
            $this->app->flush();
            Facade::clearResolvedInstances();
        }
        parent::tearDown();
    }
}
