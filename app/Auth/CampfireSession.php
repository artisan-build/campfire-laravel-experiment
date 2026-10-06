<?php

namespace App\Auth;

use App\Models\User;
use App\Support\RailsCrypto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CampfireSession
{
    public function session(Request $request): ?object
    {
        if ($request->attributes->has('campfire_session')) {
            return $request->attributes->get('campfire_session');
        }

        $token = app(RailsCrypto::class)->verifyCookie('session_token', $request->cookie('session_token'));
        $session = is_string($token) ? DB::table('sessions')->where('token', $token)->first() : null;
        $request->attributes->set('campfire_session', $session);

        return $session;
    }

    public function user(Request $request, bool $includeBots = false): ?User
    {
        $session = $this->session($request);
        $user = $session ? User::active()->find($session->user_id) : null;

        return $user && ($includeBots || $user->role !== 2) ? $user : null;
    }
}
