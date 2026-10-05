@extends('layouts.app', ['title' => $room->displayName($currentUser).' settings'])
@section('nav')
    <x-ui.button href="/rooms/{{ $room->id }}" variant="ghost">Back to room</x-ui.button>
@endsection
@section('content')
<div class="w-full p-4 sm:p-8" data-testid="room-settings">
    <x-ui.panel class="grid gap-5">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-orange-600">Room settings</p>
            <h1 class="mt-1 text-3xl font-black tracking-tight">{{ $room->displayName($currentUser) }}</h1>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <x-ui.button href="/rooms/{{ $room->id }}/involvement">Notifications</x-ui.button>
            @if($room->type !== 'Rooms::Direct')
                <x-ui.button href="/rooms/{{ $room->type === 'Rooms::Open' ? 'opens' : 'closeds' }}/{{ $room->id }}/edit">Edit room</x-ui.button>
            @endif
        </div>
    </x-ui.panel>
</div>
@endsection
