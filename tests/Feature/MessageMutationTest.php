<?php

use App\Events\MessageDeleted;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\MessageRead;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\MentionDigestNotification;
use App\Services\MessageReadService;
use App\Services\MessageService;
use App\Services\Notifications\MentionEmailDigestService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['chat.message_edit_window_minutes' => 15]);
});

test('owner and admin can soft delete and clean linked records while preserving deliveries and claims', function (string $role) {
    $owner = User::factory()->create();
    $actor = $role === 'owner' ? $owner : User::factory()->admin()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create();
    $mention = MessageMention::factory()->for($message)->create();
    MessageRead::factory()->for($message)->create();
    $other = Message::factory()->for($message->channel)->create();
    MessageMention::factory()->for($other)->for($mention->user)->create();
    MessageRead::factory()->for($other)->create();
    $sent = NotificationDelivery::factory()->sent()->for($mention->user)->create(['reference_id' => $message->id]);
    $pending = NotificationDelivery::factory()->create(['reference_id' => $message->id]);
    $claim = ['user_id' => $mention->user_id, 'type' => NotificationDelivery::TYPE_MENTION, 'channel' => NotificationDelivery::CHANNEL_EMAIL, 'token' => 'durable-claim', 'created_at' => now()->toDateTimeString()];
    DB::table('notification_delivery_claims')->insert($claim);
    $sentBefore = $sent->fresh()->getAttributes();
    $pendingBefore = $pending->fresh()->getAttributes();
    Event::fake([MessageDeleted::class]);

    $this->actingAs($actor)->deleteJson(route('messages.destroy', $message))
        ->assertOk()->assertJsonPath('message_id', $message->id)->assertJsonPath('realtime', false);
    $this->deleteJson(route('messages.destroy', $message))->assertNotFound();

    $this->assertSoftDeleted($message);
    $this->assertDatabaseCount('messages', 2);
    $this->assertDatabaseMissing('message_mentions', ['message_id' => $message->id]);
    $this->assertDatabaseMissing('message_reads', ['message_id' => $message->id]);
    $this->assertDatabaseCount('message_mentions', 1);
    $this->assertDatabaseCount('message_reads', 1);
    expect($sent->fresh()->getAttributes())->toBe($sentBefore);
    expect($pending->fresh()->getAttributes())->toBe($pendingBefore);
    $this->assertDatabaseHas('notification_delivery_claims', $claim);
    Event::assertDispatchedTimes(MessageDeleted::class, 1);
    Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $event): bool => $event->broadcastWith() === ['message_id' => $message->id, 'channel_id' => $message->channel_id]
        && $event->broadcastOn()->name === 'private-company.general'
        && $event->broadcastAs() === 'MessageDeleted');
})->with(['owner', 'admin']);

test('another employee cannot delete a message', function () {
    $actor = User::factory()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create();
    Event::fake([MessageDeleted::class]);

    $this->actingAs($actor)->deleteJson(route('messages.destroy', $message))->assertForbidden();

    $this->assertNotSoftDeleted($message);
    Event::assertNotDispatched(MessageDeleted::class);
});

test('guest and inactive users cannot mutate messages', function (string $method, string $routeName, string $state) {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create(['body' => 'Eski mesaj']);
    if ($state === 'inactive') {
        $owner->is_active = false;
        $owner->save();
        $this->actingAs($owner);
    }
    Event::fake([MessageDeleted::class, MessageUpdated::class]);

    $this->$method(route($routeName, $message), ['body' => 'Yeni mesaj'])->assertStatus($state === 'guest' ? 401 : 403);

    $this->assertNotSoftDeleted($message);
    $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Eski mesaj', 'edited_at' => null]);
    Event::assertNotDispatched(MessageDeleted::class);
    Event::assertNotDispatched(MessageUpdated::class);
})->with([
    ['deleteJson', 'messages.destroy', 'guest'], ['deleteJson', 'messages.destroy', 'inactive'],
    ['patchJson', 'messages.update', 'guest'], ['patchJson', 'messages.update', 'inactive'],
]);

