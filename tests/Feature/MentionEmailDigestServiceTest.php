<?php

use App\Models\MessageMention;
use App\Models\MessageRead;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\MentionDigestNotification;
use App\Services\Notifications\MentionEmailDigestService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\Process;

beforeEach(function () {
    config(['notifications.mention_email.delay_minutes' => 30, 'notifications.mention_email.max_attempts' => 3]);
});

function createDigestMention(User $user, int $ageMinutes = 30): MessageMention
{
    return MessageMention::factory()->for($user)->create(['created_at' => now()->subMinutes($ageMinutes)]);
}

test('only mentions at least the configured age produce a digest', function (int $age, int $expected) {
    $this->freezeTime();
    $user = User::factory()->create();
    createDigestMention($user, $age);
    Notification::fake();

    expect(app(MentionEmailDigestService::class)->run())->toBe([
        'users_processed' => $expected, 'emails_sent' => $expected,
        'mentions_processed' => $expected, 'failed' => 0,
    ]);

    Notification::assertCount($expected);
    $this->assertDatabaseCount('notification_deliveries', $expected);
})->with(['older' => [31, 1], 'exact delay' => [30, 1], 'recent' => [29, 0]]);

test('the mention delay can be changed through configuration', function () {
    $this->freezeTime();
    config(['notifications.mention_email.delay_minutes' => 45]);
    $user = User::factory()->create();
    createDigestMention($user, 44);
    $eligible = createDigestMention($user, 45);
    Notification::fake();

    app(MentionEmailDigestService::class)->run();

    Notification::assertSentToTimes($user, MentionDigestNotification::class, 1);
    $this->assertDatabaseCount('notification_deliveries', 1);
    $this->assertDatabaseHas('notification_deliveries', ['reference_id' => $eligible->message_id, 'status' => 'sent']);
});

test('a mention already read by its recipient never sends mail', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $mention = createDigestMention($user);
    MessageRead::factory()->for($user)->create(['message_id' => $mention->message_id]);
    Notification::fake();

    app(MentionEmailDigestService::class)->run();

    Notification::assertNothingSent();
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('another user reading the message or the recipient reading a different message does not suppress mail', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $mention = createDigestMention($user);
    MessageRead::factory()->create(['message_id' => $mention->message_id]);
    MessageRead::factory()->for($user)->create();
    Notification::fake();

    app(MentionEmailDigestService::class)->run();

    Notification::assertSentToTimes($user, MentionDigestNotification::class, 1);
    $this->assertDatabaseHas('notification_deliveries', ['user_id' => $user->id, 'reference_id' => $mention->message_id, 'status' => 'sent']);
});

