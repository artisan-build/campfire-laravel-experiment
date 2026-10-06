@extends('layouts.app', ['title' => $room ? 'Edit settings' : ($kind === 'directs' ? 'New Ping' : 'New chat room')])
@section('nav')<x-ui.button href="/" variant="ghost">Back to chat</x-ui.button>@endsection
@section('content')
@php($assets = app(\App\Support\Assets::class))
<div class="w-full p-4 sm:p-8" data-testid="room-form">
@if($kind === 'directs' && $room)
    <x-ui.panel class="max-w-xl text-center">
        <h1 class="text-3xl font-black tracking-tight">Ping members</h1>
        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">@foreach($room->users->where('id','!=',$currentUser->id) as $member)<div class="rounded-2xl bg-stone-100 p-4 dark:bg-stone-800"><img class="mx-auto size-20 rounded-full object-cover" src="{{ $member->avatarUrl() }}" width="80" height="80" loading="lazy" alt=""><strong class="mt-2 block truncate">{{ $member->name }}</strong></div>@endforeach</div>
        <form action="/rooms/directs/{{ $room->id }}" method="post" class="mt-8">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger" data-confirm="Are you sure you want to delete this ping and all messages in it? This can’t be undone." data-turbo-confirm="Are you sure you want to delete this ping and all messages in it? This can’t be undone.">Delete Ping</x-ui.button></form>
    </x-ui.panel>
@elseif($kind === 'directs')
    <turbo-frame id="direct_rooms_control" target="_top">
        <x-ui.panel class="max-w-2xl">
            <h1 class="text-3xl font-black tracking-tight">Start a Ping</h1>
            <form action="/rooms/directs" method="post" class="mt-6 grid gap-4" data-controller="form" data-action="keydown.esc->form#cancel">@csrf
                <section class="autocomplete__container unpad input input--actor"><div class="autocomplete__input input flex flex-wrap position-relative flex-item-grow" data-controller="autocomplete" data-autocomplete-url-value="/autocompletable/users">
                    <select name="user_ids[]" data-autocomplete-target="select" data-template-id="autocompletable-user" multiple hidden required></select>
                    <template id="autocompletable-user"><div class="autocomplete__pill max-width" data-value="" tabindex="0"><img class="avatar flex-item-no-shrink" data-content="avatar" src="" alt=""><span class="autocomplete-field__selected-value-text overflow-ellipsis flex-item-grow" data-content="label"></span><button type="button" data-action="autocomplete#remove:prevent" data-value="" tabindex="-1" class="btn btn--plain txt-small translucent flex-item-no-shrink"><img src="{{ $assets->path('remove-circle.svg') }}" aria-hidden="true"><span class="for-screen-reader">Remove <span data-content="screenReaderLabel"></span></span></button></div></template>
                    <input type="text" name="user_ids_input" autocomplete="off" autocorrect="off" data-1p-ignore="true" class="autocomplete__input input flex flex-wrap position-relative" data-autocomplete-target="input" data-action="input->autocomplete#search keydown->autocomplete#didPressKey" aria-label="People to ping" placeholder="Type names">
                </div></section>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><x-ui.button href="/users/me/sidebar" data-turbo-frame="user_sidebar" data-form-target="cancel">Cancel</x-ui.button><x-ui.button type="submit" variant="primary">Start Ping</x-ui.button></div>
            </form>
        </x-ui.panel>
    </turbo-frame>
@else
    <x-ui.panel>
        <div><p class="text-sm font-bold uppercase tracking-[0.18em] text-orange-600">{{ $kind === 'opens' ? 'Open room' : 'Private room' }}</p><h1 class="text-3xl font-black tracking-tight">{{ $room ? 'Edit room' : 'New chat room' }}</h1></div>
        <form action="/rooms/{{ $kind }}{{ $room ? '/'.$room->id : '' }}" method="post" class="mt-6 grid gap-6" data-controller="form">@csrf @if($room)@method('PATCH')@endif
            <x-ui.field label="Room name"><input name="room[name]" id="room_name" value="{{ $room?->name ?? '' }}" required autofocus placeholder="Name the room" data-turbo-permanent="true" data-action="keydown.enter->form#submit:prevent"></x-ui.field>
            <section class="grid gap-4 rounded-2xl bg-stone-100 p-4 dark:bg-stone-800">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center"><div class="flex-1"><h2 class="font-bold">Access</h2><p class="text-sm text-stone-600 dark:text-stone-300">{{ $kind === 'opens' ? 'Everyone can enter this room.' : 'Choose who can enter this room.' }}</p></div><x-ui.button href="/rooms/{{ $kind === 'opens' ? 'closeds' : 'opens' }}/{{ $room ? $room->id.'/edit' : 'new' }}">{{ $kind === 'opens' ? 'Make private' : 'Open to everyone' }}</x-ui.button></div>
                <div class="grid gap-2">
                @foreach($users as $member)
                    <label class="flex items-center gap-3 rounded-xl bg-white p-3 dark:bg-stone-900" data-value="{{ mb_strtolower($member->name) }}"><img class="size-10 rounded-full object-cover" src="{{ $member->avatarUrl() }}" width="40" height="40" alt=""><strong class="min-w-0 flex-1 truncate">{{ $member->name }}</strong>
                    @if($kind === 'opens')<span class="text-sm font-semibold text-stone-500">Included</span>
                    @elseif(!$room && $member->id === $currentUser->id)<input type="hidden" name="user_ids[]" value="{{ $member->id }}"><span class="text-sm font-semibold text-stone-500">You</span>
                    @else<input type="checkbox" name="user_ids[]" value="{{ $member->id }}" class="size-5 accent-orange-500" @checked(in_array($member->id,$selected))><span class="sr-only">Give {{ $member->name }} access</span>@endif
                    </label>
                @endforeach
                </div>
            </section>
            <x-ui.button type="submit" variant="primary">Save room</x-ui.button>
        </form>
        @if($room)<form action="/rooms/{{ $kind }}/{{ $room->id }}" method="post" class="mt-6 border-t border-stone-200 pt-6 text-center dark:border-stone-700">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger" data-confirm="Are you sure you want to delete this room and all messages in it? This can’t be undone." data-turbo-confirm="Are you sure you want to delete this room and all messages in it? This can’t be undone.">Delete {{ $room->name }}</x-ui.button></form>@endif
    </x-ui.panel>
@endif
</div>
@endsection
