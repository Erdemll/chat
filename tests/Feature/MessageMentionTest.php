<?php

use App\Events\MessageCreated;
use App\Events\MessageReadsUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\User;
use App\Services\MessageMentionService;
use App\Services\MessageReadService;
use App\Services\MessageService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('active employees select mentionable coworkers using one query and a minimal response', function () {
    $actor = User::factory()->create();
    $first = User::factory()->create(['name' => 'Ahmet Yılmaz']);
    $second = User::factory()->create(['name' => 'Zeynep Kaya']);
    User::factory()->inactive()->create();
    $this->actingAs($actor);
    DB::enableQueryLog();

    $this->getJson(route('users.mentionable'))->assertOk()->assertExactJson(['data' => [
        ['id' => $first->id, 'name' => $first->name], ['id' => $second->id, 'name' => $second->name],
    ]]);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1);
});

test('guest and inactive accounts cannot access mentionable employees', function (string $state, int $status) {
    if ($state === 'inactive') {
        $this->actingAs(User::factory()->inactive()->create());
    }
    $this->getJson(route('users.mentionable'))->assertStatus($status);
})->with(['guest' => ['guest', 401], 'inactive' => ['inactive', 403]]);

test('a message stores mentions with server time and exposes only coworker id and name', function () {
    $this->freezeTime();
    $actor = User::factory()->create();
    $target = User::factory()->create(['name' => 'Ahmet Yılmaz']);
    $channel = Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);
    Mail::fake();
    Notification::fake();

    $response = $this->actingAs($actor)->postJson(route('messages.store'), [
        'body' => '@Ahmet Yılmaz rapora bakabilir misin?', 'mentions' => [$target->id],
        'user_id' => $target->id, 'created_at' => '2000-01-01',
    ])->assertCreated()->assertJsonPath('data.mentions', [['id' => $target->id, 'name' => $target->name]])
        ->assertJsonPath('data.read_count', 0)->assertJsonPath('data.user.id', $actor->id);
    $messageId = $response->json('data.id');

    $this->assertDatabaseCount('message_mentions', 1);
    $this->assertDatabaseHas('message_mentions', ['message_id' => $messageId, 'user_id' => $target->id, 'created_at' => now()->toDateTimeString()]);
    expect($target->messageMentions()->first()->message_id)->toBe($messageId);
    expect(Message::findOrFail($messageId)->mentions()->first()->user_id)->toBe($target->id);
    Event::assertDispatchedTimes(MessageCreated::class, 1);
    Event::assertDispatched(MessageCreated::class, fn (MessageCreated $event) => $event->broadcastWith() === ['message_id' => $messageId, 'channel_id' => $channel->id]);
    Mail::assertNothingSent();
    Notification::assertNothingSent();
    $this->getJson(route('messages.show', $messageId))->assertJsonPath('data.mentions', [['id' => $target->id, 'name' => $target->name]]);
});

test('self inactive and nonexistent mention IDs return 422 and do not store messages', function (string $state) {
    $actor = User::factory()->create();
    $targetId = match ($state) {
        'self' => $actor->id,
        'inactive' => User::factory()->inactive()->create()->id,
        default => 999999,
    };
    Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);

    $this->actingAs($actor)->postJson(route('messages.store'), ['body' => 'Merhaba', 'mentions' => [$targetId]])
        ->assertUnprocessable()->assertJsonValidationErrors('mentions');

    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('message_mentions', 0);
    Event::assertNotDispatched(MessageCreated::class);
})->with(['self', 'inactive', 'nonexistent']);

test('invalid mention shapes and duplicate IDs fail validation safely', function (mixed $ids, string $field) {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    Channel::factory()->general()->create();
    $ids = $ids === 'duplicate' ? [$target->id, $target->id] : $ids;
    Event::fake([MessageCreated::class]);

    $this->actingAs($actor)->postJson(route('messages.store'), ['body' => 'Merhaba', 'mentions' => $ids])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('message_mentions', 0);
    Event::assertNotDispatched(MessageCreated::class);
})->with([
    'scalar' => ['invalid', 'mentions'], 'over limit' => [range(1, 21), 'mentions'],
    'duplicate' => ['duplicate', 'mentions.0'], 'string item' => [['invalid'], 'mentions.0'],
    'nested item' => [[[1]], 'mentions.0'], 'null item' => [[null], 'mentions.0'],
    'associative array' => [['user' => 1], 'mentions'],
]);

test('messages without mentions preserve the existing creation flow', function (string $state) {
    $actor = User::factory()->create();
    Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);
    $payload = ['body' => 'Normal Türkçe mesaj'];
    if ($state !== 'missing') {
        $payload['mentions'] = $state === 'null' ? null : [];
    }

    $this->actingAs($actor)->postJson(route('messages.store'), $payload)->assertCreated()->assertJsonPath('data.mentions', []);

    $this->assertDatabaseCount('messages', 1);
    $this->assertDatabaseCount('message_mentions', 0);
    Event::assertDispatchedTimes(MessageCreated::class, 1);
})->with(['missing', 'null', 'empty']);

test('profanity blocked messages never create mentions or broadcast', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);

    $this->actingAs($actor)->postJson(route('messages.store'), ['body' => 'siktir', 'mentions' => [$target->id]])->assertUnprocessable()->assertJsonValidationErrors('body');

    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('message_mentions', 0);
    Event::assertNotDispatched(MessageCreated::class);
});

test('mention write failures roll back both the message and already inserted mentions', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);
    Exceptions::fake();
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'message_mentions')) {
            throw new RuntimeException('Simulated mention write failure');
        }
    });

    $this->actingAs($actor)->postJson(route('messages.store'), ['body' => 'Merhaba', 'mentions' => [$target->id]])->assertInternalServerError();

    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('message_mentions', 0);
    Event::assertNotDispatched(MessageCreated::class);
    Exceptions::assertReported(RuntimeException::class);
});

