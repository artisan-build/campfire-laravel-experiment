@extends('layouts.app', ['title' => 'Account settings'])
@section('nav')
<x-ui.button href="/" variant="ghost">Back to chat</x-ui.button>
@if($currentUser->role === 1)<div class="ml-auto flex gap-2"><x-ui.button href="/account/bots" class="hidden sm:inline-flex">Chat bots</x-ui.button><x-ui.button href="/account/custom_styles/edit">Custom styles</x-ui.button></div>@endif
@endsection
@section('content')
<div class="w-full p-4 sm:p-8" data-testid="settings-account">
<x-ui.panel class="grid gap-8" style="view-transition-name: account-settings">
    <div><p class="text-sm font-bold uppercase tracking-[0.18em] text-orange-600">Administration</p><h1 class="text-3xl font-black tracking-tight">Account settings</h1></div>
    @if($currentUser->role === 1)
        <div class="flex flex-col items-center gap-3" data-controller="upload-preview">
            <img class="size-20 rounded-2xl object-cover ring-4 ring-orange-100 dark:ring-orange-950" src="/account/logo" width="80" height="80" data-upload-preview-target="image" alt="Account logo">
            <div class="flex flex-wrap justify-center gap-2"><form action="/account" method="post" enctype="multipart/form-data" data-controller="form">@csrf @method('PATCH')<label class="inline-flex min-h-10 cursor-pointer items-center rounded-full border border-stone-300 px-4 py-2 text-sm font-semibold dark:border-stone-600"><input class="sr-only" type="file" name="account[logo]" accept="image/*" data-action="upload-preview#previewImage change->form#submit">Upload logo</label></form>
            @if(\App\Models\Attachment::where('record_type','Account')->where('record_id',$account->id)->where('name','logo')->exists())<form action="/account/logo" method="post">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger">Delete logo</x-ui.button></form>@endif</div>
        </div>
        <form action="/account" method="post" class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end" data-controller="form">@csrf @method('PATCH')<x-ui.field label="Account name"><input name="account[name]" value="{{ $account->name }}" required autofocus data-action="keydown.enter->form#submit"></x-ui.field><x-ui.button type="submit" variant="primary">Save changes</x-ui.button></form>
        @php($restricted = (bool) (json_decode($account->settings ?? '{}', true)['restrict_room_creation_to_administrators'] ?? false))
        <form action="/account" method="post" data-controller="form">@csrf @method('PUT')<input type="hidden" name="account[settings][restrict_room_creation_to_administrators]" value="{{ $restricted ? 'false' : 'true' }}"><label class="flex cursor-pointer items-center gap-3 rounded-2xl bg-stone-100 p-4 font-semibold dark:bg-stone-800"><input type="checkbox" class="size-5 accent-orange-500" @checked($restricted) data-action="change->form#submit"><span>Only administrators can create rooms</span></label></form>
    @endif

    <section class="grid gap-4 rounded-2xl bg-orange-50 p-5 dark:bg-orange-950/40" data-testid="settings-invitations">
        <div><h2 class="text-xl font-bold">Invite people</h2><p class="break-all text-sm text-stone-600 dark:text-stone-300">{{ url('/join/'.$account->join_code) }}</p></div>
        <div class="flex flex-wrap gap-2">
            <span x-data="clipboard(@js(url('/join/'.$account->join_code)))"><x-ui.button @click="copy()" x-bind:class="{ 'bg-emerald-600 text-white': copied }"><span x-text="copied ? 'Copied' : 'Copy invitation link'">Copy invitation link</span></x-ui.button></span>
            <x-ui.button href="/qr_code/{{ rtrim(strtr(base64_encode(url('/join/'.$account->join_code)), '+/', '-_'), '=') }}">QR code</x-ui.button>
            @if($currentUser->role === 1)<form action="/account/join_code" method="post">@csrf<x-ui.button type="submit" variant="danger" data-turbo-confirm="Are you sure you want to generate a new invite code?">Reset invite link</x-ui.button></form>@endif
        </div>
    </section>

    <section class="grid gap-3">
        <h2 class="text-xl font-bold">People</h2>
        @foreach($users as $u)
            <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-stone-200 p-3 dark:border-stone-700 {{ $u->status === 2 ? 'opacity-50' : '' }}">
                <img class="size-10 rounded-full object-cover" src="{{ $u->avatarUrl() }}" width="40" height="40" loading="lazy" alt=""><strong class="min-w-0 flex-1 truncate">{{ $u->name }}</strong>
                @if($currentUser->role === 1 && $u->status === 0)
                    <form action="/account/users/{{ $u->id }}" method="post" data-controller="form">@csrf @method('PATCH')<input type="hidden" name="user[role]" value="member"><label class="flex cursor-pointer items-center gap-2 text-sm"><input class="size-4 accent-orange-500" type="checkbox" name="user[role]" value="administrator" data-action="form#submit" @checked($u->role === 1) @disabled($u->id === $currentUser->id)><span>Admin</span></label></form>
                    @if($u->id !== $currentUser->id)<form action="/account/users/{{ $u->id }}" method="post">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger" data-turbo-confirm="Are you sure you want to permanently remove this person from the account? This can’t be undone.">Delete</x-ui.button></form>@endif
                @endif
                @if($u->id === $currentUser->id)<x-ui.button href="/users/me/profile" variant="ghost">My settings</x-ui.button>@endif
            </div>
        @endforeach
    </section>
</x-ui.panel>
</div>
@endsection
