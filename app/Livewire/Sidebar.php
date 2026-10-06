<?php

namespace App\Livewire;

use App\Models\User;
use Livewire\Component;

final class Sidebar extends Component
{
    /** @return array<string, string> */
    protected function getListeners(): array
    {
        $userId = $this->user()->id;

        return [
            'echo-private:users.'.$userId.'.rooms,.sidebar.changed' => '$refresh',
            'echo-private:users.'.$userId.'.unreads,.unread' => '$refresh',
            'echo-private:users.'.$userId.'.reads,.read' => '$refresh',
        ];
    }

    public function render()
    {
        $currentUser = $this->user();
        $memberships = $currentUser->memberships()
            ->where('involvement', '!=', 'invisible')
            ->with('room.users')
            ->get();

        return view('users.sidebar', [
            'currentUser' => $currentUser,
            'directs' => $memberships
                ->filter(fn ($membership) => $membership->room->type === 'Rooms::Direct')
                ->sortByDesc(fn ($membership) => $membership->room->updated_at),
            'shared' => $memberships
                ->reject(fn ($membership) => $membership->room->type === 'Rooms::Direct')
                ->sortBy(fn ($membership) => mb_strtolower($membership->room->name ?? '')),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->status === 0 && $user->role !== 2, 403);

        return $user;
    }
}
