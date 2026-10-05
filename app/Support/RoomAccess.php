<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The one membership rule every realtime channel and every realtime command is gated on. */
final class RoomAccess
{
    public static function active(?User $user): bool
    {
        return $user !== null && $user->status === 0 && $user->role !== 2;
    }

    public static function member(?User $user, int $room): bool
    {
        return self::active($user)
            && DB::table('memberships')->where('room_id', $room)->where('user_id', $user->id)->exists();
    }
}
