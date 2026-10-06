<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SessionAuthentication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class SessionController extends Controller
{
    public function new()
    {
        return User::exists() ? view('sessions.new') : redirect()->route('first-run.show');
    }

    public function create(Request $r)
    {
        $key = 'login:'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->view('sessions.new', ['error' => true], 429);
        }
        RateLimiter::hit($key, 180);
        $user = User::active()->where('email_address', trim($r->input('email_address', '')))->first();
        if (! $user || ! $user->password_digest || ! password_verify($r->input('password', ''), $user->password_digest)) {
            return response()->view('sessions.new', ['error' => true], 401);
        }
        $r->session()->regenerate();

        return $this->start($r, $user);
    }

    public function start(Request $r, User $user)
    {
        return redirect(app(SessionAuthentication::class)->start($r, $user));
    }

    public function destroy(Request $r)
    {
        app(SessionAuthentication::class)->stop($r);

        return redirect()->route('chat.root');
    }
}
