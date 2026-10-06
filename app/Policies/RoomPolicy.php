<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RoomPolicy
{
    public function view(User $user, Room $room): bool
    {
        return $room->users()->whereKey($user->id)->exists();
    }

    public function create(User $user, string $kind): bool
    {
        if (! in_array($kind, ['opens', 'closeds', 'directs'], true)) {
            return false;
        }

        $settings = json_decode(DB::table('accounts')->value('settings') ?? '{}', true);

        return $kind === 'directs'
            || ! ($settings['restrict_room_creation_to_administrators'] ?? false)
            || $user->role === 1;
    }

    public function update(User $user, Room $room): bool
    {
        return $room->type !== 'Rooms::Direct'
            && $this->view($user, $room)
            && ($user->role === 1 || $room->creator_id === $user->id);
    }

    public function delete(User $user, Room $room): bool
    {
        return $this->view($user, $room)
            && ($room->type === 'Rooms::Direct' || $user->role === 1 || $room->creator_id === $user->id);
    }
}
