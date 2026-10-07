<?php

use App\Events\MessageReadsUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\User;
use App\Services\MessageReadService;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

test('a read batch broadcasts one private event with authoritative counts and no reader details', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $first = Message::factory()->for($channel)->create();
    $second = Message::factory()->for($channel)->create();
    $own = Message::factory()->for($user)->for($channel)->create();
    $foreign = Message::factory()->create();
    MessageRead::factory()->for($first)->create();
    Event::fake([MessageReadsUpdated::class]);
    $updates = [['message_id' => $first->id, 'read_count' => 2], ['message_id' => $second->id, 'read_count' => 1]];

    $this->actingAs($user)->postJson(route('messages.read'), ['message_ids' => [$second->id, $own->id, $first->id, $foreign->id]])
        ->assertOk()->assertExactJson(['success' => true, 'reads' => $updates]);

    $this->assertDatabaseCount('message_reads', 3);
    Event::assertDispatchedTimes(MessageReadsUpdated::class, 1);
    Event::assertDispatched(MessageReadsUpdated::class, fn (MessageReadsUpdated $event) => $event->broadcastOn()->name === 'private-company.general'
        && $event->broadcastAs() === 'MessageReadsUpdated'
        && $event->broadcastWith() === ['channel_id' => $channel->id, 'reads' => $updates]);
});

test('already read messages do not broadcast again and mixed batches only report new reads', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $first = Message::factory()->for($channel)->create();
    $second = Message::factory()->for($channel)->create();
    MessageRead::factory()->for($first)->for($user)->create();
    $firstReadAt = now()->toDateTimeString();
    $this->travel(5)->minutes();
    Event::fake([MessageReadsUpdated::class]);
    $payload = ['message_ids' => [$first->id, $second->id]];

    $this->actingAs($user)->postJson(route('messages.read'), $payload)
        ->assertExactJson(['success' => true, 'reads' => [['message_id' => $second->id, 'read_count' => 1]]]);
    $this->postJson(route('messages.read'), $payload)->assertExactJson(['success' => true, 'reads' => []]);

    $this->assertDatabaseCount('message_reads', 2);
    $this->assertDatabaseHas('message_reads', ['message_id' => $first->id, 'user_id' => $user->id, 'read_at' => $firstReadAt]);
    Event::assertDispatchedTimes(MessageReadsUpdated::class, 1);
});

test('own inaccessible and invalid read batches do not dispatch realtime updates', function () {
    $user = User::factory()->create();
    $own = Message::factory()->for($user)->for(Channel::factory()->general()->create())->create();
    $foreign = Message::factory()->create();
    Event::fake([MessageReadsUpdated::class]);

    $this->actingAs($user)->postJson(route('messages.read'), ['message_ids' => [$own->id, $foreign->id]])->assertOk();
    $this->postJson(route('messages.read'), ['message_ids' => [999999]])->assertUnprocessable();

    $this->assertDatabaseCount('message_reads', 0);
    Event::assertNotDispatched(MessageReadsUpdated::class);
});

test('read events wait for commit so readers can query the persisted receipts', function () {
    $user = User::factory()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create();
    Event::fake([MessageReadsUpdated::class]);

    DB::transaction(function () use ($user, $message) {
        app(MessageReadService::class)->markAsRead($user, [$message->id]);
        Event::assertNotDispatched(MessageReadsUpdated::class);
    });

    $this->assertDatabaseHas('message_reads', ['message_id' => $message->id, 'user_id' => $user->id]);
    Event::assertDispatchedTimes(MessageReadsUpdated::class, 1);
});

test('rolled back read batches do not emit realtime updates', function () {
    $user = User::factory()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create();
    Event::fake([MessageReadsUpdated::class]);

    expect(fn () => DB::transaction(function () use ($user, $message) {
        app(MessageReadService::class)->markAsRead($user, [$message->id]);
        throw new RuntimeException('Roll back the batch');
    }))->toThrow(RuntimeException::class, 'Roll back the batch');

    $this->assertDatabaseCount('message_reads', 0);
    Event::assertNotDispatched(MessageReadsUpdated::class);
});

test('broadcast outages preserve read records and return counts without failing the request', function () {
    $user = User::factory()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create();
    Exceptions::fake();
    $broadcaster = Mockery::mock(NullBroadcaster::class);
    $broadcaster->shouldReceive('broadcast')->once()->andThrow(new RuntimeException('Broadcast unavailable'));
    Broadcast::extend('failing', fn () => $broadcaster);
    config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);

    $this->actingAs($user)->postJson(route('messages.read'), ['message_ids' => [$message->id]])
        ->assertOk()->assertExactJson(['success' => true, 'reads' => [['message_id' => $message->id, 'read_count' => 1]]]);

    $this->assertDatabaseHas('message_reads', ['message_id' => $message->id, 'user_id' => $user->id]);
    Exceptions::assertReported(RuntimeException::class);
});
