<?php

namespace App\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use LengthException;
use Psr\Http\Message\StreamInterface;

final class BoundedResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private bool $exceeded = false;

    public function __construct(
        private StreamInterface $stream,
        private int $maximumBytes = WebhookDestinations::MAX_RESPONSE_BYTES,
    ) {}

    public function write($string): int
    {
        if ($this->tell() + strlen($string) > $this->maximumBytes) {
            $this->reject('Webhook response exceeded the allowed size.');
        }

        return $this->stream->write($string);
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    public function reject(string $message): never
    {
        $this->exceeded = true;

        throw new LengthException($message);
    }
}
