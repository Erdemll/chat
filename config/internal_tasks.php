<?php

return [
    'secret' => env('INTERNAL_TASK_SECRET'),
    'max_skew_seconds' => (int) env('INTERNAL_TASK_MAX_SKEW_SECONDS', 300),
];
