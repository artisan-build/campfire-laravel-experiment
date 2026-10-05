<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;

final class MessagePosted extends RoomJsonBroadcast
{
    public function __construct(Message $message)
    {
        parent::__construct((int) $message->room_id, [
            'message' => (new MessageResource($message))->forBroadcast(),
        ]);
    }

    public function broadcastAs(): string
    {
        return 'message.posted';
    }
}
