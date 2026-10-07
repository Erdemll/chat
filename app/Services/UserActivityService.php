<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserActivityService
{
    public const UPDATE_INTERVAL_SECONDS = 120;

    public function record(User $user): bool
    {
        $observedAt = now();
        $cutoff = $observedAt->copy()->subSeconds(self::UPDATE_INTERVAL_SECONDS);
        if (! $user->is_active || $user->last_active_at?->gt($cutoff)) {
            return false;
        }

        $updated = User::query()->whereKey($user->id)->where('is_active', true)
            ->where(fn (Builder $users) => $users->whereNull('last_active_at')->orWhere('last_active_at', '<=', $cutoff))
            ->toBase()->update(['last_active_at' => $observedAt]);

        if ($updated > 0) {
            $user->forceFill(['last_active_at' => $observedAt])->syncOriginalAttribute('last_active_at');
        }

        return $updated > 0;
    }
}
