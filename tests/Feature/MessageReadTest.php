<?php

use App\Events\MessageCreated;
use App\Events\MessageReadsUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\User;
use App\Services\MessageReadService;
use App\Services\MessageService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;

test('active users mark other messages in a batch with authenticated identity and server time', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $author = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $messages = Message::factory()->count(3)->for($author)->for($channel)->create();
    Event::fake([MessageCreated::class, MessageReadsUpdated::class]);
    Broadcast::shouldReceive('queue')->never();

    $this->actingAs($user)->postJson(route('messages.read'), [
        'message_ids' => $messages->modelKeys(),
        'user_id' => $author->id,
        'read_at' => '2000-01-01T00:00:00Z',
    ])->assertOk()->assertJsonPath('success', true)->assertJsonCount(3, 'reads');

    $this->assertDatabaseCount('message_reads', 3);
    foreach ($messages as $message) {
        $this->assertDatabaseHas('message_reads', ['message_id' => $message->id, 'user_id' => $user->id, 'read_at' => now()->toDateTimeString()]);
    }
    Event::assertNotDispatched(MessageCreated::class);
    Event::assertDispatchedTimes(MessageReadsUpdated::class, 1);
});

test('unauthenticated and inactive users cannot mark messages or list readers', function (string $state, int $status) {
    $channel = Channel::factory()->general()->create();
    $message = Message::factory()->for($channel)->create();
    if ($state === 'inactive') {
        $this->actingAs(User::factory()->inactive()->create());
    }

    $this->postJson(route('messages.read'), ['message_ids' => [$message->id]])->assertStatus($status);
    if ($state === 'inactive') {
        $this->actingAs(User::factory()->inactive()->create());
    }
    $this->getJson(route('messages.reads', $message))->assertStatus($status);

    $this->assertDatabaseCount('message_reads', 0);
})->with(['guest' => ['guest', 401], 'inactive' => ['inactive', 403]]);

test('own messages and inaccessible channels are ignored even in a mixed batch', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $own = Message::factory()->for($user)->for($channel)->create();
    $foreign = Message::factory()->create();
    $allowed = Message::factory()->for($channel)->create();

    $this->actingAs($user)->postJson(route('messages.read'), ['message_ids' => [$own->id, $foreign->id, $allowed->id]])->assertOk();

    $this->assertDatabaseCount('message_reads', 1);
    $this->assertDatabaseHas('message_reads', ['message_id' => $allowed->id, 'user_id' => $user->id]);
});

test('duplicate reads preserve the first read timestamp across repeated requests', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create();
    $payload = ['message_ids' => [$message->id]];
    $this->actingAs($user)->postJson(route('messages.read'), $payload)->assertOk();
    $firstReadAt = now()->toDateTimeString();
    $this->travel(5)->minutes();

    $this->postJson(route('messages.read'), $payload)->assertOk();

    $this->assertDatabaseCount('message_reads', 1);
    $this->assertDatabaseHas('message_reads', ['message_id' => $message->id, 'user_id' => $user->id, 'read_at' => $firstReadAt]);
});

test('invalid batches return 422 without creating reads', function (mixed $ids, string $field) {
    $user = User::factory()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create();
    $ids = $ids === 'duplicate' ? [$message->id, $message->id] : $ids;

    $this->actingAs($user)->postJson(route('messages.read'), ['message_ids' => $ids])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('message_reads', 0);
})->with([
    'missing' => [null, 'message_ids'],
    'empty' => [[], 'message_ids'],
    'not an array' => ['invalid', 'message_ids'],
    'over batch limit' => [range(1, 101), 'message_ids'],
    'duplicate IDs' => ['duplicate', 'message_ids.0'],
    'nonexistent ID' => [[999999], 'message_ids'],
    'non-integer ID' => [['invalid'], 'message_ids.0'],
    'nested ID' => [[[1]], 'message_ids.0'],
    'associative array' => [['message' => 1], 'message_ids'],
]);

test('deleted messages cannot be marked or expose readers', function () {
    $user = User::factory()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create();
    $message->delete();

    $this->actingAs($user)->postJson(route('messages.read'), ['message_ids' => [$message->id]])->assertUnprocessable();
    $this->getJson(route('messages.reads', $message))->assertNotFound();

    $this->assertDatabaseCount('message_reads', 0);
});

