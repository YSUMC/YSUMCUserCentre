<?php
umask(0027);
if (posix_geteuid() !== posix_getpwnam('www')['uid']) {
    throw new RuntimeException('Runtime verification must run as the website user.');
}
$root = '/www/wwwroot/ysumcusercentre';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['storage/framework/views', 'storage/framework/views/twig', 'bootstrap/cache'] as $relative) {
    $directory = $root.'/'.$relative;
    if (is_dir($directory) && !is_writable($directory)) {
        throw new RuntimeException('Website cannot write runtime directory: '.$relative);
    }
}
$twig = $app->make('twig');
foreach (['home', 'auth.login', 'sitmc-launcher::admin.instances', 'sitmc-launcher::admin.instance'] as $view) {
    $twig->load($view);
}
echo "Runtime cache writable; public/admin templates compiled as www.\n";
