<?php
return [
    'default_provider' => env('SMS_PROVIDER', 'termii'),
    
    'providers' => [
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'from_number' => env('TWILIO_FROM_NUMBER'),
        ],
        'termii' => [
            'api_key' => env('TERMII_API_KEY'),
            'sender_id' => env('TERMII_SENDER_ID', 'Academix'),
        ],
        'africastalking' => [
            'username' => env('AFRICASTALKING_USERNAME'),
            'api_key' => env('AFRICASTALKING_API_KEY'),
            'sender_id' => env('AFRICASTALKING_SENDER_ID', 'Academix'),
        ],
        'smsng' => [
            'api_key' => env('SMSNG_API_KEY'),
            'sender_id' => env('SMSNG_SENDER_ID', 'Academix'),
        ],
    ],
    
    'settings' => [
        'max_retries' => 3,
        'retry_delay' => 5, // seconds
        'timeout' => 30, // seconds
        'log_failures' => true,
    ],
];