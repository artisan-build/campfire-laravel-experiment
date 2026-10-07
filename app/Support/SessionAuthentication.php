<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

final class SessionAuthentication
{
    public function start(Request $request, User $user): string
    {
        $token = bin2hex(random_bytes(18));
        DB::table('sessions')->insert([
            'user_id' => $user->id,
            'token' => $token,
            'last_active_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Cookie::queue(cookie(
            'session_token',
            $token,
            60 * 24 * 365 * 20,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));

        return session()->pull('return_to', route('chat.root'));
    }

    public function stop(Request $request): void
    {
        $token = $request->cookie('session_token');
        if (is_string($token)) {
            DB::table('sessions')->where('token', $token)->delete();
        }
        session()->invalidate();
        session()->regenerateToken();
        Cookie::queue(Cookie::forget('session_token'));
    }
}
