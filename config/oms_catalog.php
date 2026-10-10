<?php

return [
    'token' => env('OMS_CATALOG_TOKEN', ''),
    'mode' => strtoupper((string) env('OMS_CATALOG_MODE', 'COLLECTION')),
    'version' => '1',
    'rate_limit' => (int) env('OMS_CATALOG_RATE_LIMIT', 600),
    'signed_url_cache_minutes' => (int) env('OMS_SIGNED_URL_CACHE_MINUTES', 20),
    'image_max_bytes' => (int) env('OMS_IMAGE_MAX_BYTES', 12 * 1024 * 1024),
    'video_max_bytes' => (int) env('OMS_VIDEO_MAX_BYTES', 60 * 1024 * 1024),
];
