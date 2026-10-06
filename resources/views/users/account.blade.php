@extends('layouts.app', ['title' => 'Account settings'])
@section('nav')
<x-ui.button href="/" variant="ghost">Back to chat</x-ui.button>
@if($currentUser->role === 1)<div class="ml-auto flex gap-2"><x-ui.button href="/account/bots">Chat bots</x-ui.button><x-ui.button href="/account/custom_styles/edit">Custom styles</x-ui.button></div>@endif
@endsection
@section('content')
<livewire:account-settings />
@endsection