test('reader lists contain only user id name and first read time', function () {
    $this->freezeTime();
    $channel = Channel::factory()->general()->create();
    $message = Message::factory()->for($channel)->create();
    $reader = User::factory()->create();
    $secondReader = User::factory()->create();
    MessageRead::factory()->for($message)->for($reader)->create();
    $firstReadAt = now()->toIso8601String();
    $this->travel(1)->minutes();
    MessageRead::factory()->for($message)->for($secondReader)->create();
    MessageRead::factory()->create();

    $this->actingAs($message->user)->getJson(route('messages.reads', $message))->assertOk()->assertExactJson([
        'data' => [
            ['id' => $reader->id, 'name' => $reader->name, 'read_at' => $firstReadAt],
            ['id' => $secondReader->id, 'name' => $secondReader->name, 'read_at' => now()->toIso8601String()],
        ],
    ]);
});

test('reader list policy only allows active accounts to view accessible live messages', function () {
    $user = User::factory()->create();
    $inactive = User::factory()->inactive()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create();
    $foreign = Message::factory()->create();

    expect(Gate::forUser($user)->allows('view', $message))->toBeTrue();
    expect(Gate::forUser($inactive)->allows('view', $message))->toBeFalse();
    expect(Gate::forUser($user)->allows('view', $foreign))->toBeFalse();
    $this->actingAs($user)->getJson(route('messages.reads', $foreign))->assertNotFound();
});

test('read counts accompany message history detail send and Inertia responses without embedding readers', function () {
    $channel = Channel::factory()->general()->create();
    $message = Message::factory()->for($channel)->create();
    MessageRead::factory()->count(2)->for($message)->create();
    $user = User::factory()->create();
    Event::fake([MessageCreated::class]);

    $this->actingAs($user)->getJson(route('messages.index'))->assertJsonPath('data.0.read_count', 2)->assertJsonMissingPath('data.0.reads');
    $this->getJson(route('messages.show', $message))->assertJsonPath('data.read_count', 2)->assertJsonMissingPath('data.reads');
    $this->postJson(route('messages.store'), ['body' => 'Merhaba'])->assertCreated()->assertJsonPath('data.read_count', 0);
    $this->get(route('chat'))->assertInertia(fn (AssertableInertia $page) => $page->where('history.data.0.read_count', 2));
});

test('a maximum batch validates selects and inserts reads in a constant number of queries', function () {
    $user = User::factory()->create();
    $author = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $messages = Message::factory()->count(100)->for($author)->for($channel)->create();
    $this->actingAs($user);
    Event::fake([MessageReadsUpdated::class]);
    DB::enableQueryLog();

    $this->postJson(route('messages.read'), ['message_ids' => $messages->modelKeys()])->assertOk();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(5);
    Event::assertDispatchedTimes(MessageReadsUpdated::class, 1);
    $this->assertDatabaseCount('message_reads', 100);
});

test('message history counts do not query reads separately for each message and keep cursor pagination', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $messages = Message::factory()->count(45)->for($channel)->for($user)->create();
    MessageRead::factory()->for($messages[44])->create();
    DB::enableQueryLog();

    $history = app(MessageService::class)->history($channel);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(3);
    expect($history['data'])->toHaveCount(40);
    expect($history['data'][39]['read_count'])->toBe(1);
    expect($history['before_id'])->toBe($messages[5]->id);
    expect($history['after_id'])->toBe($messages[44]->id);
    expect(app(MessageService::class)->history($channel, $messages[5]->id)['data'])->toHaveCount(5);
});

test('the service refuses inactive own foreign and deleted messages without depending on request validation', function () {
    $inactive = User::factory()->inactive()->create();
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $own = Message::factory()->for($user)->for($channel)->create();
    $foreign = Message::factory()->create();
    $deleted = Message::factory()->for($channel)->create();
    $deleted->delete();
    $reads = app(MessageReadService::class);

    $reads->markAsRead($inactive, [$own->id]);
    $reads->markAsRead($user, [$own->id, $foreign->id, $deleted->id]);

    $this->assertDatabaseCount('message_reads', 0);
});

test('read records are removed when their message or reader is permanently deleted', function () {
    $read = MessageRead::factory()->create();

    $read->message->forceDelete();

    $this->assertDatabaseCount('message_reads', 0);
    $read = MessageRead::factory()->create();
    $read->user->delete();
    $this->assertDatabaseCount('message_reads', 0);
});