test('message policies enforce the complete owner and admin permission matrix', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();
    $inactive = User::factory()->inactive()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create();

    expect(Gate::forUser($owner)->allows('update', $message))->toBeTrue();
    expect(Gate::forUser($owner)->allows('delete', $message))->toBeTrue();
    expect(Gate::forUser($admin)->allows('update', $message))->toBeFalse();
    expect(Gate::forUser($admin)->allows('delete', $message))->toBeTrue();
    expect(Gate::forUser($other)->allows('update', $message))->toBeFalse();
    expect(Gate::forUser($other)->allows('delete', $message))->toBeFalse();
    expect(Gate::forUser($inactive)->allows('update', $message))->toBeFalse();
    expect(Gate::forUser($inactive)->allows('delete', $message))->toBeFalse();
});

test('mutations cannot access a message outside the general channel', function (string $method, string $name) {
    $owner = User::factory()->admin()->create();
    $message = Message::factory()->for($owner)->create();
    Event::fake([MessageDeleted::class, MessageUpdated::class]);

    $this->actingAs($owner)->$method(route($name, $message), ['body' => 'Yeni mesaj'])->assertNotFound();

    $this->assertNotSoftDeleted($message);
    expect($message->fresh()->edited_at)->toBeNull();
    Event::assertNotDispatched(MessageDeleted::class);
    Event::assertNotDispatched(MessageUpdated::class);
})->with([['patchJson', 'messages.update'], ['deleteJson', 'messages.destroy']]);

test('deleted messages disappear from initial before after show reads and reconciliation responses', function () {
    $owner = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $first = Message::factory()->for($owner)->for($channel)->create();
    $deleted = Message::factory()->for($owner)->for($channel)->create();
    $last = Message::factory()->for($owner)->for($channel)->create();
    Event::fake([MessageDeleted::class]);
    $this->actingAs($owner)->deleteJson(route('messages.destroy', $deleted))->assertOk();

    $this->getJson(route('messages.index'))->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.1.id', $last->id)->assertJsonCount(2, 'data');
    $this->getJson(route('messages.index', ['before_id' => $last->id]))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
    $this->getJson(route('messages.index', ['after_id' => $first->id]))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $last->id);
    $this->getJson(route('messages.index', ['message_ids' => [$deleted->id, $last->id]]))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $last->id);
    $this->getJson(route('messages.show', $deleted))->assertNotFound();
    $this->getJson(route('messages.reads', $deleted))->assertNotFound();
    $this->patchJson(route('messages.update', $deleted), ['body' => 'Yeni mesaj'])->assertNotFound();
    $this->get(route('chat'))->assertInertia(fn (AssertableInertia $page) => $page->has('history.data', 2));
    Event::assertDispatchedTimes(MessageDeleted::class, 1);
});

test('read tracking cannot recreate receipts for a deleted message', function () {
    $owner = User::factory()->create();
    $reader = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create();
    Event::fake([MessageDeleted::class]);
    app(MessageService::class)->delete($owner, $message);

    expect(app(MessageReadService::class)->markAsRead($reader, [$message->id]))->toBe([]);

    $this->assertDatabaseCount('message_reads', 0);
    Event::assertDispatched(MessageDeleted::class);
});

test('delete waits for the outer commit before dispatching', function () {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create();
    Event::fake([MessageDeleted::class]);

    DB::transaction(function () use ($owner, $message) {
        app(MessageService::class)->delete($owner, $message);
        Event::assertNotDispatched(MessageDeleted::class);
    });

    $this->assertSoftDeleted($message);
    Event::assertDispatchedTimes(MessageDeleted::class, 1);
});

test('rollback restores the deleted message mentions and reads and suppresses the event', function () {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create();
    $mention = MessageMention::factory()->for($message)->create();
    $read = MessageRead::factory()->for($message)->create();
    Event::fake([MessageDeleted::class]);

    expect(fn () => DB::transaction(function () use ($owner, $message) {
        app(MessageService::class)->delete($owner, $message);
        throw new RuntimeException('Rollback delete');
    }))->toThrow(RuntimeException::class, 'Rollback delete');

    $this->assertNotSoftDeleted($message);
    $this->assertModelExists($mention);
    $this->assertModelExists($read);
    Event::assertNotDispatched(MessageDeleted::class);
});

