<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MessagePolicy
{
    public function view(User $user, Message $message): Response
    {
        return Message::query()->visibleTo($user)->whereKey($message->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
