<?php

namespace App\Jobs;

use App\Models\Message;
use App\Support\BoundedResponseStream;
use App\Support\ChatEvents;
use App\Support\MessageWriter;
use App\Support\Presence;
use App\Support\RichTextRenderer;
use App\Support\WebhookDestinations;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class DeliverMessageNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId, public bool $webhooks = false) {}

    public function handle(): void
    {
        $m = Message::presentation()->find($this->messageId);
        if (! $m) {
            return;
        }
        $mentions = app(RichTextRenderer::class)->mentions($m->richText?->body ?? '');
        $bots = $m->room->type === 'Rooms::Direct' ? $m->room->users()->where('role', 2)->where('status', 0)->get() : $m->room->users()->where('role', 2)->where('status', 0)->whereIn('users.id', $mentions)->get();
        foreach ($this->webhooks ? $bots : [] as $bot) {
            if ($bot->id === $m->creator_id || ! $m->room->memberships()->where('user_id', $bot->id)->exists()) {
                continue;
            }
            $url = DB::table('webhooks')->where('user_id', $bot->id)->value('url');
            if (! $url) {
                continue;
            }
            $destinations = app(WebhookDestinations::class);
            $destination = $destinations->resolve($url);
            if ($destination === null) {
                continue;
            }
            $payload = ['user' => ['id' => $m->creator_id, 'name' => $m->creator->name], 'room' => ['id' => $m->room_id, 'name' => $m->room->name, 'path' => '/rooms/'.$m->room_id.'/'.$bot->id.'-'.$bot->bot_token.'/messages'], 'message' => ['id' => $m->id, 'body' => ['html' => $m->richText?->body ?? '', 'plain' => trim(str_replace('@'.$bot->name, '', $m->plainText()))], 'path' => '/rooms/'.$m->room_id.'/@'.$m->id]];
            $path = tempnam(storage_path('framework/cache'), 'webhook-');
            if ($path === false) {
                throw new \RuntimeException('Unable to create webhook response file.');
            }
            $resource = fopen($path, 'w+b');
            if ($resource === false) {
                unlink($path);
                throw new \RuntimeException('Unable to open webhook response file.');
            }
            $sink = new BoundedResponseStream(Utils::streamFor($resource));
            try {
                $reply = Http::connectTimeout(7)->timeout(7)->withOptions($destinations->requestOptions($destination, $sink))->post($url, $payload);
                if ($reply->status() === 200 && in_array(strtok($reply->header('Content-Type'), ';'), ['text/plain', 'text/html'])) {
                    $body = file_get_contents($path);
                    if ($body === false) {
                        throw new \RuntimeException('Unable to read webhook response file.');
                    }
                    $created = app(MessageWriter::class)->create($m->room, $bot, ['body' => $body]);
                    app(ChatEvents::class)->created($created);
                } elseif ($reply->header('Content-Type')) {
                    $mime = strtok($reply->header('Content-Type'), ';');
                    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf', 'audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'application/json' => 'json', 'text/csv' => 'csv'];
                    if (isset($extensions[$mime])) {
                        $file = new UploadedFile($path, 'attachment.'.$extensions[$mime], $mime, null, true);
                        $created = app(MessageWriter::class)->create($m->room, $bot, ['attachment' => $file]);
                        app(ChatEvents::class)->created($created);
                    }
                }
            } catch (Throwable $error) {
                if (! $sink->exceeded()) {
                    if (! $error instanceof ConnectionException) {
                        throw $error;
                    }
                    $created = app(MessageWriter::class)->create($m->room, $bot, ['body' => 'Failed to respond within 7 seconds']);
                    app(ChatEvents::class)->created($created);
                }
            } finally {
                $sink->close();
                unlink($path);
            }
        }
        $present = app(Presence::class)->inRoom($m->room_id);
        $query = DB::table('push_subscriptions as p')->join('memberships as ms', 'ms.user_id', '=', 'p.user_id')->where('ms.room_id', $m->room_id)->where('ms.user_id', '!=', $m->creator_id)->whereNotIn('ms.user_id', $present)->where(fn ($q) => $q->where('ms.involvement', 'everything')->orWhere(fn ($q) => $q->where('ms.involvement', 'mentions')->whereIn('ms.user_id', $mentions)))->select('p.*');
        $payload = DeliverPush::payload($m->room->type === 'Rooms::Direct' ? $m->creator->name : $m->room->name, ($m->room->type === 'Rooms::Direct' ? '' : $m->creator->name.': ').$m->plainText(), '/rooms/'.$m->room_id);
        foreach ($query->get() as $sub) {
            DeliverPush::dispatch((array) $sub, $payload);
        }
    }
}
