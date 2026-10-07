<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Moderation\CompositeProfanityEngine;
use App\Services\Moderation\DictionaryProfanityEngine;
use App\Services\Moderation\ProfanityEngine;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DictionaryProfanityEngine::class, fn (): DictionaryProfanityEngine => new DictionaryProfanityEngine(resource_path('moderation/profanity-extra.json')));
        $this->app->bind(ProfanityEngine::class, CompositeProfanityEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower(trim($request->string('email')->toString())).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(max(1, (int) config('chat.message_rate_limit')))->by((string) $request->user()?->id));
        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('activity', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim(config('app.url'), '/').route('password.reset', ['token' => $token, 'email' => $user->email], false));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
