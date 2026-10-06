@extends('layouts.app', ['title' => 'Device notifications'])
@section('nav')<x-ui.button href="{{ route('profile.show', ['user' => 'me']) }}" variant="ghost">Back to my settings</x-ui.button>@endsection
@section('content')
<livewire:push-subscriptions />
@endsection
