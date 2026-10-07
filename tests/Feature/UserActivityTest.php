<?php

use App\Events\MessageCreated;
use App\Events\MessageReadsUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\User;
use App\Services\UserActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

test('a successful login records login and activity with the same server timestamp', function () {
    $this->freezeTime();
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('chat'));

    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_login_at' => now()->toDateTimeString(), 'last_active_at' => now()->toDateTimeString()]);
});

test('a failed login does not record activity', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_login_at' => null, 'last_active_at' => null]);
});

test('activity uses the authenticated identity and server time without changing login or account update time', function () {
    $this->freezeTime();
    $user = User::factory()->create(['last_login_at' => now()->subDay(), 'updated_at' => now()->subHour()]);
    $other = User::factory()->create();
    $loginAt = $user->last_login_at->toDateTimeString();
    $updatedAt = $user->updated_at->toDateTimeString();

    $this->actingAs($user)->postJson(route('activity.store'), ['user_id' => $other->id, 'last_active_at' => '2000-01-01T00:00:00Z'])->assertExactJson(['success' => true]);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => now()->toDateTimeString(), 'last_login_at' => $loginAt, 'updated_at' => $updatedAt]);
    $this->assertDatabaseHas('users', ['id' => $other->id, 'last_active_at' => null]);
});

test('guests and inactive accounts cannot record activity', function (string $state, int $status) {
    $user = User::factory()->inactive()->create();
    if ($state === 'inactive') {
        $this->actingAs($user);
    }

    $this->postJson(route('activity.store'))->assertStatus($status);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => null]);
})->with(['guest' => ['guest', 401], 'inactive' => ['inactive', 403]]);

test('repeated activity needs no queries until the two minute boundary', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $activity = app(UserActivityService::class);
    expect($activity->record($user))->toBeTrue();
    $firstActiveAt = now()->toDateTimeString();
    $this->travel(119)->seconds();
    DB::enableQueryLog();

    for ($attempt = 0; $attempt < 20; $attempt++) {
        expect($activity->record($user))->toBeFalse();
    }

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(0);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => $firstActiveAt]);
    $this->travel(1)->seconds();
    expect($activity->record($user))->toBeTrue();
    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => now()->toDateTimeString()]);
});

test('stale requests cannot overwrite a recent activity timestamp', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $staleUser = $user->fresh();
    $activity = app(UserActivityService::class);
    $activity->record($user);
    $firstActiveAt = now()->toDateTimeString();
    $this->travel(30)->seconds();

    expect($activity->record($staleUser))->toBeFalse();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => $firstActiveAt]);
});

test('an account deactivated after loading cannot be marked active by the service', function () {
    $user = User::factory()->create();
    User::query()->whereKey($user->id)->update(['is_active' => false]);

    expect(app(UserActivityService::class)->record($user))->toBeFalse();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => null]);
});

test('successful message sending records activity but rejected messages do not', function (string $body, bool $allowed) {
    $this->freezeTime();
    $user = User::factory()->create();
    Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);

    $response = $this->actingAs($user)->postJson(route('messages.store'), ['body' => $body]);

    if ($allowed) {
        $response->assertCreated();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => now()->toDateTimeString()]);
        Event::assertDispatchedTimes(MessageCreated::class, 1);
    } else {
        $response->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => null]);
        Event::assertNotDispatched(MessageCreated::class);
    }
})->with(['normal' => ['Merhaba, rapor hazır.', true], 'blocked' => ['amk', false]]);

test('new reads record activity while repeated reads preserve the first read and activity times', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create();
    Event::fake([MessageReadsUpdated::class]);
    $this->actingAs($user)->postJson(route('messages.read'), ['message_ids' => [$message->id]])->assertOk();
    $firstReadAt = now()->toDateTimeString();
    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => $firstReadAt]);
    $this->travel(5)->minutes();

    $this->postJson(route('messages.read'), ['message_ids' => [$message->id]])->assertOk()->assertJsonPath('reads', []);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => $firstReadAt]);
    $this->assertDatabaseHas('message_reads', ['user_id' => $user->id, 'message_id' => $message->id, 'read_at' => $firstReadAt]);
    Event::assertDispatchedTimes(MessageReadsUpdated::class, 1);
});

test('own inaccessible and invalid read batches do not record activity', function (string $kind) {
    $user = User::factory()->create();
    $message = match ($kind) {
        'own' => Message::factory()->for($user)->for(Channel::factory()->general()->create())->create(),
        default => Message::factory()->create(),
    };
    $payload = ['message_ids' => $kind === 'invalid' ? [] : [$message->id]];

    $response = $this->actingAs($user)->postJson(route('messages.read'), $payload);

    $response->assertStatus($kind === 'invalid' ? 422 : 200);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => null]);
})->with(['own', 'inaccessible', 'invalid']);

test('background session history detail reader and autocomplete requests do not mark activity', function (string $routeName) {
    $user = User::factory()->create();
    $message = Message::factory()->for(Channel::factory()->general()->create())->create();
    $parameters = in_array($routeName, ['messages.show', 'messages.reads'], true) ? [$message] : [];

    $this->actingAs($user)->getJson(route($routeName, $parameters))->assertOk();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'last_active_at' => null]);
})->with(['session.status', 'messages.index', 'messages.show', 'messages.reads', 'users.mentionable']);

test('activity request rate limits are per user and still allow other accounts', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson(route('activity.store'))->assertOk();
    }

    $this->postJson(route('activity.store'))->assertTooManyRequests();
    $this->actingAs($other)->postJson(route('activity.store'))->assertOk();

    $this->assertDatabaseHas('users', ['id' => $other->id, 'last_active_at' => now()->toDateTimeString()]);
});

test('admin users show three independent dates with the latest first read belonging to that user', function () {
    $this->travelTo(now()->startOfSecond());
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['last_login_at' => now()->subDays(2), 'last_active_at' => now()->subMinutes(3)]);
    $empty = User::factory()->create();
    $message = Message::factory()->for($admin)->create();
    $olderMessage = Message::factory()->for($admin)->create(['created_at' => now()->subDay()]);
    MessageRead::factory()->for($message)->for($user)->create(['read_at' => now()->subHour()]);
    MessageRead::factory()->for($olderMessage)->for($user)->create(['read_at' => now()->subMinutes(5)]);
    MessageRead::factory()->for($message)->for($admin)->create(['read_at' => now()]);

    $this->actingAs($admin)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Users/Index')
        ->where('users.data.0.id', $empty->id)
        ->where('users.data.0.last_login_at', null)
        ->where('users.data.0.last_active_at', null)
        ->where('users.data.0.last_message_read_at', null)
        ->where('users.data.1.id', $user->id)
        ->where('users.data.1.last_login_at', now()->subDays(2)->toJSON())
        ->where('users.data.1.last_active_at', now()->subMinutes(3)->toJSON())
        ->where('users.data.1.last_message_read_at', now()->subMinutes(5)->toJSON()));
});

test('admin activity dates are paginated without queries per user', function () {
    $admin = User::factory()->admin()->create();
    $users = User::factory()->count(25)->create();
    $message = Message::factory()->for($admin)->create();
    foreach ($users as $user) {
        MessageRead::factory()->for($message)->for($user)->create();
    }
    $this->actingAs($admin);
    DB::enableQueryLog();

    $response = $this->get(route('admin.users.index'));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(2);
    $response->assertInertia(fn (Assert $page) => $page->has('users.data', 20)->where('users.total', 26));
});

test('employees cannot access the admin activity list', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.users.index'))->assertForbidden();
});
