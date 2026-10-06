<?php

namespace App\Livewire;

use App\Models\Room;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class RoomSettings extends Component
{
    public Room $room;

    public function mount(Room $room): void
    {
        Gate::authorize('view', $room);
        $this->room = $room;
    }

    public function render()
    {
        return view('livewire.room-settings');
    }
}
