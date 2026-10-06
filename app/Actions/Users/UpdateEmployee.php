<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateEmployee
{
    /** @param array{name: string, email: string, role: string, is_active: bool} $data */
    public function handle(User $actor, User $user, array $data): bool
    {
        return DB::transaction(function () use ($actor, $user, $data): bool {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->is($actor) && (! $data['is_active'] || $data['role'] !== User::ROLE_ADMIN)) {
                throw ValidationException::withMessages(['is_active' => 'Kendi admin hesabınızı pasif yapamaz veya rolünü düşüremezsiniz.']);
            }
            $emailChanged = $user->email !== $data['email'];
            if ($emailChanged || ! $data['is_active']) {
                Password::deleteToken($user);
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->remember_token = Str::random(60);
            }
            if ($emailChanged) {
                $user->forceFill(['password' => Str::random(64), 'password_set_at' => null, 'email_verified_at' => null, 'invited_at' => null, 'invitation_failed_at' => null]);
            }
            $user->forceFill($data)->save();

            return $emailChanged;
        });
    }
}
