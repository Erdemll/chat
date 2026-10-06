<?php

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

test('employee cannot access any admin endpoints', function (string $method, string $route) {
    $employee = User::factory()->create();
    $this->actingAs($employee)->$method(route($route, ['user' => $employee->id]))->assertForbidden();
})->with([
    ['get', 'admin.dashboard'], ['get', 'admin.users.index'], ['get', 'admin.users.create'], ['get', 'admin.users.edit'], ['post', 'admin.users.store'], ['put', 'admin.users.update'], ['post', 'admin.users.invitation'],
]);

test('admin can list users without exposing credentials', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Index')->has('users.data', 1)->missing('users.data.0.password')->missing('users.data.0.remember_token'));
});

test('admin creates employee and sends a fresh invitation', function () {
    $admin = User::factory()->admin()->create();
    Notification::fake();
    $this->actingAs($admin)->post(route('admin.users.store'), ['name' => 'Çalışan', 'email' => 'EMPLOYEE@example.test', 'role' => 'employee', 'is_active' => true])->assertRedirect(route('admin.users.index'))->assertSessionHas('status', 'Şifre bağlantısı gönderildi.');
    $user = User::query()->where('email', 'employee@example.test')->firstOrFail();
    expect($user->invited_at)->not->toBeNull();
    expect($user->password_set_at)->toBeNull();
    Notification::assertSentTo($user, UserInvitationNotification::class, fn ($notification) => Password::tokenExists($user, $notification->token));
});

test('invalid duplicate and inactive user creation do not send mail', function (string $case) {
    $admin = User::factory()->admin()->create();
    Notification::fake();
    $payload = ['name' => 'Çalışan', 'email' => 'employee@example.test', 'role' => 'employee', 'is_active' => true];
    if ($case === 'duplicate') {
        $payload['email'] = $admin->email;
    }
    if ($case === 'invalid') {
        $payload['role'] = 'owner';
    }
    if ($case === 'inactive') {
        $payload['is_active'] = false;
    }
    $response = $this->actingAs($admin)->post(route('admin.users.store'), $payload);
    if ($case === 'inactive') {
        $response->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['email' => 'employee@example.test', 'is_active' => false, 'invited_at' => null]);
    } else {
        $response->assertSessionHasErrors($case === 'duplicate' ? 'email' : 'role');
        $this->assertDatabaseCount('users', 1);
    }
    Notification::assertNothingSent();
})->with(['duplicate', 'invalid', 'inactive']);

test('failed invitation preserves user and exposes failure to admin', function () {
    $admin = User::factory()->admin()->create();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('provider failure'));
    $this->actingAs($admin)->post(route('admin.users.store'), ['name' => 'Çalışan', 'email' => 'employee@example.test', 'role' => 'employee', 'is_active' => true])->assertRedirect(route('admin.users.index'))->assertSessionHas('status', 'Kullanıcı kaydedildi fakat davet maili gönderilemedi. Daveti yeniden gönderebilirsiniz.');
    $user = User::query()->where('email', 'employee@example.test')->firstOrFail();
    expect($user->invitation_failed_at)->not->toBeNull();
    expect($user->invited_at)->toBeNull();
});

test('resending invitation rotates token and clears prior failure', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->invited()->create(['invitation_failed_at' => now()]);
    $oldToken = Password::createToken($user);
    $this->travel(61)->seconds();
    Notification::fake();
    $this->actingAs($admin)->post(route('admin.users.invitation', $user))->assertSessionHas('status', 'Şifre bağlantısı gönderildi.');
    expect(Password::tokenExists($user, $oldToken))->toBeFalse();
    expect($user->fresh()->invitation_failed_at)->toBeNull();
    Notification::assertSentTo($user, UserInvitationNotification::class, fn ($notification) => Password::tokenExists($user, $notification->token));
});

test('inactive user resend sends nothing', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->inactive()->create();
    Notification::fake();
    $this->actingAs($admin)->post(route('admin.users.invitation', $user))->assertSessionHas('status', 'Pasif kullanıcıya davet gönderilemez.');
    Notification::assertNothingSent();
});

test('established users receive normal reset notifications', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    Notification::fake();
    $this->actingAs($admin)->post(route('admin.users.invitation', $user))->assertSessionHas('status', 'Şifre bağlantısı gönderildi.');
    Notification::assertSentTo($user, ResetPassword::class);
});

test('admin deactivation revokes tokens and persisted sessions', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $token = Password::createToken($user);
    DB::table('sessions')->insert(['id' => 'employee-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $this->actingAs($admin)->put(route('admin.users.update', $user), ['name' => $user->name, 'email' => $user->email, 'role' => 'employee', 'is_active' => false])->assertRedirect(route('admin.users.index'));
    expect($user->fresh()->is_active)->toBeFalse();
    expect(Password::tokenExists($user, $token))->toBeFalse();
    $this->assertDatabaseMissing('sessions', ['id' => 'employee-session']);
});

test('admin cannot deactivate or demote their own account', function (bool $active, string $role) {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put(route('admin.users.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'role' => $role, 'is_active' => $active])->assertSessionHasErrors('is_active');
    expect($admin->fresh()->isAdmin())->toBeTrue();
})->with([[false, 'admin'], [true, 'employee']]);

test('email changes revoke credentials and issue invitation for new address', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $token = Password::createToken($user);
    Notification::fake();
    $this->actingAs($admin)->put(route('admin.users.update', $user), ['name' => 'Yeni Ad', 'email' => 'new@example.test', 'role' => 'employee', 'is_active' => true])->assertRedirect(route('admin.users.index'));
    $user->refresh();
    expect($user->password_set_at)->toBeNull();
    expect($user->email)->toBe('new@example.test');
    expect(Password::tokenExists($user, $token))->toBeFalse();
    Notification::assertSentTo($user, UserInvitationNotification::class);
});
