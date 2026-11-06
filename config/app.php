<?php declare(strict_types=1);

return [
'name'                 => 'WMS PT. Dekor Asia Jayakarya',
'timezone'             => 'Asia/Jakarta',
'secret_key'           => env('APP_SECRET_KEY', ''),
'debug'                => env('APP_DEBUG', '0') === '1',
'cookie_secure'        => env('COOKIE_SECURE', '0') === '1',
'session_idle_seconds' => 1800,  
'max_failed_logins'    => 5,    
'lock_minutes'         => 15,
];
