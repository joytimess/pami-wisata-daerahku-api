<?php

return [
    // Opt-in development seeder (php artisan db:seed --class=DevelopmentSeeder). Credentials come only from the local
    // environment and are never committed. The seeder refuses to run outside local/development/testing.
    'seed' => [
        'superadmin' => [
            'name' => env('DEV_SEED_SUPERADMIN_NAME', 'Superadmin Development'),
            'email' => env('DEV_SEED_SUPERADMIN_EMAIL'),
            'password' => env('DEV_SEED_SUPERADMIN_PASSWORD'),
        ],
        'admin' => [
            'name' => env('DEV_SEED_ADMIN_NAME', 'Admin Development'),
            'email' => env('DEV_SEED_ADMIN_EMAIL'),
            'password' => env('DEV_SEED_ADMIN_PASSWORD'),
        ],
    ],
];
