<div class="w-full p-4 sm:p-8" data-testid="room-settings">
    <x-ui.panel class="grid gap-5">
        <div><p class="text-sm font-semibold uppercase tracking-[0.18em] text-orange-600">Room settings</p><h1 class="mt-1 text-3xl font-black tracking-tight">{{ $room->displayName(auth()->user()) }}</h1></div>
        <div class="grid gap-3 sm:grid-cols-2">
            <x-ui.button href="{{ route('rooms.involvement', $room, absolute: false) }}">Notifications</x-ui.button>
            @can('update', $room)<x-ui.button href="{{ route('rooms.edit', ['kind' => $room->type === 'Rooms::Open' ? 'opens' : 'closeds', 'id' => $room], absolute: false) }}">Edit room</x-ui.button>@endcan
        </div>
    </x-ui.panel>
</div>
