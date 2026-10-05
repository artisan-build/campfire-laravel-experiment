<?php

namespace App\Support;

use App\Events\BoostAdded;
use App\Events\BoostRemoved;
use App\Events\MessageDeleted;
use App\Events\MessagePosted;
use App\Events\MessageUpdated;
use App\Http\Controllers\ChatController;
use App\Models\Boost;
use App\Models\Message;

final class ChatEvents
{
    public function created(Message $m): string
    {
        $m->load(['creator', 'room.users', 'richText', 'boosts.booster', 'attachment.blob']);
        $s = '';
        if (config('campfire.json_message_stream')) {
            MessagePosted::dispatch($m);
        } else {
            $html = view('messages.message', ['message' => $m])->render();
            $s = app(ChatController::class)->stream('append', 'messages_room_'.$m->room_id, $html);
            app(Broadcasting::class)->room($m->room_id, $s);
        }
        foreach ($m->room->memberships()->pluck('user_id') as $id) {
            app(Broadcasting::class)->unread((int) $id, $m->room_id);
        }

        return $s;
    }

    public function updated(Message $m): string
    {
        $m->refresh()->load(['creator', 'room.users', 'richText', 'boosts.booster', 'attachment.blob']);
        $s = '';
        if (config('campfire.json_message_stream')) {
            MessageUpdated::dispatch($m);
        } else {
            $s = app(ChatController::class)->stream('replace', 'presentation_message_'.$m->client_message_id, view('messages.presentation', ['message' => $m])->render());
            app(Broadcasting::class)->room($m->room_id, $s);
        }

        return $s;
    }

    public function delete(Message $m): string
    {
        $event = new MessageDeleted($m);
        $target = 'message_'.$m->client_message_id;
        $roomId = (int) $m->room_id;
        app(MessageWriter::class)->destroy($m);
        $s = '';
        if (config('campfire.json_message_stream')) {
            event($event);
        } else {
            $s = app(ChatController::class)->stream('remove', $target, '');
            app(Broadcasting::class)->room($roomId, $s);
        }

        return $s;
    }

    public function boostAdded(Message $m, Boost $boost): string
    {
        $boost->load('booster');
        $s = '';
        if (config('campfire.json_message_stream')) {
            BoostAdded::dispatch($m, $boost);
        } else {
            $html = view('boosts.boost', compact('boost'))->render();
            $s = app(ChatController::class)->stream('append', 'boosts_message_'.$m->client_message_id, $html);
            app(Broadcasting::class)->room($m->room_id, $s);
        }

        return $s;
    }

    public function removeBoost(Message $m, Boost $boost): string
    {
        $event = new BoostRemoved($m, $boost);
        $boostId = (int) $boost->id;
        $boost->delete();
        $s = '';
        if (config('campfire.json_message_stream')) {
            event($event);
        } else {
            $s = app(ChatController::class)->stream('remove', 'boost_'.$boostId, '');
            app(Broadcasting::class)->room($m->room_id, $s);
        }

        return $s;
    }
}
