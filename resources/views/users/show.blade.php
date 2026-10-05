@extends('layouts.app', ['title' => $user->name])
@section('nav')<x-ui.button href="/" variant="ghost">Back to chat</x-ui.button>@endsection
@section('content')
<div class="w-full p-4 sm:p-8" data-testid="user-profile-card">
    <x-ui.panel class="max-w-xl text-center">
        <img class="mx-auto size-32 rounded-full object-cover ring-4 ring-orange-100 dark:ring-orange-950" width="128" height="128" src="{{ $user->avatarUrl() }}" alt="">
        <h1 class="mt-5 text-3xl font-black tracking-tight">{{ $user->name }}</h1>
        @if($user->bio)<p class="mt-2 text-stone-600 dark:text-stone-300">{{ $user->bio }}</p>@endif
        <form action="/rooms/directs" method="post" class="mt-6">@csrf<input type="hidden" name="user_ids[]" value="{{ $user->id }}"><x-ui.button type="submit" variant="primary">Ping {{ $user->name }}</x-ui.button></form>
    </x-ui.panel>
</div>
@endsection
