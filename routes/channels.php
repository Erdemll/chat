<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('company.general', fn (User $user): bool => $user->is_active);
