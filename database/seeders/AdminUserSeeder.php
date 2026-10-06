<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use LogicException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('AdminUserSeeder yalnızca local/testing ortamında çalıştırılabilir. Production için chat:create-admin komutunu kullanın.');
        }

        $email = config('chat.local_admin.email');
        $name = config('chat.local_admin.name');
        $password = config('chat.local_admin.password');
        $email = is_string($email) ? mb_strtolower(trim($email)) : $email;

        Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:255']])->validate();

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $data = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
        ])->validate();

        $this->call(RoleSeeder::class);

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $user->forceFill([
            'role' => Role::ADMIN,
            'is_active' => true,
            'email_verified_at' => now(),
            'password_set_at' => now(),
        ])->save();
    }
}
