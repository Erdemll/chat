<?php

use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('admin invitation password setup employee chat and logout work together', function () {
    $admin = User::factory()->admin()->create();
    Channel::factory()->general()->create();
    Notification::fake();
    Event::fake([MessageCreated::class]);

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('chat'));
    $this->post(route('admin.users.store'), ['name' => 'Yeni Çalışan', 'email' => 'new@example.test', 'role' => 'employee', 'is_active' => true])->assertRedirect(route('admin.users.index'));
    $user = User::query()->where('email', 'new@example.test')->firstOrFail();
    $token = Notification::sent($user, UserInvitationNotification::class)->sole()->token;
    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'SecurePassword123!', 'password_confirmation' => 'SecurePassword123!'])->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'SecurePassword123!'])->assertRedirect(route('chat'));
    $this->get(route('chat'))->assertInertia(fn (Assert $page) => $page->component('Chat/Index')->where('channel.name', 'Genel'));
    $response = $this->postJson(route('messages.store'), ['body' => 'Ekibe merhaba!'])->assertCreated();
    $this->getJson(route('messages.show', $response->json('data.id')))->assertJsonPath('data.body', 'Ekibe merhaba!');
    $this->get(route('admin.dashboard'))->assertForbidden();
    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
    Notification::assertSentTo($user, UserInvitationNotification::class);
    Event::assertDispatched(MessageCreated::class);
});

test('admin gate permits only active admins', function (string $role, bool $active, bool $allowed) {
    $user = User::factory()->create(['role' => $role, 'is_active' => $active]);
    expect(Gate::forUser($user)->allows('admin'))->toBe($allowed);
})->with([['admin', true, true], ['admin', false, false], ['employee', true, false], ['employee', false, false]]);

test('channel seeding is idempotent and does not create default accounts', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);
    $this->assertDatabaseCount('channels', 1);
    $this->assertDatabaseHas('channels', ['name' => 'Genel', 'slug' => 'general']);
    $this->assertDatabaseCount('users', 0);
});

test('rolled back messages are not broadcast', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);
    DB::beginTransaction();
    $this->actingAs($user)->postJson(route('messages.store'), ['body' => 'Rolled back'])->assertCreated();
    DB::rollBack();
    $this->assertDatabaseCount('messages', 0);
    Event::assertNotDispatched(MessageCreated::class);
});

test('invitation URL uses configured application origin and expires without leaking credentials', function () {
    config(['app.url' => 'https://company.example.test']);
    $user = User::factory()->invited()->make(['email' => 'invited@example.test']);
    $mail = (new UserInvitationNotification('safe-test-token'))->toMail($user);
    expect($mail->actionUrl)->toStartWith('https://company.example.test/reset-password/safe-test-token')->toContain('email=invited%40example.test');
    expect($mail->introLines)->toContain('Şirket mesajlaşma hesabınız oluşturuldu. Başlamak için şifrenizi belirleyin.');
});
