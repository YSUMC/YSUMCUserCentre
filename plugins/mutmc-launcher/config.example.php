<?php

return [
    'disk' => env('MUTMC_PACK_DISK', env('YSUMC_PACK_DISK', 'local')),
    'userinfo_url' => env('MUTMC_USERINFO_URL', env('YSUMC_USERINFO_URL', rtrim(env('APP_URL', ''), '/').'/api/janus/userinfo')),
    'curseforge_api_key' => env('MUTMC_CURSEFORGE_API_KEY', env('YSUMC_CURSEFORGE_API_KEY', '')),
];
