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
 * Cloud's managed Reverb refuses a frame over 10 000 bytes, and one rendered Campfire message is
 * about 9 000 bytes of HTML before JSON escaping, so almost nothing fits inline. The payload is
 * therefore gzipped and base64'd, which takes a typical message to well under 2 000 bytes. Anything
 * still too large falls back to a pointer and the client asks the room's refresh endpoint for what
 * it missed, which is the same recovery path it already uses after a dropped connection.
 */
final class TurboStreamBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @return array{format: string, variants: list<string>}
     */
    public static function encoding(): array
    {
        return [
            'format' => 'turbo-stream-html',
            'variants' => ['inline', 'gzip+base64', 'refresh-pointer'],
        ];
    }

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
        $limit = (int) config('campfire.broadcast_payload_limit');

        if (strlen($this->html) <= $limit) {
            return ['html' => $this->html];
        }

        $compressed = base64_encode(gzencode($this->html, 6));

        if (strlen($compressed) <= $limit) {
            return ['gz' => $compressed];
        }

        return ['oversize' => true, 'roomId' => $this->roomId];
    }
}
