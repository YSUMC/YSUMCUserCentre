<?php

namespace Tests\Compatibility;

use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    public function createApplication()
    {
        $root = dirname(__DIR__, 2);
        Dotenv::createImmutable($root)->safeLoad();
        if (!str_ends_with($_ENV['DB_DATABASE'] ?? '', '_test')
            || ($_ENV['DB_PORT'] ?? '') !== '13385') {
            throw new \RuntimeException('Refusing to test outside the isolated database on port 13385.');
        }
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('ysumc_php85_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        Http::preventStrayRequests();
        Mail::fake();
        config(['session.secure' => false, 'app.url' => 'http://127.0.0.1:13850']);
        option(['site_url' => 'http://127.0.0.1:13850', 'force_ssl' => false]);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1:13850');
        \Illuminate\Support\Facades\URL::forceScheme('http');
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    protected function makeUser(int $permission = 0): User
    {
        return User::factory()->create([
            'email' => 'php85-'.bin2hex(random_bytes(5)).'@example.invalid',
            'nickname' => 'PHP85 Test',
            'permission' => $permission,
            'password' => app('cipher')->hash('Only-Test-Password-85', config('secure.salt')),
            'last_sign_at' => now()->subDays(2)->format('Y-m-d H:i:s'),
            'register_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }
}
