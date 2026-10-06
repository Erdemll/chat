<?php

use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

test('active users send trimmed plain text in the default channel using authenticated identity', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $otherChannel = Channel::factory()->create();
    Event::fake([MessageCreated::class]);
    $this->actingAs($user)->postJson(route('messages.store'), ['body' => '  <script>alert(1)</script> Merhaba  ', 'user_id' => $other->id, 'channel_id' => $otherChannel->id])->assertCreated()->assertJsonPath('data.body', '<script>alert(1)</script> Merhaba')->assertJsonPath('data.user.id', $user->id)->assertJsonMissingPath('data.user.email');
    $this->assertDatabaseHas('messages', ['body' => '<script>alert(1)</script> Merhaba', 'user_id' => $user->id, 'channel_id' => $channel->id]);
    Event::assertDispatched(MessageCreated::class, fn ($event) => $event->message->user_id === $user->id && $event->broadcastOn()->name === 'private-company.general' && $event->broadcastWith() === ['message_id' => $event->message->id, 'channel_id' => $channel->id]);
});

test('unauthenticated users cannot read or write messages', function (string $method, string $route) {
    $this->$method(route($route), ['body' => 'Merhaba'])->assertUnauthorized();
    $this->assertDatabaseCount('messages', 0);
})->with([['getJson', 'messages.index'], ['postJson', 'messages.store']]);

test('inactive users cannot read or write messages', function (string $method, string $route) {
    $user = User::factory()->inactive()->create();
    $this->actingAs($user)->$method(route($route), ['body' => 'Merhaba'])->assertForbidden();
    $this->assertDatabaseCount('messages', 0);
})->with([['getJson', 'messages.index'], ['postJson', 'messages.store']]);

test('invalid messages are rejected without storing or broadcasting', function (mixed $body, string $message) {
    $user = User::factory()->create();
    Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);
    $this->actingAs($user)->postJson(route('messages.store'), ['body' => $body])->assertUnprocessable()->assertJsonValidationErrors('body')->assertJsonPath('errors.body.0', $message);
    $this->assertDatabaseCount('messages', 0);
    Event::assertNotDispatched(MessageCreated::class);
})->with([
    'empty' => ['', 'Mesaj boş olamaz.'],
    'spaces' => [" \n\t ", 'Mesaj boş olamaz.'],
    'unicode spaces' => ["\u{00A0}\u{2003}", 'Mesaj boş olamaz.'],
    'array' => [['invalid'], 'Mesaj metin olmalıdır.'],
    'too long' => [str_repeat('a', 4001), 'Mesaj en fazla 4000 karakter olabilir.'],
]);

test('4000 unicode characters are accepted', function () {
    $user = User::factory()->create();
    Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);
    $body = str_repeat('ğ', 4000);
    $this->actingAs($user)->postJson(route('messages.store'), ['body' => $body])->assertCreated();
    $this->assertDatabaseHas('messages', ['body' => $body, 'user_id' => $user->id]);
    Event::assertDispatched(MessageCreated::class);
});

test('history is bounded ordered isolated and excludes deleted messages', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $messages = Message::factory()->count(45)->for($user)->for($channel)->create();
    Message::factory()->for($user)->create();
    $deleted = Message::factory()->for($user)->for($channel)->create();
    $deleted->delete();
    $this->actingAs($user)->getJson(route('messages.index'))->assertOk()->assertJsonCount(40, 'data')->assertJsonPath('has_more', true)->assertJsonPath('data.0.id', $messages[5]->id)->assertJsonPath('data.39.id', $messages[44]->id);
    $this->getJson(route('messages.index', ['before_id' => $messages[5]->id]))->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('has_more', false)->assertJsonPath('data.0.id', $messages[0]->id);
});

test('reconnect history catches up using bounded forward pages', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $messages = Message::factory()->count(45)->for($user)->for($channel)->create();
    $this->actingAs($user)->getJson(route('messages.index', ['after_id' => 0]))->assertJsonCount(40, 'data')->assertJsonPath('has_more', true)->assertJsonPath('after_id', $messages[39]->id);
    $this->getJson(route('messages.index', ['after_id' => $messages[39]->id]))->assertJsonCount(5, 'data')->assertJsonPath('has_more', false);
});

test('message content endpoint enforces active auth and default channel scope', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $message = Message::factory()->for($user)->for($channel)->create();
    $foreign = Message::factory()->for($user)->create();
    $this->getJson(route('messages.show', $message))->assertUnauthorized();
    $this->actingAs($user)->getJson(route('messages.show', $foreign))->assertNotFound();
    $this->getJson(route('messages.show', $message))->assertJsonPath('data.body', $message->body);
});

test('chat page only exposes a bounded message history', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $this->actingAs($user)->get(route('chat'))->assertInertia(fn (Assert $page) => $page->component('Chat/Index')->where('channel.slug', 'general')->has('history.data', 0)->missing('auth.user.password'));
});

test('message rate limit is configurable', function () {
    config(['chat.message_rate_limit' => 2]);
    $user = User::factory()->create();
    Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);
    $this->actingAs($user)->postJson(route('messages.store'), ['body' => 'One'])->assertCreated();
    $this->postJson(route('messages.store'), ['body' => 'Two'])->assertCreated();
    $this->postJson(route('messages.store'), ['body' => 'Three'])->assertTooManyRequests();
    $this->assertDatabaseCount('messages', 2);
    Event::assertDispatchedTimes(MessageCreated::class, 2);
});

test('broadcast failure preserves the stored message and is visible', function () {
    $user = User::factory()->create();
    Channel::factory()->general()->create();
    Broadcast::shouldReceive('queue')->once()->andThrow(new RuntimeException('sensitive provider details'));
    $this->actingAs($user)->postJson(route('messages.store'), ['body' => 'Saved message'])->assertCreated()->assertJsonPath('realtime', false);
    $this->assertDatabaseHas('messages', ['body' => 'Saved message']);
});

test('disabled broadcasting does not report live delivery', function () {
    config(['broadcasting.default' => 'null']);
    $user = User::factory()->create();
    Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);

    $this->actingAs($user)->postJson(route('messages.store'), ['body' => 'Saved locally'])->assertCreated()->assertJsonPath('realtime', false);
    $this->assertDatabaseHas('messages', ['body' => 'Saved locally']);
    Event::assertDispatched(MessageCreated::class);
});

test('private channel requires authentication and active accounts', function (string $state, int $expectedStatus) {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
    require base_path('routes/channels.php');
    if ($state !== 'guest') {
        $this->actingAs(User::factory()->create(['is_active' => $state === 'active']));
    }
    $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-company.general'])->assertStatus($expectedStatus);
})->with([['guest', 401], ['inactive', 403], ['active', 200]]);

test('unknown private channels are forbidden', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
    require base_path('routes/channels.php');
    $this->actingAs(User::factory()->create())->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-company.other'])->assertForbidden();
});
