<?php
// Copy to config.php (which is gitignored) and edit.
return [
    'timezone' => 'America/Phoenix',

    // URL path where the app lives, and absolute URL used in emails.
    'base_url' => '/Observers',
    'site_url' => 'https://example.org/Observers',

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'observers',
        'user' => 'observers',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    'mail' => [
        'transport' => 'smtp',          // 'smtp' or 'log' (writes to data/mail.log, for testing)
        'host' => 'smtp.example.org',
        'port' => 587,
        'encryption' => 'tls',          // 'tls' (STARTTLS), 'ssl' (implicit TLS) or 'none'
        'username' => '',
        'password' => '',
        'from_email' => 'observers@example.org',
        'from_name' => 'Pima County Observers',
        'timeout' => 15,
        'blast_batch_size' => 40,
    ],

    // SMS: only the 'null' provider (logs to data/sms.log) exists today.
    'sms' => ['provider' => 'null'],

    'verification' => [
        'require_phone' => false,       // true: signups need a verified phone to confirm
        'token_ttl_hours' => 24,        // email link lifetime; also how long unverified signups hold a seat
        'max_code_attempts' => 5,
    ],

    'shifts' => [
        'default_start' => '07:00',
        'default_end' => '19:00',
        'length_hours' => 2,
        'default_target_coverage' => 2,
    ],

    // Max actions per window (seconds)
    'rate_limits' => [
        'signup_ip' => [10, 3600],
        'signup_email' => [5, 3600],
        'verify_ip' => [20, 900],
        'login_ip' => [10, 900],
    ],

    'admin' => ['session_timeout' => 3600],
];
