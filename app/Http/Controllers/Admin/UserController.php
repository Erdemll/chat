<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\CreateEmployee;
use App\Actions\Users\SendInvitation;
use App\Actions\Users\UpdateEmployee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', ['users' => User::query()
            ->select(['id', 'name', 'email', 'role', 'is_active', 'created_at', 'invited_at', 'password_set_at', 'invitation_failed_at', 'last_login_at', 'last_active_at'])
            ->withMax('messageReads as last_message_read_at', 'read_at')
            ->orderByDesc('id')->paginate(20)]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Form', ['employee' => $user->only(['id', 'name', 'email', 'role', 'is_active'])]);
    }

    public function store(StoreUserRequest $request, CreateEmployee $create, SendInvitation $invitation): RedirectResponse
    {
        $user = $create->handle($request->userAttributes());

        return redirect()->route('admin.users.index')->with('status', $user->is_active ? $invitation->handle($user) : 'Pasif kullanıcı oluşturuldu. Davet gönderilmedi.');
    }

    public function update(UpdateUserRequest $request, User $user, UpdateEmployee $update, SendInvitation $invitation): RedirectResponse
    {
        $emailChanged = $update->handle($request->user(), $user, $request->userAttributes());
        $user->refresh();

        return redirect()->route('admin.users.index')->with('status', $emailChanged && $user->is_active ? $invitation->handle($user) : 'Kullanıcı güncellendi.');
    }
}
