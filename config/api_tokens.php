<?php

return [
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost')),
    'audience' => env('JWT_AUDIENCE', 'wisata-daerahku-api'),
    'secret' => env('JWT_SECRET'),
    'access_minutes' => 15,
    'refresh_days' => 30,
    'password_reset_url' => env('PASSWORD_RESET_URL'),
    'firebase_credentials' => env('FIREBASE_CREDENTIALS'),
    'firebase_project_id' => env('FIREBASE_PROJECT_ID'),
];