test('an outer transaction rollback removes mentions and suppresses MessageCreated', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);

    expect(fn () => DB::transaction(function () use ($actor, $target, $channel) {
        app(MessageService::class)->send($actor, $channel, 'Merhaba', [$target->id]);
        $this->assertDatabaseCount('message_mentions', 1);
        throw new RuntimeException('Roll back message');
    }))->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('message_mentions', 0);
    Event::assertNotDispatched(MessageCreated::class);
});

test('the mention service filters duplicates self inactive and invalid IDs independently of validation', function () {
    $this->freezeTime();
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $inactive = User::factory()->inactive()->create();
    $message = Message::factory()->for($actor)->for(Channel::factory()->general()->create())->create();
    $service = app(MessageMentionService::class);

    $service->attach($message, $actor, [$target->id, $target->id, $actor->id, $inactive->id, 999999]);
    $firstCreatedAt = now()->toDateTimeString();
    $this->travel(5)->minutes();
    $service->attach($message, $actor, [$target->id]);

    $this->assertDatabaseCount('message_mentions', 1);
    $this->assertDatabaseHas('message_mentions', ['message_id' => $message->id, 'user_id' => $target->id, 'created_at' => $firstCreatedAt]);
});

test('a maximum mention list uses one eligibility query and one batch insert', function () {
    $actor = User::factory()->create();
    $targets = User::factory()->count(20)->create();
    $message = Message::factory()->for($actor)->for(Channel::factory()->general()->create())->create();
    $service = app(MessageMentionService::class);
    DB::enableQueryLog();

    $service->attach($message, $actor, $targets->modelKeys());

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(2);
    $this->assertDatabaseCount('message_mentions', 20);
    Event::fake([MessageCreated::class]);
    $this->actingAs($actor)->postJson(route('messages.store'), ['body' => 'Merhaba', 'mentions' => $targets->modelKeys()])
        ->assertCreated()->assertJsonCount(20, 'data.mentions');
    $this->assertDatabaseCount('message_mentions', 40);
    Event::assertDispatchedTimes(MessageCreated::class, 1);
});

test('history eager loads mentions in constant queries and retains before and after cursors', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $messages = Message::factory()->count(45)->for($actor)->for($channel)->create();
    foreach ($messages as $message) {
        MessageMention::factory()->for($message)->for($target)->create();
    }
    $service = app(MessageService::class);
    DB::enableQueryLog();

    $history = $service->history($channel);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(3);
    expect($history['data'])->toHaveCount(40);
    expect($history['data'][0]['mentions'])->toBe([['id' => $target->id, 'name' => $target->name]]);
    expect($history['before_id'])->toBe($messages[5]->id);
    expect($service->history($channel, $messages[5]->id)['data'])->toHaveCount(5);
    expect($service->history($channel, null, $messages[39]->id)['data'])->toHaveCount(5);
    $this->actingAs($actor)->get(route('chat'))->assertInertia(fn (AssertableInertia $page) => $page->where('history.data.0.mentions.0.id', $target->id));
});

test('unread mentions use the mentioned users existing message_reads records', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $otherReader = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    Event::fake([MessageCreated::class, MessageReadsUpdated::class]);
    $message = app(MessageService::class)->send($actor, $channel, 'Merhaba', [$target->id])['message'];
    $reads = app(MessageReadService::class);
    $reads->markAsRead($otherReader, [$message->id]);

    expect(MessageMention::query()->unreadFor($target)->count())->toBe(1);
    expect(MessageMention::query()->unreadFor($otherReader)->count())->toBe(0);
    $reads->markAsRead($target, [$message->id]);

    expect(MessageMention::query()->unreadFor($target)->count())->toBe(0);
    $this->assertDatabaseCount('message_mentions', 1);
    $this->assertDatabaseCount('message_reads', 2);
    Event::assertDispatchedTimes(MessageCreated::class, 1);
    Event::assertDispatchedTimes(MessageReadsUpdated::class, 2);
});

test('mention records cascade when a message or mentioned employee is permanently removed', function () {
    $mention = MessageMention::factory()->create();
    $mention->message->forceDelete();
    $this->assertDatabaseCount('message_mentions', 0);
    $mention = MessageMention::factory()->create();
    $mention->user->delete();
    $this->assertDatabaseCount('message_mentions', 0);
});

test('unread mentions exclude deleted messages inaccessible channels and inactive employees', function () {
    $target = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $deleted = Message::factory()->for($channel)->create();
    MessageMention::factory()->for($deleted)->for($target)->create();
    $deleted->delete();
    MessageMention::factory()->for($target)->create();
    $active = Message::factory()->for($channel)->create();
    MessageMention::factory()->for($active)->for($target)->create();

    expect(MessageMention::query()->unreadFor($target)->count())->toBe(1);
    $target->is_active = false;
    $target->save();
    expect(MessageMention::query()->unreadFor($target)->count())->toBe(0);
});

test('the mention service rejects inactive actors and messages belonging to another author', function (string $state) {
    $owner = User::factory()->create();
    $target = User::factory()->create();
    $message = Message::factory()->for($owner)->create();
    $actor = $state === 'inactive' ? $owner : User::factory()->create();
    if ($state === 'inactive') {
        $actor->is_active = false;
        $actor->save();
    }

    expect(fn () => app(MessageMentionService::class)->attach($message, $actor, [$target->id]))
        ->toThrow(HttpException::class);

    $this->assertDatabaseCount('message_mentions', 0);
})->with(['inactive', 'other author']);
