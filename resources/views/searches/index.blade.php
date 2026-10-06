@extends('layouts.app', ['title' => 'Search'])
@section('nav')<x-ui.button href="{{ route('chat.root') }}" variant="ghost">Back to chat</x-ui.button><h1 class="text-lg font-black">Search</h1>@endsection
@section('content')
<livewire:search-messages />
@endsection
