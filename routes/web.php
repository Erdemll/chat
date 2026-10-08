<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserInvitationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\MentionableUserController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageReadController;
use App\Http\Controllers\UserActivityController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.update');
});
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'active'])->group(function (): void {
    Route::get('/chat', ChatController::class)->name('chat');
    Route::post('/activity', UserActivityController::class)->middleware('throttle:activity')->name('activity.store');
    Route::get('/users/mentionable', MentionableUserController::class)->name('users.mentionable');
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::post('/messages/read', [MessageReadController::class, 'store'])->name('messages.read');
    Route::get('/messages/{message}/reads', [MessageReadController::class, 'index'])->name('messages.reads');
    Route::get('/messages/{message}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('/messages', [MessageController::class, 'store'])->middleware('throttle:messages')->name('messages.store');
    Route::patch('/messages/{message}', [MessageController::class, 'update'])->middleware('throttle:messages')->name('messages.update');
    Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
    Route::get('/session-status', fn () => response()->json(['active' => true]))->name('session.status');
    Route::prefix('admin')->name('admin.')->middleware('can:admin')->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::post('/users/{user}/invitation', [UserInvitationController::class, 'store'])->middleware('throttle:invitations')->name('users.invitation');
    });
});
