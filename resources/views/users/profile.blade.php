@extends('layouts.app', ['title' => $user->name])
@section('nav')
<x-ui.button href="/" variant="ghost">Back to chat</x-ui.button>
<form action="/session" method="post" data-controller="sessions" class="ml-auto">@csrf @method('DELETE')<input type="hidden" name="push_subscription_endpoint" data-sessions-target="pushSubscriptionEndpoint"><x-ui.button type="submit" data-action="sessions#logout:prevent">Log out</x-ui.button></form>
@endsection
@section('content')
<div class="w-full p-4 sm:p-8" data-testid="settings-profile">
<x-ui.panel class="grid gap-8" style="view-transition-name: avatar-{{ $user->id }}">
    <div class="flex flex-col items-center gap-3" data-controller="upload-preview">
        <img class="size-40 rounded-full object-cover ring-4 ring-orange-100 dark:ring-orange-950" src="{{ $user->avatarUrl() }}" width="160" height="160" data-upload-preview-target="image" alt="Your avatar">
        <div class="flex flex-wrap justify-center gap-2">
            <form action="/users/me/profile" method="post" enctype="multipart/form-data" data-controller="form">@csrf @method('PATCH')
                <label class="inline-flex min-h-10 cursor-pointer items-center rounded-full border border-stone-300 bg-white px-4 py-2 text-sm font-semibold hover:border-orange-400 dark:border-stone-600 dark:bg-stone-900"><input class="sr-only" type="file" name="user[avatar]" accept="image/*" data-upload-preview-target="input" data-action="upload-preview#previewImage change->form#submit">Upload avatar</label>
            </form>
            @if(\App\Models\Attachment::where('record_type','User')->where('record_id',$user->id)->where('name','avatar')->exists())
                <form action="{{ $user->avatarUrl() }}" method="post">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger">Delete avatar</x-ui.button></form>
            @endif
        </div>
    </div>

    <form action="/users/me/profile" method="post" class="grid gap-4">@csrf @method('PATCH')
        <x-ui.field label="Name"><input name="user[name]" value="{{ $user->name }}" required autofocus autocomplete="name"></x-ui.field>
        <x-ui.field label="Email address"><input type="email" name="user[email_address]" value="{{ $user->email_address }}" autocomplete="username"></x-ui.field>
        <x-ui.field label="Change password"><input type="password" name="user[password]" autocomplete="new-password" maxlength="72" placeholder="Leave blank to keep your password"></x-ui.field>
        <x-ui.field label="Bio"><textarea name="user[bio]" rows="3" maxlength="200" placeholder="A few words about yourself">{{ $user->bio }}</textarea></x-ui.field>
        <x-ui.button type="submit" variant="primary">Save changes</x-ui.button>
    </form>

    <section class="grid gap-3 border-t border-stone-200 pt-6 dark:border-stone-700">
        <div><h2 class="text-xl font-bold">Room notifications</h2><p class="text-sm text-stone-600 dark:text-stone-300">Choose how each room gets your attention.</p></div>
        @foreach($user->memberships()->with('room.users')->get() as $membership)
            <div class="flex flex-col gap-2 rounded-2xl bg-stone-100 p-3 sm:flex-row sm:items-center dark:bg-stone-800"><a class="min-w-0 flex-1 truncate font-semibold hover:underline" href="/rooms/{{ $membership->room_id }}">{{ $membership->room->displayName($user) }}</a><form action="/rooms/{{ $membership->room_id }}/involvement" method="post" data-controller="form">@csrf @method('PUT')<select name="involvement" data-action="change->form#submit" aria-label="Notifications for {{ $membership->room->displayName($user) }}">@foreach(['everything'=>'All messages','mentions'=>'Mentions','nothing'=>'None','invisible'=>'Hide room'] as $value=>$text)@if($membership->room->type !== 'Rooms::Direct' || in_array($value,['everything','nothing']))<option value="{{ $value }}" @selected($membership->involvement === $value)>{{ $text }}</option>@endif @endforeach</select></form></div>
        @endforeach
        <x-ui.button href="/users/me/push_subscriptions">Notifications on your devices</x-ui.button>
    </section>

    @php($transferUrl = url('/session/transfers/'.app(\App\Support\RailsCrypto::class)->signedId($user->id, 'User', 'transfer', now()->addHours(4)->utc()->format('Y-m-d\TH:i:s.v\Z'))))
    <section class="grid gap-4 border-t border-stone-200 pt-6 dark:border-stone-700" data-testid="settings-device-transfer">
        <div><h2 class="text-xl font-bold">Sign in on another device</h2><p class="text-sm text-stone-600 dark:text-stone-300">Treat this private link like a password. It expires in four hours.</p></div>
        <label class="sr-only" for="session_transfer_url">Private sign-in link</label><input type="text" id="session_transfer_url" value="{{ $transferUrl }}" readonly>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="/qr_code/{{ rtrim(strtr(base64_encode($transferUrl), '+/', '-_'), '=') }}">Show QR code</x-ui.button>
            <span x-data="clipboard(@js($transferUrl))"><x-ui.button @click="copy()" x-bind:class="{ 'bg-emerald-600 text-white': copied }"><span x-text="copied ? 'Copied' : 'Copy link'">Copy link</span></x-ui.button></span>
            <span x-data="webShare(@js(['title' => 'Your sign-in link', 'text' => 'This is your private Campfire sign-in URL.', 'url' => $transferUrl]))" x-cloak x-show="supported"><x-ui.button @click="share()">Share link</x-ui.button></span>
        </div>
    </section>
</x-ui.panel>
</div>
@endsection
