@extends('layouts.app', ['title' => $botId ? 'Edit bot' : 'New chat bot'])
@section('nav')<x-ui.button href="{{ route('bots.index') }}" variant="ghost">Back to chat bots</x-ui.button>@endsection
@section('content')
<livewire:bot-form :bot-id="$botId" />
@endsection
