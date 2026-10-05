<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Boost;
use App\Models\Message;

final class BoostRemoved extends RoomJsonBroadcast
{
    public function __construct(Message $message, Boost $boost)
    {
        parent::__construct((int) $message->room_id, [
            'message_id' => (int) $message->id,
            'boost' => MessageResource::boostArray($boost),
        ]);
    }

    public function broadcastAs(): string
    {
        return 'boost.removed';
    }
}
