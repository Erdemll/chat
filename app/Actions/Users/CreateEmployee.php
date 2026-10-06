<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Str;

class CreateEmployee
{
    /** @param array{name: string, email: string, role: string, is_active: bool} $data */
    public function handle(array $data): User
    {
        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(64)]);
        $user->forceFill(['role' => $data['role'], 'is_active' => $data['is_active']])->save();

        return $user;
    }
}
