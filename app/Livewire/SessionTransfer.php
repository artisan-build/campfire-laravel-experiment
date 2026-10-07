<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\SessionAuthentication;
use App\Support\SignedIdentifiers;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class SessionTransfer extends Component
{
    #[Locked]
    public string $transferId;

    public function mount(string $transferId): void
    {
        $this->transferId = $transferId;
    }

    public function confirm()
    {
        $user = User::active()->find(app(SignedIdentifiers::class)->verifyId($this->transferId, 'User', 'transfer'));
        abort_unless($user instanceof User, 400);
        session()->regenerate();

        return $this->redirect(app(SessionAuthentication::class)->start(request(), $user));
    }

    public function render()
    {
        return view('livewire.session-transfer');
    }
}
