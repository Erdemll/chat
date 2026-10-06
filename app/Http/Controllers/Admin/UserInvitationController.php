<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\SendInvitation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UserInvitationController extends Controller
{
    public function store(User $user, SendInvitation $invitation): RedirectResponse
    {
        return back()->with('status', $invitation->handle($user));
    }
}
