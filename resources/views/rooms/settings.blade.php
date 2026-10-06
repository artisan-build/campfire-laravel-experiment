@extends('layouts.app', ['title' => $room->displayName($currentUser).' settings'])
@section('nav')<x-ui.button href="/rooms/{{ $room->id }}" variant="ghost">Back to room</x-ui.button>@endsection
@section('content')
<livewire:room-settings :room="$room" />
@endsection