test('inactive recipients never receive mention email', function () {
    $this->freezeTime();
    createDigestMention(User::factory()->inactive()->create());
    Notification::fake();

    app(MentionEmailDigestService::class)->run();

    Notification::assertNothingSent();
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('soft deleted messages never produce mention email', function () {
    $this->freezeTime();
    $mention = createDigestMention(User::factory()->create());
    $mention->message->delete();
    Notification::fake();

    app(MentionEmailDigestService::class)->run();

    Notification::assertNothingSent();
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('sent pending and exhausted failed deliveries cannot be sent again', function (string $status, int $attempts) {
    $this->freezeTime();
    $user = User::factory()->create();
    $mention = createDigestMention($user);
    NotificationDelivery::factory()->for($user)->create([
        'reference_id' => $mention->message_id, 'status' => $status,
        'attempt_count' => $attempts, 'sent_at' => $status === 'sent' ? now() : null,
    ]);
    Notification::fake();

    expect(app(MentionEmailDigestService::class)->run()['emails_sent'])->toBe(0);

    Notification::assertNothingSent();
    $this->assertDatabaseCount('notification_deliveries', 1);
})->with(['sent' => ['sent', 1], 'pending' => ['pending', 0], 'exhausted' => ['failed', 3]]);

test('deliveries for other channels types or references do not suppress mention email', function (array $attributes) {
    $this->freezeTime();
    $user = User::factory()->create();
    $mention = createDigestMention($user);
    NotificationDelivery::factory()->for($user)->sent()->create(['reference_id' => $mention->message_id, ...$attributes]);
    Notification::fake();

    app(MentionEmailDigestService::class)->run();

    Notification::assertSentToTimes($user, MentionDigestNotification::class, 1);
    $this->assertDatabaseCount('notification_deliveries', 2);
})->with([
    'push' => [['channel' => 'push']], 'other type' => [['type' => 'announcement']],
    'other reference' => [['reference_type' => 'announcement']],
]);

test('five mentions create one digest and five sent deliveries and a later run sends nothing', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $mentions = MessageMention::factory()->count(5)->for($user)->create(['created_at' => now()->subMinutes(35)]);
    Notification::fake();

    expect(app(MentionEmailDigestService::class)->run())->toBe([
        'users_processed' => 1, 'emails_sent' => 1, 'mentions_processed' => 5, 'failed' => 0,
    ]);
    expect(app(MentionEmailDigestService::class)->run())->toBe([
        'users_processed' => 0, 'emails_sent' => 0, 'mentions_processed' => 0, 'failed' => 0,
    ]);

    Notification::assertSentTo($user, MentionDigestNotification::class, fn (MentionDigestNotification $notification, array $channels) => count($notification->items) === 5 && $channels === ['mail']);
    Notification::assertCount(1);
    $this->assertDatabaseCount('notification_deliveries', 5);
    foreach ($mentions as $mention) {
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $user->id, 'reference_id' => $mention->message_id, 'status' => 'sent',
            'attempt_count' => 1, 'sent_at' => now()->toDateTimeString(), 'last_attempt_at' => now()->toDateTimeString(),
        ]);
    }
    $this->assertDatabaseCount('notification_delivery_claims', 0);
});

test('separate recipients receive separate digests', function () {
    $this->freezeTime();
    $first = User::factory()->create();
    $second = User::factory()->create();
    createDigestMention($first);
    createDigestMention($second);
    Notification::fake();

    expect(app(MentionEmailDigestService::class)->run())->toBe([
        'users_processed' => 2, 'emails_sent' => 2, 'mentions_processed' => 2, 'failed' => 0,
    ]);

    Notification::assertSentToTimes($first, MentionDigestNotification::class, 1);
    Notification::assertSentToTimes($second, MentionDigestNotification::class, 1);
    $this->assertDatabaseCount('notification_deliveries', 2);
});

test('mentions read during delivery preparation are removed without counting an attempt', function (int $readCount) {
    $this->freezeTime();
    $user = User::factory()->create();
    $mentions = MessageMention::factory()->count(2)->for($user)->create(['created_at' => now()->subMinutes(35)]);
    $readIds = $mentions->take($readCount)->pluck('message_id')->all();
    Notification::fake();
    $interleaved = false;
    DB::listen(function (QueryExecuted $query) use ($user, $readIds, &$interleaved) {
        if (! $interleaved && str_starts_with(strtolower($query->sql), 'update')
            && str_contains($query->sql, 'notification_deliveries')
            && str_contains($query->sql, 'attempt_count + 1')) {
            $interleaved = true;
            foreach ($readIds as $messageId) {
                MessageRead::factory()->for($user)->create(['message_id' => $messageId]);
            }
        }
    });

    $summary = app(MentionEmailDigestService::class)->run();

    expect($interleaved)->toBeTrue();
    expect($summary)->toBe([
        'users_processed' => 1, 'emails_sent' => $readCount === 2 ? 0 : 1,
        'mentions_processed' => 2 - $readCount, 'failed' => 0,
    ]);
    Notification::assertCount($readCount === 2 ? 0 : 1);
    if ($readCount === 1) {
        Notification::assertSentTo($user, MentionDigestNotification::class, fn (MentionDigestNotification $notification) => count($notification->items) === 1);
    }
    $this->assertDatabaseCount('notification_deliveries', 2 - $readCount);
    $this->assertDatabaseCount('notification_delivery_claims', 0);
})->with(['one read' => 1, 'all read' => 2]);

test('deactivation deletion and mention removal immediately before mail suppress the digest', function (string $change) {
    $this->freezeTime();
    $user = User::factory()->create();
    $mention = createDigestMention($user);
    Notification::fake();
    $interleaved = false;
    DB::listen(function (QueryExecuted $query) use ($user, $mention, $change, &$interleaved) {
        if (! $interleaved && str_starts_with(strtolower($query->sql), 'update')
            && str_contains($query->sql, 'attempt_count + 1')) {
            $interleaved = true;
            match ($change) {
                'inactive' => $user->forceFill(['is_active' => false])->save(),
                'deleted' => $mention->message->delete(),
                'removed' => $mention->delete(),
            };
        }
    });

    expect(app(MentionEmailDigestService::class)->run()['emails_sent'])->toBe(0);

    expect($interleaved)->toBeTrue();
    Notification::assertNothingSent();
    $this->assertDatabaseCount('notification_deliveries', 0);
    $this->assertDatabaseCount('notification_delivery_claims', 0);
})->with(['inactive', 'deleted', 'removed']);

test('failed mail records one attempt and only the exception type without secrets or message content', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $mention = createDigestMention($user);
    $mention->message->update(['body' => 'Private message body']);
    Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('Authorization: Bearer re_PRIVATE_KEY raw email body Private message body'));
    Log::spy();

    expect(app(MentionEmailDigestService::class)->run())->toBe([
        'users_processed' => 1, 'emails_sent' => 0, 'mentions_processed' => 1, 'failed' => 1,
    ]);

    $this->assertDatabaseHas('notification_deliveries', [
        'user_id' => $user->id, 'reference_id' => $mention->message_id, 'status' => 'failed',
        'attempt_count' => 1, 'last_error' => RuntimeException::class,
        'last_attempt_at' => now()->toDateTimeString(), 'failed_at' => now()->toDateTimeString(), 'sent_at' => null,
    ]);
    $this->assertDatabaseCount('notification_delivery_claims', 0);
    Log::shouldHaveReceived('warning')->once()->with('Mention digest failed', [
        'user_id' => $user->id, 'mention_count' => 1, 'exception_type' => RuntimeException::class,
    ]);
});

