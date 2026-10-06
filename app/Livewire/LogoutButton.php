<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\SessionAuthentication;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class LogoutButton extends Component
{
    public function logout(?string $pushEndpoint = null)
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if (is_string($pushEndpoint) && $pushEndpoint !== '') {
            validator(['endpoint' => $pushEndpoint], ['endpoint' => 'string|max:2048'])->validate();
            DB::table('push_subscriptions')->where('user_id', $user->id)->where('endpoint', $pushEndpoint)->delete();
        }

        app(SessionAuthentication::class)->stop(request());

        return $this->redirectRoute('session.new');
    }

    public function render()
    {
        return view('livewire.logout-button');
    }
}