test('owner updates trimmed plain text and server edit time while retaining reads and deliveries', function (string $role) {
    $this->freezeSecond();
    $owner = $role === 'admin' ? User::factory()->admin()->create() : User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create(['body' => 'Eski mesaj']);
    $read = MessageRead::factory()->for($message)->create();
    $delivery = NotificationDelivery::factory()->sent()->create(['reference_id' => $message->id]);
    $readBefore = $read->fresh()->getAttributes();
    $deliveryBefore = $delivery->fresh()->getAttributes();
    $createdAt = $message->created_at->toIso8601String();
    $this->travel(5)->minutes();
    Event::fake([MessageUpdated::class]);

    $this->actingAs($owner)->patchJson(route('messages.update', $message), [
        'body' => '  <script>alert(1)</script> Yeni mesaj  ', 'user_id' => $read->user_id,
        'edited_at' => '2000-01-01', 'deleted_at' => '2000-01-01', 'expected_body' => 'Eski mesaj',
    ])->assertOk()->assertJsonPath('data.body', '<script>alert(1)</script> Yeni mesaj')
        ->assertJsonPath('data.edited_at', now()->toIso8601String())->assertJsonPath('data.user.id', $owner->id)
        ->assertJsonPath('data.created_at', $createdAt)->assertJsonPath('data.read_count', 1)
        ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.can_delete', true);

    $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => '<script>alert(1)</script> Yeni mesaj', 'edited_at' => now()->toDateTimeString(), 'deleted_at' => null]);
    expect($read->fresh()->getAttributes())->toBe($readBefore);
    expect($delivery->fresh()->getAttributes())->toBe($deliveryBefore);
    Event::assertDispatchedTimes(MessageUpdated::class, 1);
    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event): bool => $event->broadcastOn()->name === 'private-company.general' && $event->broadcastAs() === 'MessageUpdated'
        && $event->broadcastWith() === ['message_id' => $message->id, 'channel_id' => $message->channel_id]);
    $this->getJson(route('messages.show', $message))->assertJsonPath('data.body', '<script>alert(1)</script> Yeni mesaj')->assertJsonPath('data.edited_at', now()->toIso8601String());
})->with(['employee', 'admin']);

test('other employee and admin cannot edit someone elses message', function (string $role) {
    $actor = $role === 'admin' ? User::factory()->admin()->create() : User::factory()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create(['body' => 'Eski mesaj']);
    Event::fake([MessageUpdated::class]);

    $this->actingAs($actor)->patchJson(route('messages.update', $message), ['body' => 'Yeni mesaj'])->assertForbidden();
    $this->getJson(route('messages.show', $message))->assertJsonPath('data.can_edit', false)->assertJsonPath('data.can_delete', $role === 'admin');

    $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Eski mesaj', 'edited_at' => null]);
    Event::assertNotDispatched(MessageUpdated::class);
})->with(['employee', 'admin']);

test('edit window uses configured creation deadline without resetting on edits', function (int $window, int $age, bool $allowed) {
    $this->freezeSecond();
    config(['chat.message_edit_window_minutes' => $window]);
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create(['body' => 'Eski mesaj', 'created_at' => now()->subSeconds($age), 'edited_at' => now()->subSecond()]);
    Event::fake([MessageUpdated::class]);

    $response = $this->actingAs($owner)->patchJson(route('messages.update', $message), ['body' => 'Yeni mesaj']);

    if ($allowed) {
        $response->assertOk();
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Yeni mesaj']);
        Event::assertDispatched(MessageUpdated::class);
    } else {
        $response->assertForbidden()->assertJsonPath('message', 'Bu mesaj artık düzenlenemez.');
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Eski mesaj']);
        Event::assertNotDispatched(MessageUpdated::class);
    }
})->with([
    'within default' => [15, 899, true], 'exact deadline' => [15, 900, true], 'expired default' => [15, 901, false],
    'longer configured' => [30, 1200, true], 'shorter configured' => [5, 301, false], 'disabled' => [0, 0, false], 'negative disabled' => [-1, 0, false],
]);

test('invalid edit bodies leave old content and timestamp unchanged', function (mixed $body, string $error) {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create(['body' => 'Eski mesaj']);
    Event::fake([MessageUpdated::class]);

    $this->actingAs($owner)->patchJson(route('messages.update', $message), ['body' => $body])
        ->assertUnprocessable()->assertJsonPath('errors.body.0', $error);

    $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Eski mesaj', 'edited_at' => null]);
    Event::assertNotDispatched(MessageUpdated::class);
})->with([
    'empty' => ['', 'Mesaj boş olamaz.'], 'spaces' => [" \n\t ", 'Mesaj boş olamaz.'],
    'unicode spaces' => ["\u{00A0}\u{2003}", 'Mesaj boş olamaz.'], 'array' => [['invalid'], 'Mesaj metin olmalıdır.'],
    'too long' => [str_repeat('a', 4001), 'Mesaj en fazla 4000 karakter olabilir.'],
]);

