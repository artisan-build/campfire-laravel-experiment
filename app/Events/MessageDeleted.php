<?php

namespace App\Events;

use App\Models\Message;

final class MessageDeleted extends RoomJsonBroadcast
{
    public function __construct(Message $message)
    {
        parent::__construct((int) $message->room_id, [
            'message' => [
                'id' => (int) $message->id,
                'client_message_id' => (string) $message->client_message_id,
            ],
        ]);
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }
}
