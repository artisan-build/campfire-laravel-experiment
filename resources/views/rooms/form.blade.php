@extends('layouts.app', ['title' => $room ? 'Edit settings' : ($kind === 'directs' ? 'New Ping' : 'New chat room')])
@section('nav')<x-ui.button href="{{ route('chat.root') }}" variant="ghost">Back to chat</x-ui.button>@endsection
@section('content')
<livewire:room-form :kind="$kind" :room="$room" />
@endsection