test('failed mail retries to the configured limit and then stops', function () {
    $this->freezeTime();
    config(['notifications.mention_email.max_attempts' => 2]);
    $user = User::factory()->create();
    $mention = createDigestMention($user);
    Notification::shouldReceive('sendNow')->twice()->andThrow(new RuntimeException('Temporary failure'));

    $service = app(MentionEmailDigestService::class);
    expect($service->run()['failed'])->toBe(1);
    expect($service->run()['failed'])->toBe(1);
    expect($service->run()['users_processed'])->toBe(0);

    $this->assertDatabaseHas('notification_deliveries', [
        'reference_id' => $mention->message_id, 'user_id' => $user->id, 'status' => 'failed', 'attempt_count' => 2,
    ]);
    $this->assertDatabaseCount('notification_deliveries', 1);
});

test('a successful retry updates the existing delivery and clears failure details', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $mention = createDigestMention($user);
    $delivery = NotificationDelivery::factory()->for($user)->failed()->create(['reference_id' => $mention->message_id]);
    Notification::fake();

    expect(app(MentionEmailDigestService::class)->run()['emails_sent'])->toBe(1);

    Notification::assertSentToTimes($user, MentionDigestNotification::class, 1);
    $this->assertDatabaseHas('notification_deliveries', [
        'id' => $delivery->id, 'status' => 'sent', 'attempt_count' => 2, 'sent_at' => now()->toDateTimeString(),
        'failed_at' => null, 'last_error' => null,
    ]);
    $this->assertDatabaseCount('notification_deliveries', 1);
});

