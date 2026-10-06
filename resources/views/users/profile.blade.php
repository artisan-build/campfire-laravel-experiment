@extends('layouts.app', ['title' => $user->name])
@section('nav')
<x-ui.button href="/" variant="ghost">Back to chat</x-ui.button>
<form action="/session" method="post" data-controller="sessions" class="ml-auto">@csrf @method('DELETE')<input type="hidden" name="push_subscription_endpoint" data-sessions-target="pushSubscriptionEndpoint"><x-ui.button type="submit" data-action="sessions#logout:prevent">Log out</x-ui.button></form>
@endsection
@section('content')
<livewire:profile-settings />
@endsection
