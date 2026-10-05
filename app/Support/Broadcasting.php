<?php

namespace App\Support;

use App\Events\RoomRead;
use App\Events\RoomUnread;
use App\Events\TurboStreamBroadcast;

/** The one place the app turns a Turbo Stream fragment into a broadcast. */
final class Broadcasting
{
    public function room(int $roomId, string $html): void
    {
        TurboStreamBroadcast::dispatch('rooms.'.$roomId, $html, $roomId);
    }

    public function roomList(string $html): void
    {
        TurboStreamBroadcast::dispatch('rooms', $html);
    }

    public function userSidebar(int $userId, string $html): void
    {
        TurboStreamBroadcast::dispatch('users.'.$userId.'.rooms', $html);
    }

    public function unread(int $userId, int $roomId): void
    {
        RoomUnread::dispatch($userId, $roomId);
    }

    public function read(int $userId, int $roomId): void
    {
        RoomRead::dispatch($userId, $roomId);
    }
}
