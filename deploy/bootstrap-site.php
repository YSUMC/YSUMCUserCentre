<?php

// Run from the CLI as the deployment owner. Credentials stay in a root-only file.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (config('database.connections.mysql.database') !== 'ysumc_skin'
    || (string) config('database.connections.mysql.port') !== '13306') {
    throw new RuntimeException('This bootstrap is restricted to the dedicated YSUMC database.');
}

$credentialsFile = $root.'/.deploy/bootstrap-admin.json';
$credentials = json_decode(file_get_contents($credentialsFile), true, 512, JSON_THROW_ON_ERROR);
if (strlen($credentials['password'] ?? '') < 24
    || strlen($credentials['password'] ?? '') > 32
    || !filter_var($credentials['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('Valid bootstrap credentials are required.');
}

$run = function (string $command, array $arguments = []) use ($kernel): void {
    $arguments['--no-interaction'] = true;
    if ($kernel->call($command, $arguments) !== 0) {
        throw new RuntimeException($command.' failed: '.$kernel->output());
    }
    echo $command." completed\n";
};

if (!file_exists($root.'/storage/install.lock')) {
    if (Illuminate\Support\Facades\Schema::hasTable('users')
        && App\Models\User::query()->exists()) {
        throw new RuntimeException('Existing accounts detected; refusing initial installation.');
    }
    $run('migrate', ['--force' => true]);
    $run('bs:install', [
        'email' => $credentials['email'],
        'password' => $credentials['password'],
        'nickname' => 'YSUMC 管理员',
    ]);
}

$admin = App\Models\User::where('email', $credentials['email'])->firstOrFail();
if ((int) $admin->permission !== App\Models\User::SUPER_ADMIN) {
    throw new RuntimeException('Bootstrap account is not the expected administrator.');
}

$run('plugin:enable', ['name' => 'yggdrasil-connect']);
$run('plugin:enable', ['name' => 'sitmc-launcher']);

option([
    'site_url' => 'https://skin.ysumc.net',
    'site_name' => 'YSUMC 用户中心',
    'ygg_connect_server_url' => 'https://skin.ysumc.net/api/janus',
]);

$tables = [
    'yggc_authorization_codes', 'yggc_device_codes', 'yggc_refresh_tokens',
    'yggc_grants', 'yggc_interactions', 'yggc_sessions',
];
$existing = array_filter($tables, fn ($table) => Illuminate\Support\Facades\Schema::hasTable($table));
if (count($existing) !== 0 && count($existing) !== count($tables)) {
    throw new RuntimeException('Partial Janus schema found; inspect before resuming.');
}
if (!$existing) {
    $sql = file_get_contents($root.'/janus/prisma/migrations/20250618204012_init_janus/migration.sql');
    Illuminate\Support\Facades\DB::unprepared($sql);
}

$clients = $app->make(Laravel\Passport\ClientRepository::class);
$personal = Laravel\Passport\Client::where('name', 'YSUMC Personal Access')
    ->where('personal_access_client', true)->first();
$personal ??= $clients->createPersonalAccessClient(
    $admin->uid, 'YSUMC Personal Access', 'https://skin.ysumc.net/api/janus/callback'
);
$public = Laravel\Passport\Client::where('name', 'YSUMC Launcher')
    ->where('personal_access_client', false)->first();
$public ??= $clients->create(
    $admin->uid, 'YSUMC Launcher', 'https://skin.ysumc.net/yggc/client/public',
    null, false, false, true
);
// Janus enables public device authorization through the redirect marker above.
// Keep Passport's server-side secret populated; never distribute it to launchers.
if (!$public->secret) {
    $public->secret = Illuminate\Support\Str::random(40);
    $public->save();
}

$env = file_get_contents($root.'/.env');
$line = 'PASSPORT_PERSONAL_ACCESS_CLIENT_ID='.$personal->id;
$env = preg_match('/^PASSPORT_PERSONAL_ACCESS_CLIENT_ID=.*$/m', $env)
    ? preg_replace('/^PASSPORT_PERSONAL_ACCESS_CLIENT_ID=.*$/m', $line, $env)
    : rtrim($env)."\n".$line."\n";
file_put_contents($root.'/.env', $env);

$metadata = ['admin_uid' => $admin->uid, 'public_client_id' => $public->id];
file_put_contents($root.'/.deploy/initialized.json', json_encode($metadata, JSON_PRETTY_PRINT));
chmod($root.'/.deploy/initialized.json', 0600);
echo "Site, plugins, Janus tables and OAuth clients initialized.\n";
