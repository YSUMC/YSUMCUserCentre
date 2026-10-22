<?php

return [
    'host' => env('CAS_HOST'),
    'service' => env('CAS_SERVICE'),
    'port' => (int) env('CAS_PORT', 443),
    'context' => env('CAS_CONTEXT', '/cas'),
    'service_validate_url' => env('CAS_SERVICEVALIDATE_URL'),
    'store_host' => env('CAS_STORE_HOST'),
    'ca_bundle' => env('CAS_CA_BUNDLE'),
];
