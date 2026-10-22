<?php

// Composer bundles justinrainbow/json-schema. Reuse it for offline draft-07 keyword validation.
// Usage: php tests/Interop/validate-schema.php /path/to/composer.phar
$composer = $argv[1] ?? '';
if (! is_file($composer)) { fwrite(STDERR, "Pass a local Composer PHAR.\n"); exit(1); }
require 'phar://'.realpath($composer).'/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'SITMinecraft\\Launcher\\';
    if (str_starts_with($class, $prefix)) require dirname(__DIR__, 2).'/plugins/sitmc-launcher/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
});

use SITMinecraft\Launcher\Interop\InteropRules;
use SITMinecraft\Launcher\Interop\SiteDocumentBuilder;

$rows = [];
foreach (InteropRules::FORMATS as $i => $format) {
    $rows[] = ['id' => 'pack.'.$i, 'name' => $format, 'game_version' => '26.2', 'loader' => 'fabric', 'loader_version' => '0.19.5',
        'server_address' => 'play.example.com', 'server_port' => null, 'published' => true, 'revision' => 12,
        'packs' => [['revision' => 12, 'format' => $format, 'size' => 100, 'url' => '/downloads/'.$i,
            'sha1' => $i % 2 ? str_repeat('a', 40) : null, 'sha512' => str_repeat('b', 128)]]];
}
$rows[] = ['id' => 'creative', 'name' => 'Creative', 'game_version' => '26.2', 'loader' => 'vanilla', 'loader_version' => 'omit', 'server_address' => 'play.example.com', 'published' => true];
$options = ['site' => ['id' => 'ysumc', 'name' => 'YSUMC', 'description' => '', 'domain' => 'skin.example.com'],
    'peers' => [['site_id' => 'sitmc', 'origin' => 'https://peer.example.com', 'enabled' => true]], 'now' => 1792569600];
$builder = new SiteDocumentBuilder('skin.example.com');
$cases = [$builder->build([], $options), $builder->build($rows, $options)];
$peer = $builder->build([$rows[0]], array_replace($options, ['site' => ['id' => 'sitmc', 'name' => 'SIT', 'domain' => 'peer.example.com']]));
$options['peerDocuments'] = [['siteId' => 'sitmc', 'domain' => 'peer.example.com', 'stale' => true, 'document' => $peer]];
$cases[] = $builder->build([$rows[5]], $options);
$schema = json_decode(file_get_contents(__DIR__.'/Fixtures/mc-interop-v1.schema.json'));
foreach ($cases as $i => $case) {
    $validator = new JsonSchema\Validator;
    $value = json_decode(InteropRules::encode($case));
    $validator->validate($value, $schema);
    if (! $validator->isValid()) { fwrite(STDERR, json_encode($validator->getErrors(), JSON_PRETTY_PRINT)); exit(1); }
    echo 'PASS protocol schema: case '.($i + 1)."\n";
}
