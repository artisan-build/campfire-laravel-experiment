<?php

namespace App\Support;

use App\Events\RoomRead;
use App\Events\RoomUnread;

final class Broadcasting
{
    public function unread(int $userId, int $roomId): void
    {
        RoomUnread::dispatch($userId, $roomId);
    }

    public function read(int $userId, int $roomId): void
    {
        RoomRead::dispatch($userId, $roomId);
    }
}
