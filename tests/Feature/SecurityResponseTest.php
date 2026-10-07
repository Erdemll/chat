<?php

use App\Events\MessageCreated;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

test('auth pages cannot be cached framed or mime sniffed and do not send referrers cross origin', function (string $routeName) {
    $response = $this->get(route($routeName));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
})->with(['login', 'password.request']);

test('authenticated chat and JSON history responses prohibit shared or browser storage', function (bool $json) {
    $user = User::factory()->create();
    Channel::factory()->general()->create();
    $this->actingAs($user);

    $response = $json ? $this->getJson(route('messages.index')) : $this->get(route('chat'));

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
})->with([true, false]);

test('login and protected JSON routes reject cross origin requests without csrf proof', function (bool $login) {
    $user = User::factory()->create();
    Channel::factory()->general()->create();
    Event::fake([MessageCreated::class]);
    if (! $login) {
        $this->actingAs($user);
    }
    $this->app->detectEnvironment(fn () => 'local');

    $this->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->postJson(
        route($login ? 'login.store' : 'messages.store'),
        $login ? ['email' => $user->email, 'password' => 'password'] : ['body' => 'Unauthorized message'],
    )->assertStatus(419);

    $this->assertDatabaseCount('messages', 0);
    Event::assertNotDispatched(MessageCreated::class);
    if ($login) {
        $this->assertGuest();
    }
})->with([true, false]);

test('inertia auth responses keep cookies secure and never echo submitted credentials', function () {
    config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
    $user = User::factory()->create();

    $response = $this->get(route('login'));

    $sessionCookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    expect($sessionCookie)->not->toBeNull();
    expect($sessionCookie->isSecure())->toBeTrue();
    expect($sessionCookie->isHttpOnly())->toBeTrue();
    expect($sessionCookie->getSameSite())->toBe('lax');
    $this->withHeader('X-Inertia', 'true')->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-secret'])->assertSessionHasErrors('email');
    $this->flushHeaders();
    $this->get(route('login'))->assertDontSee('wrong-secret')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->missing('password')->missing('auth.user.password'));
});

test('native login accepts named form fields including the remember checkbox value', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1'])->assertRedirect(route('chat'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->remember_token)->not->toBeNull();
});

test('secure authenticated pages encrypt history while non secure development pages remain usable', function (bool $secure) {
    $user = User::factory()->create();
    Channel::factory()->general()->create();

    $response = $this->actingAs($user)->get(($secure ? 'https' : 'http').'://localhost/chat');

    $response->assertInertia(fn (Assert $page) => $page->component('Chat/Index'));
    expect($response->viewData('page')['encryptHistory'] ?? false)->toBe($secure);
})->with([true, false]);

test('logout and anonymous login rotate history keys without encrypting guest forms', function (bool $logout) {
    if ($logout) {
        $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('login'));
    }

    $response = $this->get('https://localhost/login')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', null));
    expect($response->viewData('page')['clearHistory'] ?? false)->toBeTrue();
    expect($response->viewData('page'))->not->toHaveKey('encryptHistory');
})->with([true, false]);
