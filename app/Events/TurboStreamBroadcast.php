<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Turbo Stream fragment on its way to every subscriber of one channel.
 *
 * Upstream appended a JSON line to storage/events.log and a Workerman process tailed the file, which
 * is single-host by construction. This is the same payload over Laravel broadcasting.
 *
 * Cloud's managed Reverb refuses a frame over 10 000 bytes, so an oversized fragment is replaced by
 * a pointer: the client then asks the room's refresh endpoint for what it missed, which is the same
 * recovery path it already uses after a dropped connection.
 */
final class TurboStreamBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $channel,
        public string $html,
        public ?int $roomId = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'turbo-stream';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        if (strlen($this->html) > (int) config('campfire.broadcast_payload_limit')) {
            return ['oversize' => true, 'roomId' => $this->roomId];
        }

        return ['html' => $this->html];
    }
}