test('a retry read just before sending keeps its original failed attempt count', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $mention = createDigestMention($user);
    $delivery = NotificationDelivery::factory()->for($user)->failed()->create(['reference_id' => $mention->message_id]);
    Notification::fake();
    $interleaved = false;
    DB::listen(function (QueryExecuted $query) use ($user, $mention, &$interleaved) {
        if (! $interleaved && str_starts_with(strtolower($query->sql), 'update')
            && str_contains($query->sql, 'attempt_count + 1')) {
            $interleaved = true;
            MessageRead::factory()->for($user)->create(['message_id' => $mention->message_id]);
        }
    });

    app(MentionEmailDigestService::class)->run();

    Notification::assertNothingSent();
    $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'failed', 'attempt_count' => 1]);
    $this->assertDatabaseCount('notification_delivery_claims', 0);
});

test('a failed recipient does not interrupt another recipients digest', function () {
    $this->freezeTime();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $firstMention = createDigestMention($first);
    $secondMention = createDigestMention($second);
    Notification::shouldReceive('sendNow')->twice()->andReturnUsing(function (User $recipient) use ($first) {
        if ($recipient->id === $first->id) {
            throw new RuntimeException('Temporary failure');
        }
    });

    expect(app(MentionEmailDigestService::class)->run())->toBe([
        'users_processed' => 2, 'emails_sent' => 1, 'mentions_processed' => 2, 'failed' => 1,
    ]);

    $this->assertDatabaseHas('notification_deliveries', ['reference_id' => $firstMention->message_id, 'user_id' => $first->id, 'status' => 'failed']);
    $this->assertDatabaseHas('notification_deliveries', ['reference_id' => $secondMention->message_id, 'user_id' => $second->id, 'status' => 'sent']);
});

test('an overlapping run cannot send a second digest even when a new eligible mention appears', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    createDigestMention($user);
    $overlapping = null;
    $newMention = null;
    $transactionLevel = DB::transactionLevel();
    Notification::shouldReceive('sendNow')->once()->andReturnUsing(function () use ($user, &$overlapping, &$newMention, $transactionLevel) {
        expect(DB::transactionLevel())->toBe($transactionLevel);
        $newMention = createDigestMention($user);
        $overlapping = app(MentionEmailDigestService::class)->run();
    });

    expect(app(MentionEmailDigestService::class)->run()['emails_sent'])->toBe(1);

    expect($overlapping)->toBe(['users_processed' => 0, 'emails_sent' => 0, 'mentions_processed' => 0, 'failed' => 0]);
    $this->assertDatabaseCount('notification_deliveries', 1);
    $this->assertDatabaseMissing('notification_deliveries', ['user_id' => $user->id, 'reference_id' => $newMention->message_id]);
    $this->assertDatabaseCount('notification_delivery_claims', 0);
});

test('an existing durable claim blocks an overlapping or crashed sender', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    createDigestMention($user);
    DB::table('notification_delivery_claims')->insert([
        'user_id' => $user->id, 'type' => 'mention', 'channel' => 'email',
        'token' => '97bcd519-cc2c-4bb9-9bb9-4ccf9fba201d', 'created_at' => now()->subDay(),
    ]);
    Notification::fake();

    expect(app(MentionEmailDigestService::class)->run()['users_processed'])->toBe(0);

    Notification::assertNothingSent();
    $this->assertDatabaseCount('notification_delivery_claims', 1);
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('a persistence failure after mail acceptance keeps the pending claim and never resends automatically', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    createDigestMention($user);
    Notification::fake();
    $interleaved = false;
    DB::listen(function (QueryExecuted $query) use (&$interleaved) {
        if (! $interleaved && str_starts_with(strtolower($query->sql), 'update')
            && str_contains($query->sql, 'notification_deliveries') && in_array('sent', $query->bindings, true)) {
            $interleaved = true;
            DB::table('notification_deliveries')->update(['status' => 'pending', 'sent_at' => null]);
            throw new RuntimeException('Persistence unavailable after provider acceptance');
        }
    });

    expect(app(MentionEmailDigestService::class)->run()['failed'])->toBe(1);
    expect(app(MentionEmailDigestService::class)->run()['emails_sent'])->toBe(0);

    Notification::assertCount(1);
    $this->assertDatabaseHas('notification_deliveries', ['status' => 'pending', 'attempt_count' => 1, 'sent_at' => null]);
    $this->assertDatabaseCount('notification_delivery_claims', 1);
});

