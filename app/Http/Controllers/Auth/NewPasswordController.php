<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => $request->string('email')->toString()]);
    }

    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset([...$request->only('email', 'password', 'password_confirmation', 'token'), 'is_active' => true], function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_unless($user->is_active, 403);
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'password_set_at' => now(), 'invitation_failed_at' => null, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                event(new PasswordReset($user));
            });
        });

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', 'Şifreniz kaydedildi. Giriş yapabilirsiniz.')
            : back()->withErrors(['email' => 'Bağlantı geçersiz veya süresi dolmuş. Yeni bir şifre bağlantısı isteyin.']);
    }
}
