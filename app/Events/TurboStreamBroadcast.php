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

    private const INLINE_PAYLOAD = 'html';

    private const COMPRESSED_PAYLOAD = 'gz';

    private const POINTER_PAYLOAD = 'oversize';

    private const PAYLOAD_VARIANTS = [
        self::INLINE_PAYLOAD => 'inline',
        self::COMPRESSED_PAYLOAD => 'gzip+base64',
        self::POINTER_PAYLOAD => 'refresh-pointer',
    ];

    /**
     * @return array{format: string, variants: list<string>}
     */
    public static function encoding(): array
    {
        return [
            'format' => 'turbo-stream-html',
            'variants' => array_values(self::PAYLOAD_VARIANTS),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function encodingVariant(array $payload): string
    {
        $key = array_key_first($payload);

        return self::PAYLOAD_VARIANTS[$key] ?? throw new \InvalidArgumentException('Unknown Turbo broadcast payload.');
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
            return [self::INLINE_PAYLOAD => $this->html];
        }

        $compressed = base64_encode(gzencode($this->html, 6));

        if (strlen($compressed) <= $limit) {
            return [self::COMPRESSED_PAYLOAD => $compressed];
        }

        return [self::POINTER_PAYLOAD => true, 'roomId' => $this->roomId];
    }
}
