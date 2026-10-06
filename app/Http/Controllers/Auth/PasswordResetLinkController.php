<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        try {
            Password::sendResetLink(['email' => mb_strtolower(trim($request->string('email')->toString())), 'is_active' => true]);
        } catch (Throwable $exception) {
            Log::error('Password reset delivery failed', ['exception_type' => $exception::class]);

            return back()->withErrors(['email' => 'E-posta gönderilemedi. Lütfen daha sonra tekrar deneyin.']);
        }

        return back()->with('status', 'Aktif bir hesabınız varsa şifre bağlantısı gönderildi. Yeni istek için bir dakika bekleyin.');
    }
}
