<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Local Development Seed Credentials
    |--------------------------------------------------------------------------
    |
    | The development seeder reads its credentials from here rather than
    | calling env() directly, because env() returns null once the config is
    | cached. Keeping the values in the config directory means the seeder
    | behaves identically with or without a cached configuration.
    |
    | These accounts are for LOCAL DEVELOPMENT ONLY. They are never created by
    | the test suite and never touch the automated-test database.
    |
    */

    'admin' => [
        'name' => env('P2P_DEV_ADMIN_NAME', 'Platform Administrator'),
        'email' => env('P2P_DEV_ADMIN_EMAIL', 'admin@p2p.local'),
        'password' => env('P2P_DEV_ADMIN_PASSWORD', 'password'),
    ],

    'customer' => [
        'name' => env('P2P_DEV_CUSTOMER_NAME', 'Rakib Hasan'),
        'email' => env('P2P_DEV_CUSTOMER_EMAIL', 'customer@p2p.local'),
        'password' => env('P2P_DEV_CUSTOMER_PASSWORD', 'password'),
    ],

];
