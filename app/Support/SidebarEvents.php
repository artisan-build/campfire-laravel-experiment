<?php

namespace App\Support;

use App\Events\SidebarChanged;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SidebarEvents
{
    public function globalRemove(int $roomId): void
    {
        if (config('campfire.json_message_stream')) {
            return;
        }

        DB::afterCommit(fn () => app(Broadcasting::class)->roomList('<turbo-stream action="remove" target="list_room_'.$roomId.'"></turbo-stream>'));
    }

    public function refresh(array $userIds): void
    {
        DB::afterCommit(function () use ($userIds) {
            foreach (User::active()->whereIn('id', array_unique($userIds))->where('role', '!=', 2)->get() as $user) {
                SidebarChanged::dispatch($user->id);
            }
        });
    }
}
