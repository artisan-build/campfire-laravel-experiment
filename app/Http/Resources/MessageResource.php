<?php

namespace App\Http\Resources;

use App\Models\Boost;
use App\Models\Message;
use App\Models\RichText;
use App\Models\User;
use App\Support\BlobStorage;
use App\Support\RichTextRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
final class MessageResource extends JsonResource
{
    public function __construct(Message $resource)
    {
        $resource->loadMissing(['creator', 'room', 'richText', 'boosts.booster', 'attachment.blob']);
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Message $message */
        $message = $this->resource;
        $renderer = app(RichTextRenderer::class);
        $richText = $message->getRelation('richText');
        $storedBody = $richText instanceof RichText ? (string) $richText->body : '';
        $mentionIds = $renderer->mentions($storedBody);
        $mentionedUsers = $mentionIds === [] ? collect() : User::query()->whereKey($mentionIds)->get()->keyBy('id');
        $blob = $message->attachment?->blob;
        $attachment = null;

        if ($blob !== null) {
            $storage = app(BlobStorage::class);
            $representationUrl = null;

            if (str_starts_with((string) $blob->content_type, 'image/')) {
                $representationUrl = $storage->representationUrl($blob, [
                    'resize_to_limit' => [1200, 800],
                    'format' => $storage->thumbnailFormat($blob),
                ]);
            } elseif (str_starts_with((string) $blob->content_type, 'video/')) {
                $representationUrl = $storage->representationUrl($blob, [
                    'resize_to_limit' => [1200, 800],
                    'format' => 'webp',
                ]);
            }

            $attachment = [
                'url' => url($storage->url($blob)),
                'content_type' => $blob->content_type,
                'representation_url' => $representationUrl === null ? null : url($representationUrl),
                'filename' => $blob->filename,
                'byte_size' => (int) $blob->byte_size,
            ];
        }

        return [
            'id' => (int) $message->id,
            'client_message_id' => (string) $message->client_message_id,
            'created_at' => $message->created_at->toISOString(),
            'updated_at' => $message->updated_at->toISOString(),
            'body' => [
                'plain_text' => $message->plainText(),
                'html' => $renderer->html($storedBody),
                'editable_html' => $storedBody,
                'truncated' => false,
            ],
            'creator' => self::userArray($message->creator, true),
            'room' => [
                'id' => (int) $message->room->id,
                'name' => $message->room->name,
                'type' => $message->room->type,
            ],
            'url' => url('/rooms/'.$message->room_id.'/messages/'.$message->id),
            'attachment' => $attachment,
            'boosts' => $message->boosts->sortBy('id')->map(fn (Boost $boost) => self::boostArray($boost))->values()->all(),
            'mentions' => collect($mentionIds)->map(function (int $id) use ($mentionedUsers): array {
                $user = $mentionedUsers->get($id);

                return ['id' => $id, 'name' => $user?->name];
            })->all(),
        ];
    }

    /**
     * Keep ordinary messages complete. For a body whose duplicate plain/HTML representations would
     * exceed the application frame budget, send a marked valid-HTML preview; the canonical URL in
     * the same resource remains the fetch path for the complete MessageResource.
     *
     * @return array<string, mixed>
     */
    public function forBroadcast(): array
    {
        $message = $this->resolve(new Request);
        $budget = (int) config('campfire.broadcast_payload_limit');

        if ($this->payloadBytes($message) <= $budget) {
            return $message;
        }

        $plain = (string) $message['body']['plain_text'];

        foreach ([1024, 768, 512, 256, 0] as $bytes) {
            $preview = mb_strcut($plain, 0, $bytes, 'UTF-8');
            $message['body'] = [
                'plain_text' => $preview,
                'html' => $preview === '' ? '' : '<p>'.e($preview).'</p>',
                'editable_html' => null,
                'truncated' => true,
            ];

            if ($this->payloadBytes($message) <= $budget) {
                return $message;
            }
        }

        $message['truncation'] = [
            'fetch_required' => true,
            'boosts' => ['total' => count($message['boosts']), 'included' => count($message['boosts'])],
            'mentions' => ['total' => count($message['mentions']), 'included' => count($message['mentions'])],
            'attachment_included' => $message['attachment'] !== null,
        ];

        foreach (['boosts', 'mentions'] as $relationship) {
            while ($this->payloadBytes($message) > $budget && $message[$relationship] !== []) {
                array_pop($message[$relationship]);
                $message['truncation'][$relationship]['included'] = count($message[$relationship]);
            }
        }

        if ($this->payloadBytes($message) <= $budget) {
            return $message;
        }

        $message['attachment'] = null;
        $message['truncation']['attachment_included'] = false;

        return $message;
    }

    /** @return array<string, mixed> */
    public static function boostArray(Boost $boost): array
    {
        $boost->loadMissing('booster');

        return [
            'id' => (int) $boost->id,
            'content' => (string) $boost->content,
            'created_at' => $boost->created_at->toISOString(),
            'booster' => self::userArray($boost->booster),
        ];
    }

    /** @return array<string, mixed> */
    private static function userArray(User $user, bool $withAvatar = false): array
    {
        $identity = [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'role' => match ((int) $user->role) {
                0 => 'member',
                1 => 'administrator',
                2 => 'bot',
                default => 'unknown',
            },
        ];

        if ($withAvatar) {
            $identity['avatar_url'] = url($user->avatarUrl());
        }

        return $identity;
    }

    /** @param array<string, mixed> $message */
    private function payloadBytes(array $message): int
    {
        return strlen(json_encode(['message' => $message], JSON_THROW_ON_ERROR));
    }
}
