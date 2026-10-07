@extends('layouts.app', ['title' => 'Account settings'])
@section('nav')
<x-ui.button href="{{ route('chat.root', absolute: false) }}" variant="ghost">Back to chat</x-ui.button>
@if($currentUser->role === 1)<div class="ml-auto flex gap-2"><x-ui.button href="{{ route('bots.index', absolute: false) }}">Chat bots</x-ui.button><x-ui.button href="{{ route('account.styles.edit', absolute: false) }}">Custom styles</x-ui.button></div>@endif
@endsection
@section('content')
<livewire:account-settings />
@endsection
