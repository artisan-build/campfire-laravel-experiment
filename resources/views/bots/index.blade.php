@extends('layouts.app', ['title' => 'Chat bots'])
@section('nav')<x-ui.button href="{{ route('account.edit', absolute: false) }}" variant="ghost">Back to account settings</x-ui.button>@endsection
@section('content')
<livewire:bot-list />
@endsection
