<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

final class MessagePolicy
{
    public function update(User $user, Message $message): bool
    {
        return $message->room->users()->whereKey($user->id)->exists()
            && ($user->role === 1 || $message->creator_id === $user->id);
    }

    public function delete(User $user, Message $message): bool
    {
        return $this->update($user, $message);
    }
}
