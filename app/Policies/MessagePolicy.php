<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MessagePolicy
{
    public function delete(User $user, Message $message): Response
    {
        if (! $this->view($user, $message)->allowed()) {
            return Response::denyAsNotFound();
        }

        return $user->is_active && ($message->user_id === $user->id || $user->isAdmin())
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Message $message): Response
    {
        if (! $this->view($user, $message)->allowed()) {
            return Response::denyAsNotFound();
        }

        return $user->is_active && $message->user_id === $user->id && $message->isWithinEditWindow()
            ? Response::allow()
            : Response::deny('Bu mesaj artık düzenlenemez.');
    }

    public function view(User $user, Message $message): Response
    {
        return Message::query()->visibleTo($user)->whereKey($message->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
