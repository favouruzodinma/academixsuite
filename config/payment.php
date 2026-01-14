<?php
return [
    'default_gateway' => env('DEFAULT_PAYMENT_GATEWAY', 'paystack'),
    
    'gateways' => [
        'paystack' => [
            'test' => [
                'public_key' => env('PAYSTACK_TEST_PUBLIC_KEY'),
                'secret_key' => env('PAYSTACK_TEST_SECRET_KEY'),
                'base_url' => 'https://api.paystack.co',
            ],
            'live' => [
                'public_key' => env('PAYSTACK_LIVE_PUBLIC_KEY'),
                'secret_key' => env('PAYSTACK_LIVE_SECRET_KEY'),
                'base_url' => 'https://api.paystack.co',
            ],
            'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        ],
        
        'flutterwave' => [
            'test' => [
                'public_key' => env('FLUTTERWAVE_TEST_PUBLIC_KEY'),
                'secret_key' => env('FLUTTERWAVE_TEST_SECRET_KEY'),
                'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
            ],
            'live' => [
                'public_key' => env('FLUTTERWAVE_LIVE_PUBLIC_KEY'),
                'secret_key' => env('FLUTTERWAVE_LIVE_SECRET_KEY'),
                'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
            ],
        ],
    ],
    
    'currencies' => [
        'NGN' => [
            'symbol' => '₦',
            'name' => 'Naira',
            'decimal_places' => 2,
        ],
        'USD' => [
            'symbol' => '$',
            'name' => 'US Dollar',
            'decimal_places' => 2,
        ],
        'GBP' => [
            'symbol' => '£',
            'name' => 'British Pound',
            'decimal_places' => 2,
        ],
    ],
    
    'fees' => [
        'transaction_fee' => 1.5, // percentage
        'vat' => 7.5, // percentage
        'minimum_amount' => 100, // in base currency
    ],
    
    'invoice' => [
        'prefix' => 'INV',
        'due_days' => 30,
        'late_fee_percentage' => 5,
        'reminder_days' => [7, 3, 1],
    ],
    
    'parent_portal' => [
        'session_timeout' => 3600, // 1 hour
        'max_login_attempts' => 5,
        'password_reset_expiry' => 3600, // 1 hour
    ],
];