test('query count stays bounded as a users digest grows and all senders are loaded in a batch', function (int $count) {
    $this->freezeTime();
    $user = User::factory()->create();
    MessageMention::factory()->count($count)->for($user)->create(['created_at' => now()->subMinutes(35)]);
    Notification::fake();
    DB::enableQueryLog();

    app(MentionEmailDigestService::class)->run();

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queryCount)->toBeLessThanOrEqual(18);
    Notification::assertSentTo($user, MentionDigestNotification::class, fn (MentionDigestNotification $notification) => count($notification->items) === $count);
    $this->assertDatabaseCount('notification_deliveries', $count);
})->with(['one mention' => 1, 'hundred mentions' => 100, 'multiple write chunks' => 250]);

test('two PHP processes sharing a database cannot send simultaneous digests for the same user', function () {
    $databaseFile = tempnam(sys_get_temp_dir(), 'mention-database-');
    $mailFile = tempnam(sys_get_temp_dir(), 'mention-mail-');
    $releaseFile = tempnam(sys_get_temp_dir(), 'mention-release-');
    unlink($releaseFile);
    $originalConnection = DB::getDefaultConnection();
    config(['database.connections.digest_concurrency' => [
        ...config('database.connections.sqlite'), 'database' => $databaseFile, 'url' => null, 'busy_timeout' => 5000,
    ]]);
    $first = null;
    $second = null;

    $worker = <<<'PHP'
    require $argv[1].'/vendor/autoload.php';
    $app = require $argv[1].'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[2],
        'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
        'notifications.mention_email.delay_minutes' => 30, 'notifications.mention_email.max_attempts' => 3,
        'logging.default' => 'null',
    ]);
    Illuminate\Support\Facades\DB::purge('sqlite');
    Illuminate\Support\Facades\Notification::shouldReceive('sendNow')->andReturnUsing(function () use ($argv): void {
        file_put_contents($argv[3], "sent\n", FILE_APPEND | LOCK_EX);
        if ($argv[5] === 'hold') {
            echo "READY\n";
            flush();
            $deadline = microtime(true) + 10;
            while (! file_exists($argv[4])) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Test worker release timed out');
                }
                usleep(10000);
            }
        }
    });
    echo json_encode($app->make(App\Services\Notifications\MentionEmailDigestService::class)->run())."\n";
    PHP;

    try {
        expect(Artisan::call('migrate', ['--database' => 'digest_concurrency', '--no-interaction' => true]))->toBe(0);
        DB::setDefaultConnection('digest_concurrency');
        $user = User::factory()->create();
        createDigestMention($user, 35);
        $first = new Process([PHP_BINARY, '-r', $worker, base_path(), $databaseFile, $mailFile, $releaseFile, 'hold'], base_path());
        $first->setTimeout(15)->start();
        expect($first->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'READY')))->toBeTrue();

        $newMention = createDigestMention($user, 35);
        $second = new Process([PHP_BINARY, '-r', $worker, base_path(), $databaseFile, $mailFile, $releaseFile, 'run'], base_path());
        $second->setTimeout(15)->mustRun();

        expect(json_decode(trim($second->getOutput()), true))->toBe([
            'users_processed' => 0, 'emails_sent' => 0, 'mentions_processed' => 0, 'failed' => 0,
        ]);
        file_put_contents($releaseFile, 'release');
        expect($first->wait())->toBe(0);
        expect(file_get_contents($mailFile))->toBe("sent\n");
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertDatabaseHas('notification_deliveries', ['user_id' => $user->id, 'status' => 'sent', 'attempt_count' => 1]);
        $this->assertDatabaseMissing('notification_deliveries', ['user_id' => $user->id, 'reference_id' => $newMention->message_id]);
        $this->assertDatabaseCount('notification_delivery_claims', 0);
    } finally {
        $first?->stop(0);
        $second?->stop(0);
        DB::setDefaultConnection($originalConnection);
        DB::purge('digest_concurrency');
        foreach ([$databaseFile, $mailFile, $releaseFile] as $temporaryFile) {
            if (file_exists($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }
});
