<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Tells one signed-in user to fetch their authoritative sidebar over HTTP. */
final class SidebarChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $userId) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId.'.rooms')];
    }

    public function broadcastAs(): string
    {
        return 'sidebar.changed';
    }

    /** @return array<string, bool> */
    public function broadcastWith(): array
    {
        return ['refresh' => true];
    }
}
