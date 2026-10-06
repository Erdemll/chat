<?php

return [
    'message_rate_limit' => (int) env('CHAT_MESSAGE_RATE_LIMIT', 25),
    'page_size' => 40,
    'local_admin' => [
        'name' => env('CHAT_ADMIN_NAME'),
        'email' => env('CHAT_ADMIN_EMAIL'),
        'password' => env('CHAT_ADMIN_PASSWORD'),
    ],
];
