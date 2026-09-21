<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'DayPilot',
        'base_url' => 'http://localhost:8081',
        'timezone' => 'Asia/Kolkata',
        'cookie_secure' => false,
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: 'db',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'daypilot',
        'user' => getenv('DB_USER') ?: 'daypilot',
        'pass' => getenv('DB_PASS') ?: 'change-me',
        'charset' => 'utf8mb4',
    ],
    'ai' => [
        'provider' => getenv('AI_PROVIDER') ?: 'gemini',
        'api_key' => getenv('GEMINI_API_KEY') ?: '',
        'model' => getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash',
    ],
    'push' => [
        'subject' => getenv('VAPID_SUBJECT') ?: 'mailto:admin@example.com',
        'public_key' => getenv('VAPID_PUBLIC_KEY') ?: '',
        'private_key' => getenv('VAPID_PRIVATE_KEY') ?: '',
    ],
    'google' => [
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: '',
    ],
];
