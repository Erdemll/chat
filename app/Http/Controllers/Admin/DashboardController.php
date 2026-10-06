<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Admin/Dashboard', ['counts' => ['users' => User::query()->count(), 'active' => User::query()->where('is_active', true)->count(), 'messages' => Message::query()->count()]]);
    }
}
