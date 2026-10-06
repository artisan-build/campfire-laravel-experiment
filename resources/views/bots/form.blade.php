@extends('layouts.app', ['title' => $bot ? 'Edit bot' : 'New chat bot'])
@section('nav')<x-ui.button href="/account/bots" variant="ghost">Back to chat bots</x-ui.button>@endsection
@section('content')
<div class="w-full p-4 sm:p-8" data-testid="settings-bot-form">
<x-ui.panel class="max-w-xl">
    <h1 class="text-3xl font-black tracking-tight">{{ $bot ? 'Edit chat bot' : 'New chat bot' }}</h1>
    <form action="/account/bots{{ $bot ? '/'.$bot->id : '' }}" method="post" enctype="multipart/form-data" class="mt-6 grid gap-5">@csrf @if($bot)@method('PATCH')@endif
        <label class="mx-auto grid cursor-pointer place-items-center gap-2" data-controller="upload-preview"><img class="size-24 rounded-full object-cover ring-4 ring-orange-100 dark:ring-orange-950" src="{{ $bot ? $bot->avatarUrl() : app(\App\Support\Assets::class)->path('default-bot-avatar.svg') }}" width="96" height="96" alt="Bot avatar" data-upload-preview-target="image"><span class="text-sm font-semibold text-orange-700 dark:text-orange-400">Choose avatar</span><input class="sr-only" type="file" name="user[avatar]" accept="image/*" data-upload-preview-target="input" data-action="upload-preview#previewImage"></label>
        <x-ui.field label="Bot name"><input name="user[name]" value="{{ $bot?->name }}" required autofocus autocomplete="name"></x-ui.field>
        <x-ui.field label="Webhook URL"><input type="url" name="user[webhook_url]" value="{{ $webhook }}" placeholder="https://example.com/webhook"></x-ui.field>
        <x-ui.button type="submit" variant="primary">Save changes</x-ui.button>
    </form>
    @if($bot)
        <div class="mt-8 flex flex-col justify-between gap-3 border-t border-stone-200 pt-6 sm:flex-row dark:border-stone-700"><form action="/account/bots/{{ $bot->id }}" method="post">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger" data-confirm="Are you sure you want to permanently remove this bot from the account? This can’t be undone." data-turbo-confirm="Are you sure you want to permanently remove this bot from the account? This can’t be undone.">Delete bot</x-ui.button></form><form action="/account/bots/{{ $bot->id }}/key" method="post">@csrf @method('PUT')<x-ui.button type="submit" variant="danger" data-confirm="Are you sure you want to change the bot key? All usage of this bot must be updated." data-turbo-confirm="Are you sure you want to change the bot key? All usage of this bot must be updated.">Generate new key</x-ui.button></form></div>
    @endif
</x-ui.panel>
</div>
@endsection
