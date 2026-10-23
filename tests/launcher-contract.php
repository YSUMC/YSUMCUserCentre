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
use SITMinecraft\Launcher\Http\CurseForgeRequest;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}

check(CurseForgeRequest::allowed('GET', 'v1/categories', ['gameId' => '432'], null), 'CurseForge categories allowed');
$emptySearch = ['gameId' => '432', 'classId' => '4471', 'index' => '0', 'pageSize' => '20', 'searchFilter' => null, 'sortField' => '2', 'sortOrder' => 'desc'];
check(CurseForgeRequest::allowed('GET', 'v1/mods/search', $emptySearch, null), 'CurseForge empty search after Laravel middleware allowed');
check(CurseForgeRequest::query($emptySearch) === array_diff_key($emptySearch, ['searchFilter' => true]), 'CurseForge null search omitted and zero page offset preserved');
check(CurseForgeRequest::query(['searchFilter' => '', 'index' => 0]) === ['index' => 0], 'CurseForge empty string omitted without dropping numeric zero');
check(!CurseForgeRequest::allowed('GET', 'v1/mods/search', ['apiKey' => null], null), 'CurseForge unknown null parameter rejected');
check(!CurseForgeRequest::allowed('GET', 'v1/mods/search', ['searchFilter' => ['x']], null), 'CurseForge array search rejected');
check(CurseForgeRequest::allowed('GET', 'v1/mods/306612/files/123/download-url', [], null), 'CurseForge official download URL lookup allowed');
check(CurseForgeRequest::allowed('POST', 'v1/mods', [], ['modIds' => [306612]]), 'CurseForge read-only batch allowed');
foreach ([true, false] as $filter) {
    check(CurseForgeRequest::allowed('POST', 'v1/mods', [], ['modIds' => [1489886], 'filterPcOnly' => $filter]), 'CurseForge launcher project details with platform filter allowed');
    check(CurseForgeRequest::allowed('POST', 'v1/mods', [], ['filterPcOnly' => $filter, 'modIds' => [1489886]]), 'CurseForge JSON field order does not affect validation');
}
foreach (['true', 1, null, []] as $invalidFilter) {
    check(!CurseForgeRequest::allowed('POST', 'v1/mods', [], ['modIds' => [1489886], 'filterPcOnly' => $invalidFilter]), 'CurseForge invalid platform filter rejected');
}
check(!CurseForgeRequest::allowed('POST', 'v1/mods/files', [], ['fileIds' => [123], 'filterPcOnly' => true]), 'CurseForge platform filter restricted to project batch');
check(!CurseForgeRequest::allowed('POST', 'v1/mods', [], ['modIds' => [1489886], 'filterPcOnly' => true, 'apiKey' => 'untrusted']), 'CurseForge unknown batch fields rejected');
check(!CurseForgeRequest::allowed('POST', 'v1/mods', [], ['modIds' => ['item' => 1489886]]), 'CurseForge ID objects rejected');
check(CurseForgeRequest::allowed('POST', 'v1/fingerprints/432', [], ['fingerprints' => [4294967295]]), 'CurseForge unsigned fingerprints allowed');
foreach (['https://example.com', 'v1/../admin', 'v1/mods/1/files/1/download-url?key=x', 'v1/categories/../../mods', 'v1/users/me'] as $invalid) {
    check(!CurseForgeRequest::allowed('GET', $invalid, [], null), 'CurseForge arbitrary path rejected');
}
check(!CurseForgeRequest::allowed('DELETE', 'v1/mods/1', [], null), 'CurseForge writes rejected');
check(!CurseForgeRequest::allowed('GET', 'v1/categories', ['gameId' => 999], null), 'CurseForge other game rejected');
check(!CurseForgeRequest::allowed('GET', 'v1/mods/search', ['apiKey' => 'untrusted'], null), 'CurseForge client credentials rejected');
check(!CurseForgeRequest::allowed('POST', 'v1/mods', [], ['modIds' => array_fill(0, 51, 1)]), 'CurseForge oversized batch rejected');
check(!CurseForgeRequest::allowed('POST', 'v1/mods', [], ['modIds' => ['1']]), 'CurseForge invalid IDs rejected');

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
$notes = $builder->releases([
    array_replace($release, ['id' => 1, 'version' => '1.9.0', 'notes' => 'older']),
    array_replace($release, ['id' => 2, 'version' => '1.10.0', 'notes' => 'obsolete platform notes']),
    array_replace($release, ['id' => 3, 'version' => '1.10.0', 'notes' => 'latest notes', 'url' => 'https://cloud.sitmc.club/file.exe']),
    array_replace($release, ['version' => '9.0.0', 'enabled' => false]),
    array_replace($release, ['version' => '8.0.0', 'channel' => 'beta']),
]);
check(array_column($notes['releases'], 'version') === ['1.10.0', '1.9.0'], 'release notes deduplicate platforms, sort versions and exclude unpublished channels');
check($notes['releases'][0]['notes'] === 'latest notes', 'release notes use the newest published record even with an external installer');
check(!isset($notes['releases'][0]['signature']) && !isset($notes['releases'][0]['url']), 'release notes expose only public announcement metadata');
check($builder->releases([array_replace($release, ['signature' => '', 'url' => '', 'notes' => null])])['releases'][0]['notes'] === null, 'announcement-only releases and empty notes remain representable');
check($builder->releases([array_replace($release, ['pub_date' => 'invalid'])])['releases'] === [], 'invalid release dates are excluded');
check($builder->dynamic([$release], 'windows', 'x86_64', '1.10.0', 'release') !== null, 'matching newer release offered');
check($builder->dynamic([$release], 'windows', 'x86_64', '1.10.1', 'release') === null, 'current version has no update');
check($builder->dynamic([$release], 'darwin', 'aarch64', '1.10.0', 'release') === null, 'other platform never receives Windows artifact');
check($builder->dynamic([array_replace($release, ['signature' => ''])], 'windows', 'x86_64', '1.10.0', 'release') === null, 'unsigned release withheld');

