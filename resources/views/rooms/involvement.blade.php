@extends('layouts.app', ['title' => 'Room notifications'])
@section('nav')<x-ui.button href="{{ route('rooms.show', $membership->room_id) }}" variant="ghost">Back to room</x-ui.button>@endsection
@section('content')
<livewire:room-involvement :room-id="$membership->room_id" />
@endsection
