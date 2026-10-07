<?php

namespace App\Support;

use App\Events\BoostAdded;
use App\Events\BoostRemoved;
use App\Events\MessageDeleted;
use App\Events\MessagePosted;
use App\Events\MessageUpdated;
use App\Models\Boost;
use App\Models\Message;

final class ChatEvents
{
    public function created(Message $m): void
    {
        $m->load(['creator', 'room.users', 'richText', 'boosts.booster', 'attachment.blob']);
        MessagePosted::dispatch($m);
        foreach ($m->room->memberships()->pluck('user_id') as $id) {
            app(Broadcasting::class)->unread((int) $id, $m->room_id);
        }
    }

    public function updated(Message $m): void
    {
        $m->refresh()->load(['creator', 'room.users', 'richText', 'boosts.booster', 'attachment.blob']);
        MessageUpdated::dispatch($m);
    }

    public function delete(Message $m): void
    {
        $event = new MessageDeleted($m);
        app(MessageWriter::class)->destroy($m);
        event($event);
    }

    public function boostAdded(Message $m, Boost $boost): void
    {
        $boost->load('booster');
        BoostAdded::dispatch($m, $boost);
    }

    public function removeBoost(Message $m, Boost $boost): void
    {
        $event = new BoostRemoved($m, $boost);
        $boost->delete();
        event($event);
    }
}
