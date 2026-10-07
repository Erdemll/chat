<?php

use App\Events\MessageCreated;
use App\Exceptions\MessageRejectedException;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageService;
use App\Services\Moderation\ModerationResult;
use App\Services\Moderation\ProfanityEngine;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['moderation.enabled' => true]);
});

test('profanity returns a generic 422 without storing dispatching or broadcasting and logs only safe metadata', function () {
    $user = User::factory()->create();
    Channel::factory()->general()->create();
    $events = [];
    Event::listen(MessageCreated::class, function (MessageCreated $event) use (&$events): void {
        $events[] = $event;
    });
    Broadcast::shouldReceive('queue')->never();
    Log::shouldReceive('notice')->once()->with('Message blocked by moderation', [
        'user_id' => $user->id,
        'category' => 'profanity',
        'exception_type' => MessageRejectedException::class,
    ]);
    $error = 'Mesajınız şirket iletişim kurallarına uygun olmadığı için gönderilemedi.';

    $this->actingAs($user)->postJson(route('messages.store'), ['body' => 'siktir git'])
        ->assertUnprocessable()
        ->assertExactJson(['message' => $error, 'errors' => ['body' => [$error]]]);

    $this->assertDatabaseCount('messages', 0);
    expect($events)->toBeEmpty();
});

test('rejected messages throw an application exception before any database transaction begins', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    Event::fake([TransactionBeginning::class, MessageCreated::class]);
    $messages = app(MessageService::class);

    expect(fn () => $messages->send($user, $channel, 'siktir git'))
        ->toThrow(MessageRejectedException::class, 'Message blocked by moderation');

    $this->assertDatabaseCount('messages', 0);
    Event::assertNotDispatched(TransactionBeginning::class);
    Event::assertNotDispatched(MessageCreated::class);
});

test('normal messages reach the realtime broadcaster after persistence with the existing private payload', function () {
    config(['broadcasting.default' => 'reverb']);
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $transactionLevel = DB::transactionLevel();
    $events = [];
    Event::listen(MessageCreated::class, function (MessageCreated $event) use (&$events): void {
        $events[] = $event;
    });
    $published = null;
    $broadcaster = Mockery::mock(Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->once()->andReturnUsing(
        function (array $channels, string $event, array $payload) use (&$published): void {
            $published = [
                'channels' => array_map(fn ($channel): string => $channel->name, $channels),
                'event' => $event,
                'payload' => $payload,
                'transaction_level' => DB::transactionLevel(),
                'stored_body' => Message::query()->findOrFail($payload['message_id'])->body,
            ];
        },
    );
    Broadcast::extend('reverb', fn (): Broadcaster => $broadcaster);

    $response = $this->actingAs($user)->postJson(route('messages.store'), ['body' => '  merhaba dunya  '])
        ->assertCreated()
        ->assertJsonPath('data.body', 'merhaba dunya')
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('realtime', true);

    $messageId = $response->json('data.id');
    $this->assertDatabaseHas('messages', ['id' => $messageId, 'body' => 'merhaba dunya', 'user_id' => $user->id, 'channel_id' => $channel->id]);
    expect($events)->toHaveCount(1);
    expect($events[0]->message->id)->toBe($messageId);
    expect($published)->toBe([
        'channels' => ['private-company.general'],
        'event' => 'MessageCreated',
        'payload' => ['message_id' => $messageId, 'channel_id' => $channel->id, 'socket' => null],
        'transaction_level' => $transactionLevel,
        'stored_body' => 'merhaba dunya',
    ]);
});

test('disabled moderation skips the engine and preserves profanity messages and their event', function () {
    config(['moderation.enabled' => false]);
    $this->app->instance(ProfanityEngine::class, new class implements ProfanityEngine
    {
        public function check(string $text): ModerationResult
        {
            throw new RuntimeException('Disabled moderation must not call the engine.');
        }
    });
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);

    $this->actingAs($user)->postJson(route('messages.store'), ['body' => 'siktir git'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'siktir git');

    $this->assertDatabaseHas('messages', ['body' => 'siktir git', 'user_id' => $user->id, 'channel_id' => $channel->id]);
    Event::assertDispatched(MessageCreated::class, fn (MessageCreated $event): bool => $event->message->body === 'siktir git');
});
