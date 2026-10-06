<?php

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

test('active admin and employee can login and record last login', function (string $role) {
    $user = User::factory()->create(['role' => $role]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('chat'));
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
})->with(['admin', 'employee']);

test('wrong passwords are rejected', function () {
    $user = User::factory()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('inactive and invited users cannot login', function (string $state) {
    $user = User::factory()->$state()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
})->with(['inactive', 'invited']);

test('guests cannot access chat', function () {
    $this->get(route('chat'))->assertRedirect(route('login'));
});

test('inactive sessions are logged out on their next protected request', function () {
    $user = User::factory()->inactive()->create();
    $this->actingAs($user)->get(route('session.status'))->assertForbidden();
    $this->assertGuest();
});

test('logout ends the session', function () {
    $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('authenticated users visiting guest auth screens return to chat', function () {
    $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect(route('chat'));
});

test('public registration is unavailable', function (string $method) {
    $this->$method('/register')->assertNotFound();
})->with(['get', 'post']);

test('forgot password sends the expected synchronous notification', function (bool $invited, string $notification) {
    $user = User::factory()->create(['password_set_at' => $invited ? null : now()]);
    Notification::fake();
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
    Notification::assertSentTo($user, $notification);
})->with([[false, ResetPassword::class], [true, UserInvitationNotification::class]]);

test('forgot password does not notify inactive or unknown accounts', function (bool $exists) {
    $email = $exists ? User::factory()->inactive()->create()->email : 'unknown@example.test';
    Notification::fake();
    $this->post(route('password.email'), ['email' => $email])->assertSessionHas('status');
    Notification::assertNothingSent();
})->with([true, false]);

test('forgot password handles transport failure without leaking details', function () {
    $user = User::factory()->create();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('sensitive provider details'));
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasErrors(['email' => 'E-posta gönderilemedi. Lütfen daha sonra tekrar deneyin.']);
});

test('setup token sets a password and cannot be reused', function () {
    $user = User::factory()->invited()->create();
    $token = Password::createToken($user);
    $payload = ['email' => $user->email, 'token' => $token, 'password' => 'SecurePassword123!', 'password_confirmation' => 'SecurePassword123!'];
    $this->post(route('password.update'), $payload)->assertRedirect(route('login'));
    expect($user->fresh()->password_set_at)->not->toBeNull();
    expect(Hash::check('SecurePassword123!', $user->fresh()->password))->toBeTrue();
    $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
});

test('expired and inactive password reset tokens are refused', function (bool $inactive) {
    $user = User::factory()->create(['is_active' => ! $inactive]);
    $token = Password::createToken($user);
    if (! $inactive) {
        $this->travel(61)->minutes();
    }
    $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'SecurePassword123!', 'password_confirmation' => 'SecurePassword123!'])->assertSessionHasErrors('email');
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
})->with([true, false]);

test('auth screens use existing inertia architecture', function () {
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    $this->get(route('password.request'))->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));
    $this->get(route('password.reset', ['token' => 'example', 'email' => 'test@example.test']))->assertInertia(fn (Assert $page) => $page->component('Auth/ResetPassword')->where('email', 'test@example.test'));
});

test('login is rate limited', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('login.store'), ['email' => 'unknown@example.test', 'password' => 'wrong']);
    }
    $this->post(route('login.store'), ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertTooManyRequests();
});

test('password requests are rate limited', function () {
    Notification::fake();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('password.email'), ['email' => 'unknown@example.test']);
    }
    $this->post(route('password.email'), ['email' => 'unknown@example.test'])->assertTooManyRequests();
    Notification::assertNothingSent();
});
