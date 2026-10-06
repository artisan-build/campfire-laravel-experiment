<?php

namespace App\Support;

use App\Events\SidebarChanged;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SidebarEvents
{
    public function refresh(array $userIds): void
    {
        DB::afterCommit(function () use ($userIds) {
            foreach (User::active()->whereIn('id', array_unique($userIds))->where('role', '!=', 2)->get() as $user) {
                SidebarChanged::dispatch($user->id);
            }
        });
    }
}
