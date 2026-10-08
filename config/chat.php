<?php

return [
    'message_rate_limit' => (int) env('CHAT_MESSAGE_RATE_LIMIT', 25),
    'initial_message_limit' => max(1, min(100, (int) env('CHAT_INITIAL_MESSAGE_LIMIT', 40))),
    'history_batch_size' => max(1, min(100, (int) env('CHAT_HISTORY_BATCH_SIZE', 40))),
    'message_edit_window_minutes' => (int) env('MESSAGE_EDIT_WINDOW_MINUTES', 15),
    'local_admin' => [
        'name' => env('CHAT_ADMIN_NAME'),
        'email' => env('CHAT_ADMIN_EMAIL'),
        'password' => env('CHAT_ADMIN_PASSWORD'),
    ],
];
