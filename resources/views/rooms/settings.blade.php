@extends('layouts.app', ['title' => $room->displayName($currentUser).' settings'])
@section('nav')<x-ui.button href="{{ route('rooms.show', $room) }}" variant="ghost">Back to room</x-ui.button>@endsection
@section('content')
<livewire:room-settings :room="$room" />
@endsection
