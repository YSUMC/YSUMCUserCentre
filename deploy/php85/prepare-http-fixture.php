<?php
// ONLY for the disposable database. Never run against a production schema.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.port') != 13385 || config('database.connections.mysql.database') !== 'ysumc_php85_test') {
    throw new RuntimeException('Not the isolated database.');
}
$base = 'http://localhost:13850';
option([
    'site_url' => $base, 'force_ssl' => false, 'ygg_connect_server_url' => $base.'/api/janus',
    'ygg_disable_authserver' => false,
]);
$user = App\Models\User::where('email', 'php85-http@example.invalid')->first();
if ($user) {
    throw new RuntimeException('Fixture already exists; use its saved credentials.');
}
$password = bin2hex(random_bytes(12));
$user = App\Models\User::factory()->create([
    'email' => 'php85-http@example.invalid', 'nickname' => 'PHP85 HTTP Test', 'permission' => 0,
    'password' => app('cipher')->hash($password, config('secure.salt')), 'verified' => true,
    'last_sign_at' => now()->subDays(2)->format('Y-m-d H:i:s'), 'register_at' => now()->format('Y-m-d H:i:s'),
]);
$player = App\Models\Player::create(['uid' => $user->uid, 'name' => 'PHP85HttpTest', 'tid_skin' => 0, 'tid_cape' => 0]);
(new LittleSkin\YggdrasilConnect\Listeners\OnPlayerAdded)->handle($player);
$repo = app(Laravel\Passport\ClientRepository::class);
$personal = $repo->createPersonalAccessGrantClient('PHP85 HTTP Personal', 'users');
$client = $repo->createAuthorizationCodeGrantClient('PHP85 HTTP Launcher', [$base.'/yggc/callback', 'http://localhost:13859/callback'], true, $user);
$state = '/www/server/php-compat-assessments/20261022-migration';
file_put_contents($state.'/http-fixture.json', json_encode([
    'base' => $base, 'email' => $user->email, 'password' => $password, 'uid' => $user->uid,
    'player_name' => $player->name, 'uuid' => LittleSkin\YggdrasilConnect\Models\Profile::getUuidFromPlayer($player),
    'client_id' => $client->id, 'client_secret' => $client->secret, 'personal_client_id' => $personal->id,
]));
chmod($state.'/http-fixture.json', 0600);
$env = file_get_contents(base_path('.env'));
$env = preg_replace('/^APP_ENV=.*$/m', 'APP_ENV=staging', $env);
$env = preg_replace('/^APP_URL=.*$/m', 'APP_URL='.$base, $env);
file_put_contents(base_path('.env'), $env."\nPASSPORT_PERSONAL_ACCESS_CLIENT_ID=".$personal->id."\n");
$janus = [
    'PORT' => '13851', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '13385', 'DB_NAME' => 'ysumc_php85_test',
    'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
    'ISSUER' => $base.'/api/janus', 'BS_SITE_URL' => $base, 'SHARED_CLIENT_ID' => (string) $client->id,
    'TOKEN_EXPIRES_IN_1' => '259200', 'TOKEN_EXPIRES_IN_2' => '604800', 'DEVICE_CODE_EXPIRES_IN' => '600', 'GRANT_EXPIRES_IN' => '25920000',
];
file_put_contents(base_path('janus/.env'), implode("\n", array_map(fn ($k, $v) => $k.'='.json_encode($v, JSON_UNESCAPED_SLASHES), array_keys($janus), $janus))."\n");
chmod(base_path('janus/.env'), 0640);
copy(storage_path('oauth-private.key'), base_path('janus/oauth-private.key'));
chmod(base_path('janus/oauth-private.key'), 0600);
echo "Disposable HTTP fixtures created. Credentials stored privately.\n";
