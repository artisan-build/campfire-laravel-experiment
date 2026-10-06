@php($unreadCount = $directs->whereNotNull('unread_at')->count() + $shared->whereNotNull('unread_at')->count())
<div id="user_sidebar" class="flex h-dvh flex-col gap-6 overflow-y-auto p-5" data-testid="sidebar-rooms">
    <span hidden wire:key="sidebar-badge-{{ $unreadCount }}" x-data x-init="if ('setAppBadge' in navigator && {{ $unreadCount }} > 0) navigator.setAppBadge({{ $unreadCount }}); else if ('clearAppBadge' in navigator) navigator.clearAppBadge()"></span>
    <section class="grid gap-2" data-testid="sidebar-direct-rooms">
        <div class="flex items-center justify-between gap-3"><h2 class="text-xs font-bold uppercase tracking-[0.18em] text-stone-500">Pings</h2><a href="/rooms/directs/new" class="text-sm font-semibold text-orange-700 hover:underline dark:text-orange-400">New</a></div>
        <div id="direct_rooms" class="grid gap-1">
            @foreach($directs as $membership)<a id="list_room_{{ $membership->room_id }}" class="rounded-xl px-3 py-2 text-sm font-medium hover:bg-white dark:hover:bg-stone-800 {{ $membership->unread_at ? 'unread' : '' }}" href="/rooms/{{ $membership->room_id }}" data-room-id="{{ $membership->room_id }}">{{ $membership->room->displayName($currentUser) }}</a>@endforeach
        </div>
    </section>
    <section class="grid gap-2" data-testid="sidebar-shared-rooms">
        <div class="flex items-center justify-between gap-3"><h2 class="text-xs font-bold uppercase tracking-[0.18em] text-stone-500">Rooms</h2><a href="/rooms/opens/new" class="text-sm font-semibold text-orange-700 hover:underline dark:text-orange-400">New</a></div>
        <div id="shared_rooms" class="grid gap-1">
            @foreach($shared as $membership)<a id="list_room_{{ $membership->room_id }}" class="rounded-xl px-3 py-2 text-sm font-medium hover:bg-white dark:hover:bg-stone-800 {{ $membership->unread_at ? 'unread' : '' }}" href="/rooms/{{ $membership->room_id }}" data-room-id="{{ $membership->room_id }}">{{ $membership->room->name }}</a>@endforeach
        </div>
    </section>
    <nav class="mt-auto grid gap-1 border-t border-stone-200 pt-4 dark:border-stone-700" aria-label="Settings"><a class="rounded-xl px-3 py-2 text-sm font-medium hover:bg-white dark:hover:bg-stone-800" href="/users/me/profile">My settings</a><a class="rounded-xl px-3 py-2 text-sm font-medium hover:bg-white dark:hover:bg-stone-800" href="/account/edit">Account settings</a></nav>
</div>
