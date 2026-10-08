<?php

use App\Models\MessageMention;
use App\Models\User;
use App\Notifications\MentionDigestNotification;
use App\Services\Notifications\MentionEmailDigestService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\mock;

beforeEach(function () {
    config([
        'internal_tasks.secret' => 'test-only-internal-task-secret',
        'internal_tasks.max_skew_seconds' => 300,
        'notifications.mention_email.delay_minutes' => 30,
        'notifications.mention_email.max_attempts' => 3,
    ]);
});

function signedTaskServerVariables(string $body = '{}', ?int $timestamp = null, string $nonce = 'a6b2b0d2-66fe-4cc6-a36c-676af31729b8'): array
{
    $timestamp = (string) ($timestamp ?? now()->getTimestamp());

    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TASK_TIMESTAMP' => $timestamp,
        'HTTP_X_TASK_NONCE' => $nonce,
        'HTTP_X_TASK_SIGNATURE' => hash_hmac('sha256', $timestamp."\n".$nonce."\n".$body, config('internal_tasks.secret')),
    ];
}

test('missing task headers return generic 401 without calling the service', function () {
    mock(MentionEmailDigestService::class)->shouldReceive('run')->never();

    $this->post('/internal/tasks/mention-email-digests')
        ->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
});

test('incorrect or malformed signatures timestamps and nonces return 401', function (string $header, string $value) {
    $this->freezeTime();
    $headers = signedTaskServerVariables();
    $headers[$header] = $value;
    mock(MentionEmailDigestService::class)->shouldReceive('run')->never();

    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')
        ->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
})->with([
    'wrong signature' => ['HTTP_X_TASK_SIGNATURE', str_repeat('0', 64)],
    'invalid hex' => ['HTTP_X_TASK_SIGNATURE', str_repeat('z', 64)],
    'short signature' => ['HTTP_X_TASK_SIGNATURE', 'abc'],
    'missing signature' => ['HTTP_X_TASK_SIGNATURE', ''],
    'nonnumeric timestamp' => ['HTTP_X_TASK_TIMESTAMP', 'now'],
    'oversized timestamp' => ['HTTP_X_TASK_TIMESTAMP', '999999999999999999999'],
    'missing timestamp' => ['HTTP_X_TASK_TIMESTAMP', ''],
    'missing nonce' => ['HTTP_X_TASK_NONCE', ''],
    'short nonce' => ['HTTP_X_TASK_NONCE', 'short'],
    'invalid nonce characters' => ['HTTP_X_TASK_NONCE', str_repeat('/', 32)],
    'oversized nonce' => ['HTTP_X_TASK_NONCE', str_repeat('a', 129)],
]);

test('correctly signed stale and future timestamps outside the configured skew return 401', function (int $offset) {
    $this->freezeTime();
    $headers = signedTaskServerVariables(timestamp: now()->getTimestamp() + $offset);
    mock(MentionEmailDigestService::class)->shouldReceive('run')->never();

    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')
        ->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
})->with(['expired' => -301, 'too far ahead' => 301]);

test('a valid signature at the permitted timestamp boundary calls the shared service and returns safe JSON', function (int $offset) {
    $this->freezeTime();
    $headers = signedTaskServerVariables(timestamp: now()->getTimestamp() + $offset);
    mock(MentionEmailDigestService::class)->shouldReceive('run')->once()->andReturn([
        'users_processed' => 3, 'emails_sent' => 2, 'mentions_processed' => 5, 'failed' => 1,
    ]);

    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')
        ->assertOk()->assertExactJson([
            'status' => 'ok', 'users_processed' => 3, 'emails_sent' => 2, 'mentions_processed' => 5, 'failed' => 1,
        ]);
})->with(['current' => 0, 'old boundary' => -300, 'future boundary' => 300]);

test('configured timestamp skew replaces the default tolerance', function () {
    $this->freezeTime();
    config(['internal_tasks.max_skew_seconds' => 60]);
    $headers = signedTaskServerVariables(timestamp: now()->getTimestamp() - 61);
    mock(MentionEmailDigestService::class)->shouldReceive('run')->never();

    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')->assertUnauthorized();
});