test('moderation blocks edit bypass without changing the message mentions or events', function () {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create(['body' => 'Eski mesaj']);
    $oldMention = MessageMention::factory()->for($message)->create();
    $target = User::factory()->create();
    Event::fake([MessageUpdated::class]);

    $this->actingAs($owner)->patchJson(route('messages.update', $message), ['body' => 'siktir', 'mentions' => [$target->id]])
        ->assertUnprocessable()->assertJsonPath('errors.body.0', 'Mesajınız şirket iletişim kurallarına uygun olmadığı için düzenlenemedi.');

    $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Eski mesaj', 'edited_at' => null]);
    $this->assertModelExists($oldMention);
    $this->assertDatabaseCount('message_mentions', 1);
    Event::assertNotDispatched(MessageUpdated::class);
});

test('edit sync removes old mentions adds new ones and retains unchanged mention age', function () {
    $this->freezeSecond();
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create();
    $old = MessageMention::factory()->for($message)->create();
    $retained = MessageMention::factory()->for($message)->create();
    $new = User::factory()->create();
    $originalCreatedAt = $retained->created_at->toDateTimeString();
    $this->travel(2)->minutes();
    Event::fake([MessageUpdated::class]);

    $this->actingAs($owner)->patchJson(route('messages.update', $message), ['body' => 'Yeni mesaj', 'mentions' => [$retained->user_id, $new->id]])
        ->assertOk()->assertJsonCount(2, 'data.mentions');

    $this->assertDatabaseMissing('message_mentions', ['id' => $old->id]);
    $this->assertDatabaseHas('message_mentions', ['id' => $retained->id, 'created_at' => $originalCreatedAt]);
    $this->assertDatabaseHas('message_mentions', ['message_id' => $message->id, 'user_id' => $new->id, 'created_at' => now()->toDateTimeString()]);
    Event::assertDispatchedTimes(MessageUpdated::class, 1);
});

test('omitting mentions clears current mentions without erasing sent delivery history', function () {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create();
    $mention = MessageMention::factory()->for($message)->create();
    $delivery = NotificationDelivery::factory()->sent()->for($mention->user)->create(['reference_id' => $message->id]);
    Event::fake([MessageUpdated::class]);

    $this->actingAs($owner)->patchJson(route('messages.update', $message), ['body' => 'Mention olmadan mesaj'])->assertOk()->assertJsonPath('data.mentions', []);

    $this->assertDatabaseCount('message_mentions', 0);
    $this->assertModelExists($delivery);
    Event::assertDispatched(MessageUpdated::class);
});

test('invalid self inactive nonexistent and duplicate edit mentions preserve existing state', function (string $state) {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create(['body' => 'Eski mesaj']);
    $mention = MessageMention::factory()->for($message)->create();
    $ids = match ($state) {
        'self' => [$owner->id], 'inactive' => [User::factory()->inactive()->create()->id],
        'duplicate' => [$mention->user_id, $mention->user_id], default => [999999],
    };
    Event::fake([MessageUpdated::class]);

    $this->actingAs($owner)->patchJson(route('messages.update', $message), ['body' => 'Yeni mesaj', 'mentions' => $ids])->assertUnprocessable();

    $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Eski mesaj', 'edited_at' => null]);
    $this->assertModelExists($mention);
    $this->assertDatabaseCount('message_mentions', 1);
    Event::assertNotDispatched(MessageUpdated::class);
})->with(['self', 'inactive', 'nonexistent', 'duplicate']);

test('stale body is rejected with 409 rather than overwriting newer content', function () {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create(['body' => 'Yeni içerik']);
    Event::fake([MessageUpdated::class]);

    $this->actingAs($owner)->patchJson(route('messages.update', $message), ['body' => 'Eski taslak', 'expected_body' => 'Eski içerik'])
        ->assertConflict()->assertJsonValidationErrors('body');

    $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Yeni içerik', 'edited_at' => null]);
    Event::assertNotDispatched(MessageUpdated::class);
});

