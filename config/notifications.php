<?php

return [
    'mention_email' => [
        'delay_minutes' => (int) env('MENTION_EMAIL_DELAY_MINUTES', 30),
        'max_attempts' => (int) env('MENTION_EMAIL_MAX_ATTEMPTS', 3),
    ],
];
