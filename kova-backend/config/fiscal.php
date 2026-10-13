<?php

return [
    'enabled' => (bool) env('FISCAL_ENABLED', false),
    'provider' => env('FISCAL_PROVIDER', 'finkok'),
    'environment' => env('FINKOK_ENVIRONMENT', 'development'),
    'username' => env('FINKOK_USERNAME'),
    'password' => env('FINKOK_PASSWORD'),
    'storage_disk' => env('FISCAL_STORAGE_DISK', 'local'),
    'backup_disk' => env('FISCAL_BACKUP_DISK', 'local'),
    'encryption_key_version' => (int) env('FISCAL_ENCRYPTION_KEY_VERSION', 1),
    'encryption_keys' => [
        1 => env('FISCAL_ENCRYPTION_KEY_V1'),
    ],
    'ticket_lifetime_days' => (int) env('FISCAL_TICKET_LIFETIME_DAYS', 30),
    'reservation_lifetime_minutes' => (int) env('FISCAL_RESERVATION_LIFETIME_MINUTES', 15),
];
