<?php

// Keep the inherited plugin ID/table namespace to allow upgrades from SIT.
return [
    'disk' => env('YSUMC_PACK_DISK', 'local'),
    'userinfo_url' => env('YSUMC_USERINFO_URL', rtrim(env('APP_URL', ''), '/').'/api/janus/userinfo'),
    'curseforge_api_key' => env('YSUMC_CURSEFORGE_API_KEY', ''),
];
