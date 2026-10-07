<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\SessionAuthentication;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

final class SignIn extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $failed = false;

    public function login()
    {
        $key = 'login:'.request()->ip();
        $this->failed = false;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->failed = true;

            return null;
        }

        RateLimiter::hit($key, 180);
        $user = User::active()->where('email_address', trim($this->email))->first();
        if (! $user || ! $user->password_digest || ! password_verify($this->password, $user->password_digest)) {
            $this->failed = true;

            return null;
        }

        session()->regenerate();
        $target = app(SessionAuthentication::class)->start(request(), $user);

        return $this->redirect($target);
    }

    public function render()
    {
        return view('livewire.sign-in');
    }
}
