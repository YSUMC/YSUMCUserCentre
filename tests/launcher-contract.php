<?php

// Framework-free contract checks: php tests/launcher-contract.php
$plugin = __DIR__.'/../plugins/sitmc-launcher/src/';
spl_autoload_register(function (string $class) use ($plugin): void {
    $prefix = 'SITMinecraft\\Launcher\\';
    if (str_starts_with($class, $prefix)) {
        require_once $plugin.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});

use SITMinecraft\Launcher\Manifest\ManifestBuilder;
use SITMinecraft\Launcher\Manifest\ManifestRules;
use SITMinecraft\Launcher\Http\Controllers\SkinController;
use SITMinecraft\Launcher\Update\UpdateBuilder;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}

$row = [
    'id' => 'survival', 'name' => '燕大生存服', 'revision' => 3,
    'game_version' => '1.21.1', 'pack_sha1' => str_repeat('a', 40),
    'pack_size' => 512, 'pack_url' => 'https://skin.ysumc.net/pack.mrpack',
];
check(ManifestRules::publishProblem($row, $row['pack_url']) === null, 'valid official pack publishes');
check(ManifestRules::publishProblem(array_replace($row, ['pack_size' => 0]), $row['pack_url']) === 'pack-size-missing', 'missing size cannot publish');
check(ManifestRules::packUrlProblem('http://skin.ysumc.net/pack.mrpack') !== null, 'insecure pack URL cannot publish');
check(ManifestRules::publishProblem(array_replace($row, ['pack_sha1' => 'invalid']), $row['pack_url']) !== null, 'invalid digest cannot publish');

$skin = (new ReflectionClass(SkinController::class))->newInstanceWithoutConstructor();
$dimensions = new ReflectionMethod(SkinController::class, 'dimensionProblem');
check($dimensions->invoke($skin, 'steve', 64, 64) === null, 'modern classic skin accepted');
check($dimensions->invoke($skin, 'steve', 64, 32) === null, 'legacy classic skin accepted');
check($dimensions->invoke($skin, 'alex', 64, 64) === null, 'slim skin accepted');
check($dimensions->invoke($skin, 'alex', 64, 32) !== null, 'legacy dimensions rejected for slim skin');
check($dimensions->invoke($skin, 'cape', 64, 32) === null, 'cape accepted');

$release = [
    'version' => '1.10.1', 'channel' => 'release', 'enabled' => true,
    'pub_date' => '2026-10-20T00:00:00Z', 'target' => 'windows', 'arch' => 'x86_64',
    'url' => 'https://skin.ysumc.net/releases/YSUMC.exe', 'signature' => 'test-signature',
];
$builder = new UpdateBuilder();
check($builder->dynamic([$release], 'windows', 'x86_64', '1.10.0', 'release') !== null, 'matching newer release offered');
check($builder->dynamic([$release], 'windows', 'x86_64', '1.10.1', 'release') === null, 'current version has no update');
check($builder->dynamic([$release], 'darwin', 'aarch64', '1.10.0', 'release') === null, 'other platform never receives Windows artifact');
check($builder->dynamic([array_replace($release, ['signature' => ''])], 'windows', 'x86_64', '1.10.0', 'release') === null, 'unsigned release withheld');

$routes = file_get_contents(__DIR__.'/../routes/web.php');
foreach (['/oa-login', '/eduroam/login', '/events', '/admin/activity', '/profile/verify-realname', '/admin/export'] as $excluded) {
    check(!str_contains($routes, $excluded), "excluded route {$excluded}");
}
