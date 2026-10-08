<?php

use App\Models\MessageMention;
use App\Models\User;
use App\Notifications\MentionDigestNotification;
use App\Services\Notifications\MentionEmailDigestService;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\mock;

test('command calls the shared service and prints the exact successful summary', function () {
    mock(MentionEmailDigestService::class)->shouldReceive('run')->once()->andReturn([
        'users_processed' => 3, 'emails_sent' => 3, 'mentions_processed' => 5, 'failed' => 0,
    ]);

    $this->artisan('mentions:send-email-digests')
        ->expectsOutput('users_processed=3 emails_sent=3 mentions_processed=5 failed=0')
        ->assertExitCode(0);
});

test('command returns exit one for a partial failure and prints its summary', function () {
    mock(MentionEmailDigestService::class)->shouldReceive('run')->once()->andReturn([
        'users_processed' => 3, 'emails_sent' => 2, 'mentions_processed' => 5, 'failed' => 1,
    ]);

    $this->artisan('mentions:send-email-digests')
        ->expectsOutput('users_processed=3 emails_sent=2 mentions_processed=5 failed=1')
        ->assertExitCode(1);
});

test('command sends one real service digest and does not resend on its next invocation', function () {
    $this->freezeTime();
    config(['notifications.mention_email.delay_minutes' => 30, 'notifications.mention_email.max_attempts' => 3]);
    $user = User::factory()->create();
    MessageMention::factory()->count(3)->for($user)->create(['created_at' => now()->subMinutes(35)]);
    Notification::fake();

    $this->artisan('mentions:send-email-digests')
        ->expectsOutput('users_processed=1 emails_sent=1 mentions_processed=3 failed=0')->assertExitCode(0);
    $this->artisan('mentions:send-email-digests')
        ->expectsOutput('users_processed=0 emails_sent=0 mentions_processed=0 failed=0')->assertExitCode(0);

    Notification::assertSentToTimes($user, MentionDigestNotification::class, 1);
    $this->assertDatabaseCount('notification_deliveries', 3);
});
