<?php

// Integration check against an initialized site. Does not modify account data.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expected = App\Models\Scope::pluck('description', 'name')->toArray();
if (!$expected) {
    throw new RuntimeException('This check requires configured custom OAuth scopes.');
}

Illuminate\Support\Facades\Cache::forget('scopes');
$provider = $app->getProvider(App\Providers\AuthServiceProvider::class);
$provider->boot();

foreach (array_keys($expected) as $scope) {
    if (!Laravel\Passport\Passport::hasScope($scope)) {
        throw new RuntimeException('Custom OAuth scope was lost after clearing cache: '.$scope);
    }
}
if (Illuminate\Support\Facades\Cache::get('scopes') !== $expected) {
    throw new RuntimeException('Custom OAuth scope cache was not rebuilt.');
}
if (!Laravel\Passport\Passport::hasScope('User.Read')) {
    throw new RuntimeException('Built-in OAuth scope was lost.');
}
echo "OAuth scopes survive an empty cache.\n";