test('replayed nonce returns 401 even with a new valid timestamp or body signature', function () {
    $this->freezeTime();
    $headers = signedTaskServerVariables();
    mock(MentionEmailDigestService::class)->shouldReceive('run')->once()->andReturn([
        'users_processed' => 0, 'emails_sent' => 0, 'mentions_processed' => 0, 'failed' => 0,
    ]);

    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')->assertOk();
    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')->assertUnauthorized();
    $headers = signedTaskServerVariables('{"changed":true}', now()->getTimestamp() + 1);
    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{"changed":true}')->assertUnauthorized();
});

test('a future timestamp nonce remains protected for the entire signature validity window', function () {
    $this->freezeTime();
    $headers = signedTaskServerVariables(timestamp: now()->getTimestamp() + 300);
    mock(MentionEmailDigestService::class)->shouldReceive('run')->once()->andReturn([
        'users_processed' => 0, 'emails_sent' => 0, 'mentions_processed' => 0, 'failed' => 0,
    ]);

    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')->assertOk();
    $this->travel(599)->seconds();
    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')->assertUnauthorized();
});

test('changing raw body bytes including whitespace invalidates the signature without consuming the nonce', function () {
    $this->freezeTime();
    $body = '{"task":"digest"}';
    $headers = signedTaskServerVariables($body);
    mock(MentionEmailDigestService::class)->shouldReceive('run')->once()->andReturn([
        'users_processed' => 0, 'emails_sent' => 0, 'mentions_processed' => 0, 'failed' => 0,
    ]);

    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{ "task":"digest"}')->assertUnauthorized();
    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, $body)->assertOk();
});

test('a missing or blank internal secret fails closed with generic 401', function (?string $secret) {
    $this->freezeTime();
    $headers = signedTaskServerVariables();
    config(['internal_tasks.secret' => $secret]);
    mock(MentionEmailDigestService::class)->shouldReceive('run')->never();

    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')
        ->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
})->with(['missing' => null, 'empty' => '', 'whitespace' => '   ']);

test('cache failure returns 401 without running the task or exposing the exception message', function () {
    $this->freezeTime();
    $headers = signedTaskServerVariables();
    Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('re_PRIVATE_KEY provider detail'));
    Log::spy();
    mock(MentionEmailDigestService::class)->shouldReceive('run')->never();

    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')
        ->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);

    Log::shouldHaveReceived('warning')->once()->with('Internal task nonce storage failed', ['exception_type' => RuntimeException::class]);
});

test('signed endpoint sends a digest without login session or CSRF and a replay cannot resend', function () {
    $this->freezeTime();
    config(['app.url' => 'https://configured.example.test']);
    $user = User::factory()->create();
    MessageMention::factory()->count(2)->for($user)->create(['created_at' => now()->subMinutes(35)]);
    Notification::fake();
    $headers = signedTaskServerVariables();
    $headers['HTTP_HOST'] = 'attacker.example.test';
    unset($headers['HTTP_ACCEPT']);

    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')
        ->assertOk()->assertExactJson([
            'status' => 'ok', 'users_processed' => 1, 'emails_sent' => 1, 'mentions_processed' => 2, 'failed' => 0,
        ]);
    $this->call('POST', '/internal/tasks/mention-email-digests', [], [], [], $headers, '{}')->assertUnauthorized();

    $this->assertGuest();
    Notification::assertSentTo($user, MentionDigestNotification::class, fn (MentionDigestNotification $notification) => $notification->chatUrl === 'https://configured.example.test/chat');
    Notification::assertCount(1);
    $this->assertDatabaseCount('notification_deliveries', 2);
});

test('a normal authenticated administrator cannot bypass the internal signature', function () {
    $this->actingAs(User::factory()->admin()->create());
    mock(MentionEmailDigestService::class)->shouldReceive('run')->never();

    $this->postJson('/internal/tasks/mention-email-digests')->assertUnauthorized();
});

test('internal task endpoint only accepts POST', function () {
    mock(MentionEmailDigestService::class)->shouldReceive('run')->never();

    $this->getJson('/internal/tasks/mention-email-digests')->assertStatus(405);
});
