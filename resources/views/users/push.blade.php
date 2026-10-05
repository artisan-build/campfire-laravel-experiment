@extends('layouts.app', ['title' => 'Device notifications'])
@section('nav')<x-ui.button href="/users/me/profile" variant="ghost">Back to my settings</x-ui.button>@endsection
@section('content')
<div class="w-full p-4 sm:p-8" data-testid="settings-notifications">
    <x-ui.panel class="grid gap-5">
        <div><p class="text-sm font-bold uppercase tracking-[0.18em] text-orange-600">My settings</p><h1 class="text-3xl font-black tracking-tight">Device notifications</h1></div>
        <div class="grid gap-3">
            @forelse($subscriptions as $subscription)
                <div class="flex flex-col gap-3 rounded-2xl border border-stone-200 p-4 sm:flex-row sm:items-center dark:border-stone-700">
                    <p class="min-w-0 flex-1 break-words text-sm text-stone-600 dark:text-stone-300">{{ $subscription->user_agent }}</p>
                    <form action="/users/me/push_subscriptions/{{ $subscription->id }}" method="post">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger">Remove</x-ui.button></form>
                </div>
            @empty
                <p class="rounded-2xl bg-stone-100 p-4 text-stone-600 dark:bg-stone-800 dark:text-stone-300">No devices are subscribed yet.</p>
            @endforelse
        </div>
    </x-ui.panel>
</div>
@endsection
