<?php

return [
    'name' => 'SERVICENEST',
    'url' => $_ENV['APP_URL'] ?? 'http://localhost:8000',
    'env' => $_ENV['APP_ENV'] ?? 'development',
    'debug' => ($_ENV['APP_DEBUG'] ?? 'true') === 'true',
    'timezone' => 'UTC',
    
    // Security & Authentication
    'jwt' => [
        'secret' => $_ENV['JWT_SECRET'] ?? 'servicenest_super_secret_jwt_key_1234567890',
        'refresh_secret' => $_ENV['JWT_REFRESH_SECRET'] ?? 'servicenest_super_secret_refresh_key_0987654321',
        'access_ttl' => 3600,       // 1 hour
        'refresh_ttl' => 2592000,   // 30 days
    ],
    
    // Cloud Storage
    'cloudinary' => [
        'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'] ?? '',
        'api_key' => $_ENV['CLOUDINARY_API_KEY'] ?? '',
        'api_secret' => $_ENV['CLOUDINARY_API_SECRET'] ?? '',
    ],
    
    // Firebase Push Notifications
    'fcm' => [
        'server_key' => $_ENV['FCM_SERVER_KEY'] ?? '',
    ],
    
    // Payment Gateway Details
    'payments' => [
        'commission_rate' => 0.15, // 15% Platform Commission
        'stripe' => [
            'secret_key' => $_ENV['STRIPE_SECRET_KEY'] ?? '',
            'public_key' => $_ENV['STRIPE_PUBLIC_KEY'] ?? '',
            'webhook_secret' => $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '',
        ],
        'razorpay' => [
            'key_id' => $_ENV['RAZORPAY_KEY_ID'] ?? '',
            'key_secret' => $_ENV['RAZORPAY_KEY_SECRET'] ?? '',
        ],
    ],
    
    // Google Maps API
    'google_maps' => [
        'api_key' => $_ENV['GOOGLE_MAPS_API_KEY'] ?? '',
    ],

    // Mail / SMTP Settings (Free Gmail SMTP or Custom SMTP)
    'mail' => [
        'host'         => $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com',
        'port'         => (int)($_ENV['SMTP_PORT'] ?? 587),
        'username'     => $_ENV['SMTP_USER'] ?? '',
        'password'     => $_ENV['SMTP_PASS'] ?? '',
        'from_address' => $_ENV['SMTP_FROM_EMAIL'] ?? ($_ENV['SMTP_USER'] ?? ''),
        'from_name'    => $_ENV['SMTP_FROM_NAME'] ?? 'ServEasy',
    ]
];
