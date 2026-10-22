<?php
// Host-specific, bounded synthetic-account checks. Never resets options or keys.
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Player;

$root = '/www/wwwroot/ysumcusercentre-php85-20261022';
$state = '/www/server/php-compat-assessments/20261022-migration/production-cutover-20261022';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'ysumc_skin' || config('database.connections.mysql.port') != 13306) {
    throw new RuntimeException('Unexpected production database.');
}
if (!is_file($state.'/database-restore-check.json')) throw new RuntimeException('Verified backup required.');
umask(0077);
$mode = $argv[1] ?? 'inspect';
$snapTables = ['users', 'players', 'uuid', 'oauth_clients', 'options', 'migrations'];
$snapshot = function () use ($snapTables) {
    $out = [];
    foreach ($snapTables as $table) {
        $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row))->sort()->values();
        $out[$table] = ['count' => $rows->count(), 'sha256' => hash('sha256', $rows->toJson())];
    }
    return $out;
};
if ($mode === 'inspect') {
    $personal = app(Laravel\Passport\ClientRepository::class)->personalAccessClient('users');
    echo json_encode([
        'php' => PHP_VERSION, 'personal_client_id' => $personal->id,
        'plugins' => json_decode(option('plugins_enabled'), true),
        'ygg_disable_authserver' => option('ygg_disable_authserver'),
        'force_ssl' => option('force_ssl'),
        'tables' => array_map(fn ($r) => array_values((array)$r)[0], DB::select('SHOW TABLES')),
    ], JSON_PRETTY_PRINT)."\n";
    exit;
}
if ($mode === 'create') {
    if (is_file($state.'/production-fixture.json')) throw new RuntimeException('Fixture already exists.');
    file_put_contents($state.'/database-invariants-before.json', json_encode($snapshot(), JSON_PRETTY_PRINT));
    $janusIds = [];
    foreach (['yggc_sessions', 'yggc_grants', 'yggc_interactions', 'yggc_device_codes', 'yggc_refresh_tokens', 'yggc_authorization_codes'] as $table) {
        $janusIds[$table] = DB::table($table)->pluck('id')->all();
    }
    file_put_contents($state.'/janus-existing-ids.json', json_encode($janusIds));
    DB::transaction(function () use ($state) {
        $suffix = bin2hex(random_bytes(5));
        $password = bin2hex(random_bytes(16));
        $user = new User;
        $user->forceFill([
            'email' => 'php85-cutover-'.$suffix.'@example.invalid', 'nickname' => 'PHP85 Cutover Test',
            'password' => app('cipher')->hash($password, config('secure.salt')), 'permission' => 0,
            'verified' => true, 'score' => 10000, 'avatar' => 0, 'ip' => '127.0.0.1',
            'last_sign_at' => now()->subDays(2)->format('Y-m-d H:i:s'), 'register_at' => now()->format('Y-m-d H:i:s'),
        ])->save();
        $player = Player::create(['uid' => $user->uid, 'name' => 'P85'.$suffix, 'tid_skin' => 0, 'tid_cape' => 0]);
        (new LittleSkin\YggdrasilConnect\Listeners\OnPlayerAdded)->handle($player);
        $client = app(Laravel\Passport\ClientRepository::class)->createAuthorizationCodeGrantClient(
            'PHP85 Cutover '.$suffix, ['https://skin.ysumc.net/yggc/callback'], true, $user);
        file_put_contents($state.'/production-fixture.json', json_encode([
            'base' => 'https://skin.ysumc.net', 'uid' => $user->uid, 'email' => $user->email, 'password' => $password,
            'pid' => $player->pid, 'player_name' => $player->name,
            'uuid' => LittleSkin\YggdrasilConnect\Models\Profile::getUuidFromPlayer($player),
            'client_id' => $client->id, 'client_secret' => $client->secret,
            'plugin_dir' => 'php85-cutover-'.$suffix,
        ]));
    });
    echo "Bounded synthetic account, player and client created. Credentials are private.\n";
    exit;
}
$fixture = json_decode(file_get_contents($state.'/production-fixture.json'), true);
$user = User::findOrFail($fixture['uid']);
if ($user->email !== $fixture['email'] || !str_ends_with($user->email, '@example.invalid')) {
    throw new RuntimeException('Fixture ownership mismatch.');
}
if ($mode === 'reset-test-password') {
    $fixture['password'] = bin2hex(random_bytes(16));
    $user->changePassword($fixture['password']);
    file_put_contents($state.'/production-fixture.json', json_encode($fixture));
    echo "Synthetic password updated privately within the application's 32-character limit.\n";
} elseif ($mode === 'admin') {
    $user->permission = 2;
    $user->save();
    echo "Synthetic account temporarily elevated for plugin upload verification.\n";
} elseif ($mode === 'cleanup') {
    if (DB::table('textures')->where('uploader', $user->uid)->exists()) throw new RuntimeException('Delete synthetic textures through HTTP first.');
    DB::transaction(function () use ($fixture, $user) {
        $tokens = DB::table('oauth_access_tokens')->where('user_id', $user->uid)->pluck('id');
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokens)->delete();
        DB::table('oauth_access_tokens')->where('user_id', $user->uid)->delete();
        DB::table('oauth_auth_codes')->where('user_id', $user->uid)->delete();
        DB::table('code_id_to_uuid')->where('uuid', $fixture['uuid'])->delete();
        DB::table('ygg_log')->where('user_id', $user->uid)->delete();
        DB::table('oauth_clients')->where('id', $fixture['client_id'])->where('user_id', $user->uid)->delete();
        DB::table('uuid')->where('pid', $fixture['pid'])->where('uuid', $fixture['uuid'])->delete();
        DB::table('players')->where('pid', $fixture['pid'])->where('uid', $user->uid)->delete();
        DB::table('users')->where('uid', $user->uid)->where('email', $fixture['email'])->delete();
    });
    $janusIds = json_decode(file_get_contents($state.'/janus-existing-ids.json'), true);
    foreach ($janusIds as $table => $existing) {
        foreach (DB::table($table)->whereNotIn('id', $existing)->get() as $row) {
            $payload = json_decode($row->payload, true);
            $associated = (string)($payload['accountId'] ?? '') === (string)$fixture['uid']
                || (string)($payload['clientId'] ?? '') === (string)$fixture['client_id']
                || (string)($payload['params']['client_id'] ?? '') === (string)$fixture['client_id'];
            if (!$associated) throw new RuntimeException('Unattributed new Janus row in '.$table.'; do not delete blindly.');
            DB::table($table)->where('id', $row->id)->delete();
        }
    }
    $dir = $root.'/plugins/'.$fixture['plugin_dir'];
    if (is_dir($dir)) {
        if (file_get_contents($dir.'/LICENSE') !== 'PHP85 production extraction check') throw new RuntimeException('Unexpected fixture directory.');
        unlink($dir.'/LICENSE');
        rmdir($dir);
    }
    $before = json_decode(file_get_contents($state.'/database-invariants-before.json'), true);
    $after = $snapshot();
    file_put_contents($state.'/database-invariants-after.json', json_encode($after, JSON_PRETTY_PRINT));
    if ($before !== $after) throw new RuntimeException('Existing production rows changed; inspect private invariant hashes.');
    file_put_contents($state.'/fixture-cleanup.json', json_encode(['account_removed' => true, 'client_removed' => true, 'player_removed' => true, 'plugin_directory_removed' => true, 'existing_rows_unchanged' => true]));
    echo "Synthetic rows removed; existing users, players, UUIDs, clients, options and migration ledger unchanged.\n";
} else {
    throw new RuntimeException('Unknown mode.');
}
