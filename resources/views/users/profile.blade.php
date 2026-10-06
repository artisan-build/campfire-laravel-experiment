@extends('layouts.app', ['title' => $user->name])
@section('nav')
<x-ui.button href="{{ route('chat.root') }}" variant="ghost">Back to chat</x-ui.button>
<livewire:logout-button />
@endsection
@section('content')
<livewire:profile-settings />
@endsection
