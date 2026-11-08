<?php

return [
    // Android App Links: the HTTPS payment return page opens the app only when this package and the signing
    // certificate fingerprints (colon-separated uppercase SHA-256, comma list) match what the installed app is signed with.
    // Include the Play App Signing certificate for store builds. Empty means /.well-known/assetlinks.json is not published.
    'android' => [
        'package' => env('MOBILE_ANDROID_PACKAGE', 'id.wisatadaerahku.app'),
        'sha256_fingerprints' => array_values(array_filter(array_map('trim', explode(',', (string) env('MOBILE_ANDROID_SHA256_FINGERPRINTS', ''))))),
    ],
];
