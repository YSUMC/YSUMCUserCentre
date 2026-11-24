<?php
// Generated: only OAuth namespaces; the host loader always has priority.
$loader = new \Composer\Autoload\ClassLoader;
$loader->addPsr4('Laravel\\Socialite\\', __DIR__.'/laravel/socialite/src');
$loader->addPsr4('League\\OAuth1\\Client\\', __DIR__.'/league/oauth1-client/src');
$loader->addPsr4('SocialiteProviders\\Manager\\', __DIR__.'/socialiteproviders/manager/src');
$loader->register(false);
return $loader;