test('mention write failure rolls back both updated body and mention replacements', function () {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create(['body' => 'Eski mesaj']);
    $old = MessageMention::factory()->for($message)->create();
    $new = User::factory()->create();
    Event::fake([MessageUpdated::class]);
    Exceptions::fake();
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'message_mentions')) {
            throw new RuntimeException('Mention write failed');
        }
    });

    $this->actingAs($owner)->patchJson(route('messages.update', $message), ['body' => 'Yeni mesaj', 'mentions' => [$new->id]])->assertInternalServerError();

    $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Eski mesaj', 'edited_at' => null]);
    $this->assertModelExists($old);
    $this->assertDatabaseCount('message_mentions', 1);
    Event::assertNotDispatched(MessageUpdated::class);
    Exceptions::assertReported(RuntimeException::class);
});

test('edit waits for commit and an outer rollback suppresses MessageUpdated', function () {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create(['body' => 'Eski mesaj']);
    Event::fake([MessageUpdated::class]);

    expect(fn () => DB::transaction(function () use ($owner, $message) {
        app(MessageService::class)->update($owner, $message, 'Yeni mesaj');
        Event::assertNotDispatched(MessageUpdated::class);
        throw new RuntimeException('Rollback edit');
    }))->toThrow(RuntimeException::class, 'Rollback edit');

    $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Eski mesaj', 'edited_at' => null]);
    Event::assertNotDispatched(MessageUpdated::class);
    DB::transaction(function () use ($owner, $message) {
        app(MessageService::class)->update($owner, $message, 'Son mesaj');
        Event::assertNotDispatched(MessageUpdated::class);
    });
    Event::assertDispatchedTimes(MessageUpdated::class, 1);
});

test('broadcast outages preserve successful mutation and report realtime false', function (string $method, string $name) {
    $owner = User::factory()->create();
    $message = Message::factory()->for($owner)->for(Channel::factory()->general()->create())->create(['body' => 'Eski mesaj']);
    Broadcast::shouldReceive('queue')->once()->andThrow(new RuntimeException('Provider unavailable'));

    $this->actingAs($owner)->$method(route($name, $message), ['body' => 'Yeni mesaj'])->assertOk()->assertJsonPath('realtime', false);

    if ($method === 'deleteJson') {
        $this->assertSoftDeleted($message);
    } else {
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Yeni mesaj']);
    }
})->with([['patchJson', 'messages.update'], ['deleteJson', 'messages.destroy']]);

test('digest uses edited mention state and never emails deleted messages or removed mentions', function () {
    $this->freezeSecond();
    config(['notifications.mention_email.delay_minutes' => 0]);
    $owner = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $edited = Message::factory()->for($owner)->for($channel)->create();
    $removed = MessageMention::factory()->for($edited)->create();
    $new = User::factory()->create();
    $deleted = Message::factory()->for($owner)->for($channel)->create();
    $deletedMention = MessageMention::factory()->for($deleted)->create();
    Event::fake([MessageUpdated::class, MessageDeleted::class]);
    Notification::fake();
    app(MessageService::class)->update($owner, $edited, 'Güncel mesaj', [$new->id]);
    app(MessageService::class)->delete($owner, $deleted);

    $summary = app(MentionEmailDigestService::class)->run();

    expect($summary['emails_sent'])->toBe(1);
    Notification::assertSentToTimes($new, MentionDigestNotification::class, 1);
    Notification::assertNotSentTo($removed->user, MentionDigestNotification::class);
    Notification::assertNotSentTo($deletedMention->user, MentionDigestNotification::class);
    $this->assertDatabaseHas('notification_deliveries', ['user_id' => $new->id, 'reference_id' => $edited->id, 'status' => NotificationDelivery::STATUS_SENT]);
    Event::assertDispatched(MessageUpdated::class);
    Event::assertDispatched(MessageDeleted::class);
});

test('bounded reconciliation returns only current requested messages with constant query count', function () {
    $owner = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    $messages = Message::factory()->count(45)->for($owner)->for($channel)->create();
    $foreign = Message::factory()->create();
    $this->actingAs($owner);
    DB::enableQueryLog();

    $this->getJson(route('messages.index', ['message_ids' => [...$messages->modelKeys(), $foreign->id]]))
        ->assertJsonCount(45, 'data')->assertJsonPath('has_more', false);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(4);
    $this->getJson(route('messages.index', ['message_ids' => range(1, 101)]))->assertUnprocessable();
    $this->getJson(route('messages.index', ['message_ids' => [$messages[0]->id], 'before_id' => $messages[1]->id]))->assertUnprocessable();
    $this->getJson(route('messages.index', ['message_ids' => [$messages[0]->id], 'after_id' => 0]))->assertUnprocessable();
});
