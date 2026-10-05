@extends('layouts.app', ['title' => 'Chat bots'])
@section('nav')<x-ui.button href="/account/edit" variant="ghost">Back to account settings</x-ui.button>@endsection
@section('content')
<div class="w-full p-4 sm:p-8" data-testid="settings-bots">
<x-ui.panel class="max-w-5xl">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-bold uppercase tracking-[0.18em] text-orange-600">Integrations</p><h1 class="text-3xl font-black tracking-tight">Chat bots</h1><p class="mt-2 text-stone-600 dark:text-stone-300">Let other services post updates directly to Campfire.</p></div><x-ui.button href="/account/bots/new" variant="primary">Add a chat bot</x-ui.button></div>
    <div class="mt-8 grid gap-5">
    @foreach($bots as $bot)
        <article class="grid gap-4 rounded-2xl border border-stone-200 bg-stone-50 p-4 dark:border-stone-700 dark:bg-stone-950">
            <div class="flex items-center gap-3"><img class="size-12 rounded-full object-cover" src="{{ $bot->avatarUrl() }}" width="48" height="48" alt=""><strong class="text-xl">{{ $bot->name }}</strong><x-ui.button href="/account/bots/{{ $bot->id }}/edit" class="ml-auto">Edit</x-ui.button></div>
            @foreach($bot->rooms()->where('type','!=','Rooms::Direct')->orderBy('name')->get() as $room)
                <fieldset class="grid gap-3 rounded-2xl border border-stone-200 p-4 dark:border-stone-700"><legend class="px-2 font-bold">{{ $room->name }}</legend>
                @foreach(["curl -d 'Hello!' ".url('/rooms/'.$room->id.'/'.$bot->id.'-'.$bot->bot_token.'/messages') => 'curl command for posting messages', 'curl -F "attachment=@/path/to/file" '.url('/rooms/'.$room->id.'/'.$bot->id.'-'.$bot->bot_token.'/messages') => 'curl command for posting attachments'] as $command=>$label)
                    <div class="flex flex-col gap-2 sm:flex-row" x-data="clipboard(@js($command))"><input class="min-w-0 flex-1 font-mono text-sm" type="text" readonly aria-label="{{ $label }}" value="{{ $command }}"><x-ui.button @click="copy()" x-bind:class="{ 'bg-emerald-600 text-white': copied }"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></x-ui.button></div>
                @endforeach
                </fieldset>
            @endforeach
        </article>
    @endforeach
    </div>
</x-ui.panel>
</div>
@endsection
