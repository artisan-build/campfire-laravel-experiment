<?php

namespace App\Http\Middleware;

use App\Auth\CampfireSession;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class AuthenticateCampfire
{
    public function __construct(private readonly CampfireSession $campfireSession) {}

    public function handle(Request $request, Closure $next)
    {
        if (DB::table('bans')->where('ip_address', $request->ip())->exists()) {
            abort(403);
        }
        $key = $request->input('bot_key');
        if (is_string($key)) {
            $parts = explode('-', trim($key), 2);
            if (count($parts) === 2 && User::active()->where('role', 2)->where('id', $parts[0])->where('bot_token', $parts[1])->exists()) {
                abort(403);
            }
        }
        $session = $this->campfireSession->session($request);
        $user = $this->campfireSession->user($request, includeBots: true);
        if (! $user) {
            $request->session()->put('return_to', $request->getRequestUri());

            return redirect('/session/new');
        }
        if ($user->role === 2) {
            abort(403);
        }
        Auth::guard()->setUser($user);
        $request->attributes->set('campfire_user', $user);
        view()->share('currentUser', $user);
        if (strtotime($session->last_active_at) < time() - 3600) {
            DB::table('sessions')->where('id', $session->id)->update(['last_active_at' => now(), 'updated_at' => now(), 'user_agent' => $request->userAgent(), 'ip_address' => $request->ip()]);
        }

        return $next($request);
    }
}
