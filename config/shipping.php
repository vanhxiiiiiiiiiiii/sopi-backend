<?php

return [
    'routing_url' => env('SHIPPING_ROUTING_URL', 'https://router.project-osrm.org'),
    'geocoding_url' => env('SHIPPING_GEOCODING_URL', 'https://nominatim.openstreetmap.org'),
    'geocoding_fallback_url' => env('SHIPPING_GEOCODING_FALLBACK_URL', 'https://photon.komoot.io'),
    'user_agent' => env('SHIPPING_MAP_USER_AGENT', 'SopiCoursework/1.0 (+https://github.com/vanhxiiiiiiiiiiii/sopi-backend)'),
    'cache_store' => env('SHIPPING_CACHE_STORE', 'file'),
];
