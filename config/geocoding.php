<?php

return [
    'reverse_url' => env('GEOCODING_REVERSE_URL', 'https://nominatim.openstreetmap.org/reverse'),
    'user_agent' => env('GEOCODING_USER_AGENT', 'WisataDaerahku/1.0 (+https://github.com/joytimess/pami-wisata-daerahku-api)'),
    'cache_store' => env('GEOCODING_CACHE_STORE', 'database'),
    'timeout' => (int) env('GEOCODING_TIMEOUT', 5),
    'cache_seconds' => (int) env('GEOCODING_CACHE_SECONDS', 86400),
];
