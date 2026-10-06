<?php

use App\Models\Room;
use App\Models\User;
use App\Support\RoomAccess;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/**
 * Channel authorization. Every channel the browser can name is listed here and everything else is
 * denied, so the client is free to build its own channel names: a name it invents still has to pass
 * the matching rule below. Upstream needed signed stream names because the subscriber named an
 * Action Cable channel class; here authorization keys off the channel itself.
 */

// The account-wide room list. Upstream streamed room removals to every signed-in user.
Broadcast::channel('rooms', fn (User $user) => RoomAccess::active($user));

// One user's own sidebar, unread badges and read receipts.
Broadcast::channel('users.{id}.rooms', fn (User $user, int $id) => (int) $user->id === $id);
Broadcast::channel('users.{id}.unreads', fn (User $user, int $id) => (int) $user->id === $id);
Broadcast::channel('users.{id}.reads', fn (User $user, int $id) => (int) $user->id === $id);

// A room's message stream and its typing indicator, for members only.
Broadcast::channel('rooms.{room}', fn (User $user, int $room) => ($model = Room::find($room)) && Gate::forUser($user)->allows('view', $model));
Broadcast::channel('rooms.{room}.typing', fn (User $user, int $room) => ($model = Room::find($room)) && Gate::forUser($user)->allows('view', $model));

// Presence. Returning an array admits the user and gives every other member their identity.
Broadcast::channel('rooms.{room}.presence', fn (User $user, int $room) => ($model = Room::find($room)) && Gate::forUser($user)->allows('view', $model)
    ? ['id' => $user->id, 'name' => $user->name]
    : null);
