<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

abstract class RoomJsonBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $roomId,
        private readonly array $payload,
    ) {}

    /** @return array<int, Channel> */
    final public function broadcastOn(): array
    {
        return [new PrivateChannel('rooms.'.$this->roomId)];
    }

    /** @return array<string, mixed> */
    final public function broadcastWith(): array
    {
        return $this->payload;
    }
}
