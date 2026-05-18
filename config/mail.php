<?php
if (!function_exists('env')) {
    require_once __DIR__ . '/functions.php';
}
return [
    'driver' => env('MAIL_DRIVER', 'smtp'),
    'host' => env('MAIL_HOST', 'academixsuite.com'),
    'port' => env('MAIL_PORT', 465),
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@academixsuite.com'),
        'name' => env('MAIL_FROM_NAME', 'AcademixSuite'),
    ],
    'encryption' => env('MAIL_ENCRYPTION', 'ssl'),
    'username' => env('MAIL_USERNAME'),
    'password' => env('MAIL_PASSWORD'),
    'sendmail' => '/usr/sbin/sendmail -bs',
    'pretend' => false,
    
    // For Mailgun
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],
    
    // For SendGrid
    'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY'),
    ],
    
    // Test email for configuration testing
    'test_email' => env('MAIL_TEST_EMAIL'),
];