<?php

namespace App\Livewire;

use App\Models\Membership;
use App\Models\User;
use App\Support\SidebarEvents;
use Livewire\Component;

final class RoomInvolvement extends Component
{
    public int $roomId;

    public string $involvement;

    public function mount(int $roomId): void
    {
        $membership = $this->membership($roomId);
        $this->roomId = $roomId;
        $this->involvement = $membership->involvement;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'involvement' => 'required|in:invisible,nothing,mentions,everything',
        ]);
        $this->membership($this->roomId)->update($validated);
        app(SidebarEvents::class)->refresh([$this->user()->id]);
        session()->flash('notice', 'Notification preference saved.');
    }

    public function render()
    {
        return view('livewire.room-involvement', ['membership' => $this->membership($this->roomId)]);
    }

    private function membership(int $roomId): Membership
    {
        return $this->user()->memberships()->where('room_id', $roomId)->firstOrFail();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
