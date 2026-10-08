<?php

use App\Http\Controllers\Internal\MentionEmailDigestController;
use App\Http\Middleware\VerifyInternalTaskSignature;
use Illuminate\Support\Facades\Route;

Route::post('/internal/tasks/mention-email-digests', MentionEmailDigestController::class)
    ->middleware(VerifyInternalTaskSignature::class)
    ->name('internal.tasks.mention-email-digests');