$routes = file_get_contents(__DIR__.'/../routes/web.php');
foreach (['/oa-login', '/eduroam/login', '/events', '/admin/activity', '/profile/verify-realname', '/admin/export'] as $excluded) {
    check(!str_contains($routes, $excluded), "excluded route {$excluded}");
}

$catalog = (new ManifestBuilder('https://skin.ysumc.net'))->build([array_replace($row, [
    'server_address' => 'play.southmount.cn', 'server_port' => 25565, 'opened_on' => '2026-10-20',
])]);
check($catalog['instances'][0]['server']['opened_on'] === '2026-10-20', 'opening date is published unchanged');
$legacy = (new ManifestBuilder('https://skin.ysumc.net'))->build([array_replace($row, ['server_address' => 'example.com'])]);
check($legacy['instances'][0]['server']['opened_on'] === null, 'legacy instances do not invent opening dates');

$artifact = array_replace($release, ['sha256' => str_repeat('a', 64), 'size' => 100, 'artifact_kind' => 'nsis']);
$catalog = $builder->versions([$artifact], 'https://skin.ysumc.net');
check($catalog['versions'][0]['artifacts'][0]['relative_path'] === 'releases/YSUMC.exe', 'release catalog carries download path and integrity');
foreach ([['enabled' => false], ['channel' => 'beta'], ['signature' => ''], ['sha256' => 'bad'], ['url' => 'https://other.example/file.exe'], ['url' => 'https://skin.ysumc.net:8443/file.exe'], ['arch' => null]] as $invalid) {
    check($builder->versions([array_replace($artifact, $invalid)], 'https://skin.ysumc.net')['versions'] === [], 'incomplete or external release omitted from catalog');
}

$mutmcBuilder = new ManifestBuilder('https://skin.ysumc.net', '/api/launcher/mutmc/packs');
$mutmc = $mutmcBuilder->build([array_replace($row, ['pack_url' => null])], publishedAt: 0);
check($mutmc['instances'][0]['pack']['url'] === 'https://skin.ysumc.net/api/launcher/mutmc/packs/survival/'.$row['revision'], 'MUTMC uploads resolve through the isolated download route');
check($mutmcBuilder->build([], publishedAt: 0)['instances'] === [], 'MUTMC has no placeholder packs before publishing');
check($mutmcBuilder->build([array_replace($row, ['enabled' => false])], publishedAt: 0)['instances'] === [], 'MUTMC disabled packs are withheld');
