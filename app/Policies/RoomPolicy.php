<?php

namespace App\Policies;

use App\Enums\RoomKind;
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

    public function transitionKind(User $user, Room $room, RoomKind $kind): bool
    {
        return $this->update($user, $room)
            && in_array($room->type, ['Rooms::Open', 'Rooms::Closed'], true)
            && in_array($kind, [RoomKind::Open, RoomKind::Closed], true);
    }

    public function delete(User $user, Room $room): bool
    {
        return $this->view($user, $room)
            && ($room->type === 'Rooms::Direct' || $user->role === 1 || $room->creator_id === $user->id);
    }
}
