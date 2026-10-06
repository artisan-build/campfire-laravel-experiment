<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Component;

final class BotList extends Component
{
    public function mount(): void
    {
        $this->authorizeAdministrator();
    }

    public function rotateKey(int $botId): void
    {
        $this->authorizeAdministrator();
        $this->bot($botId)->update(['bot_token' => Str::random(12)]);
    }

    public function delete(int $botId): void
    {
        $this->authorizeAdministrator();
        $this->bot($botId)->deactivate();
    }

    public function render()
    {
        $this->authorizeAdministrator();

        return view('livewire.bot-list', ['bots' => User::active()->where('role', 2)->get()]);
    }

    private function bot(int $id): User
    {
        return User::active()->where('role', 2)->findOrFail($id);
    }

    private function authorizeAdministrator(): void
    {
        abort_unless(auth()->user() instanceof User && auth()->user()->role === 1, 403);
    }
}
